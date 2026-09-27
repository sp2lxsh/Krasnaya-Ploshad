<?php
/**
 * Plugin Name: RS — товары удаляются вместе с салоном
 * Description: При перемещении салона в корзину его товары тоже уходят в корзину, при восстановлении салона — возвращаются, при окончательном удалении салона — удаляются навсегда. Большие салоны обрабатываются фоном через Action Scheduler (входит в WooCommerce).
 * Version: 1.1.0
 *
 * Тип записи «Салоны» и поле ACF, связывающее товар с салоном, определяются
 * автоматически. Что именно найдено — видно в уведомлении над списком салонов,
 * а в колонке «Товаров» — сколько товаров у каждого салона. Пока поле не найдено,
 * плагин ничего не удаляет.
 */

defined( 'ABSPATH' ) || exit;

// Сколько товаров обрабатывать за один фоновый шаг.
if ( ! defined( 'RS_SALON_BATCH' ) ) {
	define( 'RS_SALON_BATCH', 50 );
}

// Метка на товаре: «в корзину его отправил салон N» — чтобы при восстановлении
// салона не вернуть товары, которые удалили вручную раньше.
const RS_SALON_TRASHED_BY = '_rs_trashed_by_salon';
const RS_SALON_ACTION     = 'rs_salon_products_batch';
const RS_SALON_GROUP      = 'rs-salon-products';

/**
 * Slug типа записи «Салоны». Можно задать вручную константой RS_SALON_POST_TYPE
 * в wp-config.php, иначе ищется тип записи с названием «Салоны».
 */
function rs_salon_post_type() {
	static $slug = null;

	if ( null !== $slug ) {
		return $slug;
	}
	if ( defined( 'RS_SALON_POST_TYPE' ) ) {
		return $slug = RS_SALON_POST_TYPE;
	}
	if ( ! did_action( 'init' ) ) {
		return '';
	}

	$slug = '';
	foreach ( get_post_types( array(), 'objects' ) as $type ) {
		if ( in_array( mb_strtolower( $type->label ), array( 'салоны', 'салон' ), true ) ) {
			$slug = $type->name;
			break;
		}
	}

	return $slug;
}

/**
 * Имена полей ACF, связывающих товар с салоном. Можно задать вручную константой
 * RS_SALON_META_KEY, иначе берутся поля типа «Объект записи» / «Связь» у товара,
 * которые ссылаются на тип «Салоны».
 *
 * @return string[]
 */
function rs_salon_meta_keys() {
	static $keys = null;

	if ( null !== $keys ) {
		return $keys;
	}
	if ( defined( 'RS_SALON_META_KEY' ) ) {
		return $keys = array( RS_SALON_META_KEY );
	}

	$keys = array();
	$type = rs_salon_post_type();
	if ( ! $type || ! function_exists( 'acf_get_field_groups' ) ) {
		return $keys;
	}

	foreach ( acf_get_field_groups( array( 'post_type' => 'product' ) ) as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $field ) {
			if ( in_array( $field['type'], array( 'post_object', 'relationship' ), true )
				&& in_array( $type, (array) $field['post_type'], true ) ) {
				$keys[] = $field['name'];
			}
		}
	}

	return $keys = array_values( array_unique( $keys ) );
}

function rs_salon_is_salon( $post_id ) {
	$type = rs_salon_post_type();
	return $type && get_post_type( $post_id ) === $type;
}

/**
 * ID товаров, привязанных к салону.
 *
 * @param int   $salon_id ID салона.
 * @param array $statuses Статусы товаров, среди которых искать.
 * @return int[]
 */
function rs_salon_linked_product_ids( $salon_id, array $statuses ) {
	$salon_id = (int) $salon_id;
	$ids      = array();

	$meta_query = array( 'relation' => 'OR' );
	foreach ( rs_salon_meta_keys() as $key ) {
		// «Объект записи», одиночный: значение = "123".
		$meta_query[] = array(
			'key'   => $key,
			'value' => $salon_id,
		);
		// «Связь» / множественный объект: сериализованный массив с "123".
		$meta_query[] = array(
			'key'     => $key,
			'value'   => '"' . $salon_id . '"',
			'compare' => 'LIKE',
		);
	}

	if ( count( $meta_query ) > 1 ) {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => $statuses,
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => $meta_query,
			)
		);
	}

	/**
	 * Позволяет подменить поиск, если связь салон → товары устроена иначе
	 * (например, список товаров хранится в поле самого салона).
	 */
	$ids = apply_filters( 'rs_salon_product_ids', $ids, $salon_id, $statuses );

	return array_map( 'intval', (array) $ids );
}

/**
 * Привязан ли товар ещё к какому-нибудь живому (не удалённому) салону.
 * Такие товары не трогаем.
 */
function rs_salon_product_has_other_salon( $product_id, $salon_id ) {
	$linked = array();
	foreach ( rs_salon_meta_keys() as $key ) {
		$value  = maybe_unserialize( get_post_meta( $product_id, $key, true ) );
		$linked = array_merge( $linked, array_map( 'intval', (array) $value ) );
	}
	$others = array_diff( $linked, array( 0, (int) $salon_id ) );

	foreach ( $others as $other_id ) {
		$status = get_post_status( $other_id );
		if ( $status && 'trash' !== $status && rs_salon_is_salon( $other_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Запускает операцию над товарами: сразу, если товаров немного, иначе фоном.
 *
 * @param string $op       trash | untrash | delete.
 * @param int    $salon_id ID салона.
 * @param int[]  $ids      ID товаров.
 */
function rs_salon_dispatch( $op, $salon_id, array $ids ) {
	if ( ! $ids ) {
		return;
	}

	if ( count( $ids ) <= RS_SALON_BATCH || ! function_exists( 'as_enqueue_async_action' ) ) {
		rs_salon_process( $op, $salon_id, $ids );
		return;
	}

	foreach ( array_chunk( $ids, RS_SALON_BATCH ) as $chunk ) {
		as_enqueue_async_action(
			RS_SALON_ACTION,
			array(
				'op'    => $op,
				'salon' => (int) $salon_id,
				'ids'   => $chunk,
			),
			RS_SALON_GROUP
		);
	}

	rs_salon_notice(
		sprintf( 'Товаров салона: %d. Они обрабатываются в фоне (WooCommerce → Статус → Запланированные действия, группа %s).', count( $ids ), RS_SALON_GROUP )
	);
}

/**
 * Обрабатывает пачку товаров.
 */
function rs_salon_process( $op, $salon_id, array $ids ) {
	$salon_id = (int) $salon_id;
	$done     = 0;

	if ( 'untrash' === $op ) {
		// С WP 5.6 восстановленные записи по умолчанию становятся черновиками —
		// товарам возвращаем их прежний статус.
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
	}

	foreach ( $ids as $product_id ) {
		$product_id = (int) $product_id;

		if ( 'product' !== get_post_type( $product_id ) ) {
			continue;
		}

		switch ( $op ) {
			case 'trash':
				if ( 'trash' === get_post_status( $product_id ) || rs_salon_product_has_other_salon( $product_id, $salon_id ) ) {
					break;
				}
				update_post_meta( $product_id, RS_SALON_TRASHED_BY, $salon_id );
				if ( wp_trash_post( $product_id ) ) {
					$done++;
				}
				break;

			case 'untrash':
				if ( (int) get_post_meta( $product_id, RS_SALON_TRASHED_BY, true ) !== $salon_id ) {
					break;
				}
				if ( wp_untrash_post( $product_id ) ) {
					delete_post_meta( $product_id, RS_SALON_TRASHED_BY );
					$done++;
				}
				break;

			case 'delete':
				$trashed_by_us = (int) get_post_meta( $product_id, RS_SALON_TRASHED_BY, true ) === $salon_id;
				if ( ! $trashed_by_us && rs_salon_product_has_other_salon( $product_id, $salon_id ) ) {
					break;
				}
				// Через WooCommerce, чтобы удалились и вариации. Картинки
				// в медиатеке не удаляются.
				$product = wc_get_product( $product_id );
				if ( $product && $product->delete( true ) ) {
					$done++;
				}
				break;
		}
	}

	if ( 'untrash' === $op ) {
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
	}

	return $done;
}

add_action(
	RS_SALON_ACTION,
	function ( $op, $salon, $ids ) {
		rs_salon_process( $op, $salon, (array) $ids );
	},
	10,
	3
);

// Салон → в корзину: его товары тоже в корзину.
add_action(
	'trashed_post',
	function ( $post_id ) {
		if ( ! rs_salon_is_salon( $post_id ) ) {
			return;
		}
		$ids = rs_salon_linked_product_ids( $post_id, array( 'publish', 'draft', 'pending', 'private', 'future' ) );
		rs_salon_dispatch( 'trash', $post_id, $ids );
	}
);

// Салон восстановлен из корзины: возвращаем только те товары, которые убрали вместе с ним.
add_action(
	'untrashed_post',
	function ( $post_id ) {
		if ( ! rs_salon_is_salon( $post_id ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'trash',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => RS_SALON_TRASHED_BY,
				'meta_value'       => (int) $post_id,
			)
		);
		rs_salon_dispatch( 'untrash', $post_id, array_map( 'intval', $ids ) );
	}
);

// Салон удалён навсегда (очистка корзины): товары тоже удаляются навсегда.
add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( ! rs_salon_is_salon( $post_id ) ) {
			return;
		}
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		$ids      = rs_salon_linked_product_ids( $post_id, $statuses );
		$ids      = array_merge(
			$ids,
			get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => $statuses,
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => RS_SALON_TRASHED_BY,
					'meta_value'       => (int) $post_id,
				)
			)
		);
		rs_salon_dispatch( 'delete', $post_id, array_values( array_unique( array_map( 'intval', $ids ) ) ) );
	}
);

/*
 * Колонка «Товаров» в списке салонов и уведомление о найденном поле — чтобы до
 * первого удаления убедиться, что связь салон → товары определена верно.
 */
add_action(
	'admin_init',
	function () {
		$type = rs_salon_post_type();
		if ( ! $type ) {
			return;
		}

		add_filter(
			'manage_' . $type . '_posts_columns',
			function ( $columns ) {
				$columns['rs_products'] = 'Товаров';
				return $columns;
			}
		);

		add_action(
			'manage_' . $type . '_posts_custom_column',
			function ( $column, $post_id ) {
				if ( 'rs_products' === $column ) {
					echo (int) count( rs_salon_linked_product_ids( $post_id, array( 'publish', 'draft', 'pending', 'private', 'future' ) ) );
				}
			},
			10,
			2
		);
	}
);

add_action(
	'admin_notices',
	function () {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || $screen->post_type !== rs_salon_post_type() ) {
			return;
		}

		$keys = rs_salon_meta_keys();
		if ( $keys ) {
			printf(
				'<div class="notice notice-info"><p>Удаление товаров вместе с салоном включено. Тип записи: <code>%s</code>, поле товара: <code>%s</code>.</p></div>',
				esc_html( rs_salon_post_type() ),
				esc_html( implode( ', ', $keys ) )
			);
		} else {
			echo '<div class="notice notice-warning"><p>Не найдено поле ACF, связывающее товар с салоном — товары вместе с салоном удаляться не будут. Задайте константу RS_SALON_META_KEY в wp-config.php или в начале файла этого плагина.</p></div>';
		}
	}
);

/*
 * Уведомление в админке о фоновой обработке (показывается после редиректа).
 */
function rs_salon_notice( $message ) {
	if ( is_admin() && get_current_user_id() ) {
		set_transient( 'rs_salon_notice_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	}
}

add_action(
	'admin_notices',
	function () {
		$key     = 'rs_salon_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}
);
