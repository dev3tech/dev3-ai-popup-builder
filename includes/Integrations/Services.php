<?php
/**
 * Email marketing integrations: Mailchimp, Brevo and a signed webhook.
 * Keys come from Secrets (encrypted, never sent to the browser).
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Integrations;

use Dev3\AiPopupBuilder\Data\Leads;
use Dev3\AiPopupBuilder\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Services.
 */
class Services {

	const SERVICES = array( 'mailchimp', 'brevo', 'webhook' );

	/**
	 * Pushes a lead to every enabled integration of its campaign and stores the outcome.
	 *
	 * @param array $lead     Lead.
	 * @param array $campaign Campaign.
	 * @return array service => { ok, message, at }
	 */
	public static function sync_lead( array $lead, array $campaign ) {
		$config = $campaign['config']['integrations'];
		$out    = array();
		foreach ( self::SERVICES as $service ) {
			if ( empty( $config[ $service ]['enabled'] ) ) {
				continue;
			}
			// Marketing lists need consent when the campaign asks for it.
			if ( 'webhook' !== $service && ! empty( $campaign['config']['form']['consent']['enabled'] ) && ! $lead['consent'] ) {
				$out[ $service ] = array(
					'ok'      => true,
					'message' => 'Skipped: no marketing consent.',
					'at'      => current_time( 'mysql' ),
				);
				continue;
			}
			$result          = self::push( $service, $config[ $service ], $lead, $campaign );
			$out[ $service ] = array(
				'ok'      => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : 'Sent.',
				'at'      => current_time( 'mysql' ),
			);
		}
		Leads::set_sync( $lead['id'], $out );
		return $out;
	}

	/**
	 * One service.
	 *
	 * @param string $service  Service.
	 * @param array  $config   Campaign config for the service.
	 * @param array  $lead     Lead.
	 * @param array  $campaign Campaign.
	 * @return true|\WP_Error
	 */
	public static function push( $service, array $config, array $lead, array $campaign ) {
		switch ( $service ) {
			case 'mailchimp':
				return self::mailchimp( $config, $lead );
			case 'brevo':
				return self::brevo( $config, $lead );
			case 'webhook':
				return self::webhook( $config['url'], self::payload( $lead, $campaign ) );
		}
		return new \WP_Error( 'dev3pu_unknown_service', 'Unknown integration.' );
	}

	/**
	 * Splits a full name.
	 *
	 * @param string $name Name.
	 * @return array [ first, last ]
	 */
	public static function split_name( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ), 2 );
		return array( isset( $parts[0] ) ? $parts[0] : '', isset( $parts[1] ) ? $parts[1] : '' );
	}

	/**
	 * Webhook payload.
	 *
	 * @param array $lead     Lead.
	 * @param array $campaign Campaign.
	 * @return array
	 */
	public static function payload( array $lead, array $campaign ) {
		return array(
			'event'    => 'lead.captured',
			'lead'     => array(
				'id'         => $lead['id'],
				'email'      => $lead['email'],
				'name'       => $lead['name'],
				'phone'      => $lead['phone'],
				'fields'     => (object) $lead['fields'],
				'consent'    => $lead['consent'],
				'page_url'   => $lead['page_url'],
				'referrer'   => $lead['referrer'],
				'utm'        => (object) $lead['utm'],
				'device'     => $lead['device'],
				'coupon'     => $lead['coupon'],
				'created_at' => $lead['created_at'],
			),
			'campaign' => array(
				'id'      => $campaign['id'],
				'title'   => $campaign['title'],
				'type'    => $campaign['type'],
				'variant' => $lead['variant'],
			),
			'site'     => home_url( '/' ),
		);
	}

	/**
	 * Interprets an HTTP response.
	 *
	 * @param array|\WP_Error $response Response.
	 * @param string          $service  Service.
	 * @return array|\WP_Error Decoded body.
	 */
	public static function check( $response, $service ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $body ) ? $body : array();
		}
		$detail = '';
		if ( is_array( $body ) ) {
			foreach ( array( 'detail', 'message', 'title', 'error' ) as $key ) {
				if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
					$detail = $body[ $key ];
					break;
				}
			}
		}
		return new \WP_Error( 'dev3pu_' . $service . '_http', trim( sprintf( '%s HTTP %d %s', ucfirst( $service ), $code, $detail ) ), array( 'status' => $code ) );
	}

	/**
	 * Mailchimp data center from the key ("…-us21").
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mailchimp_dc( $key ) {
		$pos = strrpos( (string) $key, '-' );
		$dc  = false === $pos ? '' : substr( $key, $pos + 1 );
		return preg_match( '/^[a-z]{2,4}\d{1,3}$/', $dc ) ? $dc : '';
	}

	/**
	 * Mailchimp request.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /3.0/.
	 * @param array|null $body   Body.
	 * @return array|\WP_Error
	 */
	public static function mailchimp_request( $method, $path, $body = null ) {
		$key = Secrets::get( 'mailchimp_key' );
		$dc  = self::mailchimp_dc( $key );
		if ( '' === $dc ) {
			return new \WP_Error( 'dev3pu_mailchimp_key', __( 'Add a Mailchimp API key (it ends in -usXX) under Integrations.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'dev3:' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return self::check( wp_remote_request( "https://{$dc}.api.mailchimp.com/3.0/" . ltrim( $path, '/' ), $args ), 'mailchimp' );
	}

	/**
	 * Mailchimp subscribe/update + tags.
	 *
	 * @param array $config Config { list_id, tags, double }.
	 * @param array $lead   Lead.
	 * @return true|\WP_Error
	 */
	public static function mailchimp( array $config, array $lead ) {
		if ( '' === $lead['email'] || '' === $config['list_id'] ) {
			return new \WP_Error( 'dev3pu_mailchimp_config', __( 'Mailchimp: choose an audience for this campaign.', 'dev3-ai-popup-builder' ) );
		}
		list( $first, $last ) = self::split_name( $lead['name'] );
		$hash                 = md5( strtolower( $lead['email'] ) );
		$list                 = rawurlencode( $config['list_id'] );
		$result               = self::mailchimp_request(
			'PUT',
			"lists/{$list}/members/{$hash}",
			array(
				'email_address' => $lead['email'],
				'status_if_new' => ! empty( $config['double'] ) ? 'pending' : 'subscribed',
				'merge_fields'  => (object) array_filter(
					array(
						'FNAME' => $first,
						'LNAME' => $last,
						'PHONE' => $lead['phone'],
					)
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$tags = array_filter( array_map( 'trim', explode( ',', (string) $config['tags'] ) ) );
		if ( $tags ) {
			$payload = array();
			foreach ( $tags as $tag ) {
				$payload[] = array(
					'name'   => $tag,
					'status' => 'active',
				);
			}
			$tagged = self::mailchimp_request( 'POST', "lists/{$list}/members/{$hash}/tags", array( 'tags' => $payload ) );
			if ( is_wp_error( $tagged ) ) {
				return $tagged;
			}
		}
		return true;
	}

	/**
	 * Brevo request.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /v3/.
	 * @param array|null $body   Body.
	 * @return array|\WP_Error
	 */
	public static function brevo_request( $method, $path, $body = null ) {
		$key = Secrets::get( 'brevo_key' );
		if ( '' === $key ) {
			return new \WP_Error( 'dev3pu_brevo_key', __( 'Add a Brevo API key under Integrations.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'api-key'      => $key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return self::check( wp_remote_request( 'https://api.brevo.com/v3/' . ltrim( $path, '/' ), $args ), 'brevo' );
	}

	/**
	 * Brevo create/update contact.
	 *
	 * @param array $config Config { list_id }.
	 * @param array $lead   Lead.
	 * @return true|\WP_Error
	 */
	public static function brevo( array $config, array $lead ) {
		if ( '' === $lead['email'] ) {
			return new \WP_Error( 'dev3pu_brevo_config', __( 'Brevo: the lead has no email address.', 'dev3-ai-popup-builder' ) );
		}
		list( $first, $last ) = self::split_name( $lead['name'] );
		$body                 = array(
			'email'         => $lead['email'],
			'attributes'    => (object) array_filter(
				array(
					'FIRSTNAME' => $first,
					'LASTNAME'  => $last,
					'SMS'       => preg_replace( '/[^\d+]/', '', $lead['phone'] ),
				)
			),
			'updateEnabled' => true,
		);
		if ( '' !== $config['list_id'] && ctype_digit( (string) $config['list_id'] ) ) {
			$body['listIds'] = array( (int) $config['list_id'] );
		}
		$result = self::brevo_request( 'POST', 'contacts', $body );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Signed JSON webhook. wp_safe_remote_post refuses internal/private hosts.
	 *
	 * @param string $url     URL.
	 * @param array  $payload Payload.
	 * @return true|\WP_Error
	 */
	public static function webhook( $url, array $payload ) {
		if ( '' === (string) $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'dev3pu_webhook_url', __( 'Add a valid public webhook URL.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) );
		}
		$body    = wp_json_encode( $payload );
		$headers = array(
			'Content-Type'      => 'application/json',
			'X-DEV3PU-Event'    => isset( $payload['event'] ) ? (string) $payload['event'] : 'lead.captured',
			'X-DEV3PU-Delivery' => wp_generate_uuid4(),
		);
		$secret  = Secrets::get( 'webhook_secret' );
		if ( '' !== $secret ) {
			$headers['X-DEV3PU-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => $headers,
				'body'        => $body,
			)
		);
		$result   = self::check( $response, 'webhook' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Tests credentials; returns lists to pick from.
	 *
	 * @param string $service mailchimp|brevo|webhook.
	 * @param string $url     Webhook URL (for webhook tests).
	 * @return array|\WP_Error { ok, message, lists }
	 */
	public static function test( $service, $url = '' ) {
		if ( 'webhook' === $service ) {
			$result = self::webhook(
				$url,
				array(
					'event' => 'test',
					'site'  => home_url( '/' ),
					'at'    => gmdate( 'c' ),
				)
			);
			return is_wp_error( $result ) ? $result : array(
				'ok'      => true,
				'message' => __( 'Test event delivered.', 'dev3-ai-popup-builder' ),
				'lists'   => array(),
			);
		}
		$lists = self::lists( $service );
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		return array(
			'ok'      => true,
			/* translators: %d: number of lists */
			'message' => sprintf( _n( 'Connected. %d list found.', 'Connected. %d lists found.', count( $lists ), 'dev3-ai-popup-builder' ), count( $lists ) ),
			'lists'   => $lists,
		);
	}

	/**
	 * Lists / audiences.
	 *
	 * @param string $service mailchimp|brevo.
	 * @return array|\WP_Error [ { value, label } ]
	 */
	public static function lists( $service ) {
		if ( 'mailchimp' === $service ) {
			$result = self::mailchimp_request( 'GET', 'lists?count=100&fields=lists.id,lists.name' );
		} elseif ( 'brevo' === $service ) {
			$result = self::brevo_request( 'GET', 'contacts/lists?limit=50' );
		} else {
			return new \WP_Error( 'dev3pu_unknown_service', __( 'Unknown integration.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$lists = array();
		foreach ( isset( $result['lists'] ) ? (array) $result['lists'] : array() as $list ) {
			if ( isset( $list['id'], $list['name'] ) ) {
				$lists[] = array(
					'value' => (string) $list['id'],
					'label' => (string) $list['name'],
				);
			}
		}
		return $lists;
	}
}
