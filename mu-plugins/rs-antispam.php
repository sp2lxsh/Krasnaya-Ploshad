<?php
/**
 * Plugin Name: RS — защита комментариев и отзывов от спама
 * Description: Комментарии открыты только там, где нужны (по умолчанию — отзывы к товарам); пингбеки/трекбеки отключены; скрытая ловушка и проверка времени заполнения формы; отзывы со ссылками или без кириллицы уходят в спам; старый спам чистится автоматически.
 * Version: 1.0.0
 *
 * Установка: положить файл в wp-content/mu-plugins/.
 * После установки оставьте тестовый отзыв к товару (не залогинившись) —
 * он должен попасть в «Ожидают проверки», а не в «Спам».
 */

defined( 'ABSPATH' ) || exit;

// Типы записей, где комментарии/отзывы разрешены. Если нужен блог — добавьте 'post'.
if ( ! defined( 'RS_COMMENTS_POST_TYPES' ) ) {
	define( 'RS_COMMENTS_POST_TYPES', 'product' );
}

// false — комментарий без токена формы уходит в «Спам» (можно проверить и восстановить).
// true  — такой запрос сразу отклоняется и в базу не пишется. Включать после того,
//         как убедились, что настоящие отзывы проходят.
if ( ! defined( 'RS_ANTISPAM_STRICT' ) ) {
	define( 'RS_ANTISPAM_STRICT', false );
}

// Минимальное время (сек) между открытием страницы и отправкой формы.
if ( ! defined( 'RS_ANTISPAM_MIN_SECONDS' ) ) {
	define( 'RS_ANTISPAM_MIN_SECONDS', 4 );
}

// Через сколько дней удалять комментарии из «Спама».
if ( ! defined( 'RS_ANTISPAM_PURGE_DAYS' ) ) {
	define( 'RS_ANTISPAM_PURGE_DAYS', 14 );
}

function rs_antispam_allowed_post_types() {
	return array_filter( array_map( 'trim', explode( ',', RS_COMMENTS_POST_TYPES ) ) );
}

/*
 * 1. Комментарии только на разрешённых типах записей, пингбеки — нигде.
 */
add_filter(
	'comments_open',
	function ( $open, $post_id ) {
		return $open && in_array( get_post_type( $post_id ), rs_antispam_allowed_post_types(), true );
	},
	20,
	2
);

add_filter( 'pings_open', '__return_false', 20 );

add_filter(
	'xmlrpc_methods',
	function ( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}
);

add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

/*
 * 2. Скрытые поля формы: ловушка (люди её не видят и не заполняют) и подписанная
 *    метка времени. Боты, которые шлют POST прямо в wp-comments-post.php,
 *    не имеют ни того, ни другого. Метка не ограничена сверху по возрасту,
 *    поэтому страницы из кеша WP Fastest Cache продолжают работать.
 */
add_action(
	'comment_form',
	function () {
		$time = time();
		printf(
			'<p style="position:absolute!important;left:-9999px!important;height:1px;overflow:hidden" aria-hidden="true">'
			. '<label>Не заполняйте это поле <input type="text" name="rs_hp" value="" tabindex="-1" autocomplete="off"></label></p>'
			. '<input type="hidden" name="rs_ts" value="%1$d"><input type="hidden" name="rs_tk" value="%2$s">',
			(int) $time,
			esc_attr( rs_antispam_token( $time ) )
		);
	}
);

function rs_antispam_token( $time ) {
	return wp_hash( 'rs_comment_form|' . (int) $time, 'nonce' );
}

/**
 * Возвращает причину, по которой комментарий — спам, или пустую строку.
 * 'reject' — однозначный бот, в базу не пишем.
 */
function rs_antispam_check( array $data ) {
	// Не трогаем записи из админки и от пользователей, которые могут модерировать.
	if ( ! empty( $data['user_id'] ) && user_can( (int) $data['user_id'], 'moderate_comments' ) ) {
		return '';
	}

	$type = isset( $data['comment_type'] ) ? $data['comment_type'] : '';
	if ( in_array( $type, array( 'pingback', 'trackback' ), true ) ) {
		return 'reject';
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- анонимная форма, nonce не используется.
	if ( ! empty( $_POST['rs_hp'] ) ) {
		return 'reject';
	}

	$ts = isset( $_POST['rs_ts'] ) ? (int) $_POST['rs_ts'] : 0;
	$tk = isset( $_POST['rs_tk'] ) ? (string) wp_unslash( $_POST['rs_tk'] ) : '';
	// phpcs:enable

	if ( ! $ts || ! hash_equals( rs_antispam_token( $ts ), $tk ) ) {
		return RS_ANTISPAM_STRICT ? 'reject' : 'нет токена формы';
	}
	if ( time() - $ts < RS_ANTISPAM_MIN_SECONDS ) {
		return 'reject';
	}

	$text = isset( $data['comment_content'] ) ? $data['comment_content'] : '';

	if ( preg_match( '~https?://|www\.|<a\s|\[url~i', $text . ' ' . ( isset( $data['comment_author_url'] ) ? $data['comment_author_url'] : '' ) ) ) {
		return 'ссылка';
	}

	// Сайт русскоязычный: отзыв совсем без кириллицы — почти всегда спам.
	if ( ! preg_match( '/\p{Cyrillic}/u', $text ) ) {
		return 'нет кириллицы';
	}

	return '';
}

add_filter(
	'pre_comment_approved',
	function ( $approved, $data ) {
		if ( is_wp_error( $approved ) || 'spam' === $approved || 'trash' === $approved ) {
			return $approved;
		}

		$reason = rs_antispam_check( (array) $data );

		if ( 'reject' === $reason ) {
			return new WP_Error( 'rs_spam', 'Комментарий отклонён.', 403 );
		}

		return '' === $reason ? $approved : 'spam';
	},
	20,
	2
);

/*
 * 3. Ежедневно удаляем старый спам пачками, чтобы таблица комментариев не разрасталась.
 */
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'rs_antispam_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rs_antispam_purge' );
		}
	}
);

add_action(
	'rs_antispam_purge',
	function () {
		$ids = get_comments(
			array(
				'status'     => 'spam',
				'fields'     => 'ids',
				'number'     => 1000,
				'date_query' => array(
					array( 'before' => RS_ANTISPAM_PURGE_DAYS . ' days ago' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_comment( $id, true );
		}
	}
);
