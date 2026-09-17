<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP client for api.hh.ru.
 */
class HH_Vacancies_Api_Client {

	const API_BASE = 'https://api.hh.ru';

	/**
	 * @var HH_Vacancies_Settings
	 */
	private $settings;

	/**
	 * @var HH_Vacancies_OAuth
	 */
	private $oauth;

	/**
	 * @param HH_Vacancies_Settings $settings Settings.
	 * @param HH_Vacancies_OAuth    $oauth OAuth.
	 */
	public function __construct( HH_Vacancies_Settings $settings, HH_Vacancies_OAuth $oauth ) {
		$this->settings = $settings;
		$this->oauth    = $oauth;
	}

	/**
	 * @param string $path Path starting with /.
	 * @param array  $query Query args.
	 * @param bool   $retried Whether a token refresh retry already happened.
	 * @return array|\WP_Error Decoded JSON array.
	 */
	public function get( $path, array $query = array(), $retried = false ) {
		$token = $this->oauth->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$user_agent = $this->settings->get( 'user_agent' );
		if ( '' === $user_agent ) {
			return new WP_Error( 'hh_vacancies_no_ua', __( 'Укажите User-Agent в настройках плагина.', 'hh-vacancies' ) );
		}

		$url = self::API_BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'User-Agent'    => $user_agent,
					'HH-User-Agent' => $user_agent,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 401 === $code || 403 === $code ) {
			$oauth_error = '';
			if ( is_array( $data ) && isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
				foreach ( $data['errors'] as $err ) {
					if ( isset( $err['type'] ) && 'oauth' === $err['type'] && ! empty( $err['value'] ) ) {
						$oauth_error = (string) $err['value'];
						break;
					}
				}
			}

			if ( 'token-revoked' === $oauth_error ) {
				$this->oauth->clear_tokens();
			} elseif ( ! $retried && ( 'token-expired' === $oauth_error || ( '' === $oauth_error && 401 === $code ) ) ) {
				$refreshed = $this->oauth->refresh_tokens();
				if ( ! is_wp_error( $refreshed ) ) {
					return $this->get( $path, $query, true );
				}
			}

			return new WP_Error(
				'hh_vacancies_auth',
				__( 'Ошибка авторизации API. Требуется повторное подключение.', 'hh-vacancies' )
			);
		}

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error(
				'hh_vacancies_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'Ошибка запроса к API (HTTP %d).', 'hh-vacancies' ),
					$code
				)
			);
		}

		return $data;
	}
}
