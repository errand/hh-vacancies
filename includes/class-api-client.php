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
	 * @param bool   $retried Whether a token recovery retry already happened.
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

		$args = array(
			'timeout'    => 30,
			'user-agent' => $user_agent,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $token,
				'User-Agent'    => $user_agent,
				'HH-User-Agent' => $user_agent,
				'Accept'        => 'application/json',
			),
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 401 === $code || 403 === $code ) {
			return $this->handle_auth_error( $code, $data, $path, $query, $retried );
		}

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error(
				'hh_vacancies_http',
				$this->format_http_error( $code, $data )
			);
		}

		return $data;
	}

	/**
	 * @param int         $code HTTP status.
	 * @param array|mixed $data Decoded body.
	 * @param string      $path API path.
	 * @param array       $query Query args.
	 * @param bool        $retried Already retried after recovery.
	 * @return array|\WP_Error
	 */
	private function handle_auth_error( $code, $data, $path, $query, $retried ) {
		$parsed = $this->parse_api_errors( $data );
		$value  = $this->normalize_oauth_value( $parsed['oauth_value'] );

		$recoverable = $this->is_token_expired( $value )
			|| in_array( $value, array( 'token_revoked', 'bad_authorization' ), true )
			|| ( '' === $value && ( 401 === $code || 403 === $code ) );

		if ( $recoverable && ! $retried ) {
			$recovered = $this->oauth->recover_token();
			if ( ! is_wp_error( $recovered ) ) {
				return $this->get( $path, $query, true );
			}

			return new WP_Error(
				'hh_vacancies_auth',
				sprintf(
					/* translators: 1: oauth value, 2: recovery error */
					__( 'Не удалось восстановить токен (%1$s): %2$s. Получите токен приложения заново.', 'hh-vacancies' ),
					$value ? $value : 'bad_authorization',
					$recovered->get_error_message()
				)
			);
		}

		if ( in_array( $value, array( 'token_revoked', 'bad_authorization', 'application_not_found' ), true ) ) {
			$this->oauth->clear_tokens();
			return new WP_Error(
				'hh_vacancies_auth',
				sprintf(
					/* translators: %s: oauth error value */
					__( 'Ошибка авторизации API (%s). Требуется повторное подключение.', 'hh-vacancies' ),
					$value
				)
			);
		}

		$message = $this->format_http_error( $code, $data );
		if ( $parsed['oauth_value'] || $parsed['first_type'] ) {
			$detail  = trim( $parsed['first_type'] . ( $parsed['oauth_value'] || $parsed['first_value'] ? ' / ' . ( $parsed['oauth_value'] ? $parsed['oauth_value'] : $parsed['first_value'] ) : '' ) );
			$message = sprintf(
				/* translators: 1: HTTP status, 2: error detail */
				__( 'Ошибка API (HTTP %1$d): %2$s', 'hh-vacancies' ),
				$code,
				$detail
			);
		}

		return new WP_Error( 'hh_vacancies_http', $message );
	}

	/**
	 * @param array|mixed $data Response body.
	 * @return array{oauth_value:string,first_type:string,first_value:string}
	 */
	private function parse_api_errors( $data ) {
		$result = array(
			'oauth_value' => '',
			'first_type'  => '',
			'first_value' => '',
		);

		if ( ! is_array( $data ) ) {
			return $result;
		}

		// Top-level oauth_error (documented in DELETE /token and other 403 samples).
		if ( ! empty( $data['oauth_error'] ) ) {
			$result['oauth_value'] = (string) $data['oauth_error'];
		}

		if ( empty( $data['errors'] ) || ! is_array( $data['errors'] ) ) {
			return $result;
		}

		foreach ( $data['errors'] as $err ) {
			if ( ! is_array( $err ) ) {
				continue;
			}

			$type  = isset( $err['type'] ) ? (string) $err['type'] : '';
			$value = isset( $err['value'] ) ? (string) $err['value'] : '';

			if ( '' === $result['first_type'] && $type ) {
				$result['first_type']  = $type;
				$result['first_value'] = $value;
			}

			if ( 'oauth' === $type && $value && '' === $result['oauth_value'] ) {
				$result['oauth_value'] = $value;
			}
		}

		return $result;
	}

	/**
	 * Normalize hyphen/underscore variants from OpenAPI vs live API.
	 *
	 * @param string $value Raw oauth value.
	 * @return string
	 */
	private function normalize_oauth_value( $value ) {
		$value = strtolower( str_replace( '-', '_', (string) $value ) );
		return $value;
	}

	/**
	 * @param string $value Normalized oauth value.
	 * @return bool
	 */
	private function is_token_expired( $value ) {
		return 'token_expired' === $value;
	}

	/**
	 * @param int         $code HTTP status.
	 * @param array|mixed $data Body.
	 * @return string
	 */
	private function format_http_error( $code, $data ) {
		$parsed = $this->parse_api_errors( $data );
		if ( $parsed['first_type'] || $parsed['oauth_value'] ) {
			$detail = $parsed['first_type'] ? $parsed['first_type'] : 'oauth';
			$val    = $parsed['oauth_value'] ? $parsed['oauth_value'] : $parsed['first_value'];
			if ( $val ) {
				$detail .= ' / ' . $val;
			}
			return sprintf(
				/* translators: 1: HTTP status, 2: error detail */
				__( 'Ошибка API (HTTP %1$d): %2$s', 'hh-vacancies' ),
				$code,
				$detail
			);
		}

		if ( is_array( $data ) && ! empty( $data['description'] ) ) {
			return sprintf(
				/* translators: 1: HTTP status, 2: description */
				__( 'Ошибка API (HTTP %1$d): %2$s', 'hh-vacancies' ),
				$code,
				(string) $data['description']
			);
		}

		return sprintf(
			/* translators: %d: HTTP status */
			__( 'Ошибка запроса к API (HTTP %d).', 'hh-vacancies' ),
			$code
		);
	}
}
