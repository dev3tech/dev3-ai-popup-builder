<?php
/**
 * Server-side page targeting. It only depends on the requested URL / queried object,
 * so the result is safe to cache in full-page caches.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Page rules.
 */
class Page_Rules {

	/**
	 * Context of the current request.
	 *
	 * @return array { front, post_type, id, terms[], path, search, not_found, woo[] }
	 */
	public static function context() {
		$ctx = array(
			'front'     => is_front_page(),
			'post_type' => '',
			'id'        => 0,
			'terms'     => array(),
			'path'      => self::current_path(),
			'search'    => is_search(),
			'not_found' => is_404(),
			'woo'       => array(),
		);
		if ( is_singular() ) {
			$post             = get_queried_object();
			$ctx['id']        = $post ? (int) $post->ID : 0;
			$ctx['post_type'] = $post ? (string) $post->post_type : '';
			if ( $post ) {
				foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
					$terms = get_the_terms( $post, $taxonomy );
					foreach ( is_array( $terms ) ? $terms : array() as $term ) {
						$ctx['terms'][] = $taxonomy . ':' . $term->term_id;
					}
				}
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->taxonomy ) ) {
				$ctx['terms'][] = $term->taxonomy . ':' . $term->term_id;
			}
		} elseif ( is_post_type_archive() ) {
			$ctx['post_type'] = (string) get_query_var( 'post_type' );
		}
		if ( function_exists( 'is_shop' ) ) {
			foreach ( array(
				'shop'     => 'is_shop',
				'product'  => 'is_product',
				'cart'     => 'is_cart',
				'checkout' => 'is_checkout',
				'account'  => 'is_account_page',
			) as $key => $fn ) {
				if ( function_exists( $fn ) && call_user_func( $fn ) ) {
					$ctx['woo'][] = $key;
				}
			}
		}
		return $ctx;
	}

	/**
	 * Request path + query, e.g. "/shop/?orderby=price".
	 *
	 * @return string
	 */
	public static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return '' === $uri ? '/' : $uri;
	}

	/**
	 * Whether page rules match a context.
	 *
	 * @param array $pages { mode, rules[] }.
	 * @param array $ctx   Context.
	 * @return bool
	 */
	public static function matches( array $pages, array $ctx ) {
		if ( 'all' === $pages['mode'] || empty( $pages['rules'] ) ) {
			return true;
		}
		$any = false;
		foreach ( $pages['rules'] as $rule ) {
			if ( self::rule( $rule, $ctx ) ) {
				$any = true;
				break;
			}
		}
		return 'include' === $pages['mode'] ? $any : ! $any;
	}

	/**
	 * One rule.
	 *
	 * @param array $rule { type, value }.
	 * @param array $ctx  Context.
	 * @return bool
	 */
	public static function rule( array $rule, array $ctx ) {
		$value = (string) $rule['value'];
		switch ( $rule['type'] ) {
			case 'front_page':
				return ! empty( $ctx['front'] );
			case 'post_type':
				return '' !== $value && $ctx['post_type'] === $value;
			case 'ids':
				return $ctx['id'] > 0 && in_array( (int) $ctx['id'], array_map( 'intval', explode( ',', $value ) ), true );
			case 'term':
				return in_array( $value, (array) $ctx['terms'], true );
			case 'url':
				return self::url_matches( $value, (string) $ctx['path'] );
			case 'search':
				return ! empty( $ctx['search'] );
			case '404':
				return ! empty( $ctx['not_found'] );
			case 'woo':
				return in_array( $value, (array) $ctx['woo'], true );
		}
		return false;
	}

	/**
	 * URL rule: "contains" text, or a wildcard pattern on the path when it has "*" (e.g. "/shop/*").
	 *
	 * @param string $pattern Pattern.
	 * @param string $path    Path.
	 * @return bool
	 */
	public static function url_matches( $pattern, $path ) {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		if ( false === strpos( $pattern, '*' ) ) {
			return false !== stripos( $path, $pattern );
		}
		$only_path = (string) wp_parse_url( $path, PHP_URL_PATH );
		$regex     = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
		return (bool) preg_match( $regex, $only_path );
	}
}
