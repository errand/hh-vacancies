<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HH.ru auth: application token (client_credentials) + optional user OAuth.
 *
 * For public vacancy search GET /vacancies?employer_id=… the application token is correct:
 * unlimited lifetime, no browser redirect required.
 */
class HH_Vacancies_OAuth {

	const STATE_TRANSIENT = 'hh_vacancies_oauth_state';
	const AUTHORIZE_URL   = 'https://hh.ru/oauth/authorize';
	const TOKEN_URL       = 'https://api.hh.ru/token';

	const AUTH_APPLICATION = 'application';
	const AUTH_USER        = 'user';

	/**
	 * @var HH_Vacancies_Settings
	 */
	private $settings;

	/**
	 * @param HH_Vacancies_Settings $settings Settings.
	 */
	public function __construct( HH_Vacancies_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register admin-post handlers.
	 */
	public function init() {
		add_action( 'admin_post_hh_vacancies_app_token', array( $this, 'handle_app_token' ) );
		add_action( 'admin_post_hh_vacancies_oauth_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_hh_vacancies_oauth_callback', array( $this, 'handle_callback' ) );
		add_action( 'admin_post_hh_vacancies_oauth_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Fixed redirect URI for HH application settings (user OAuth only).
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=hh_vacancies_oauth_callback' );
	}

	/**
	 * @return bool
	 */
	public function is_connected() {
		$tokens = $this->get_tokens();
		return ! empty( $tokens['access_token'] );
	}

	/**
	 * @return string application|user|''
	 */
	public function get_auth_mode() {
		$tokens = $this->get_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			return '';
		}
		if ( ! empty( $tokens['auth_mode'] ) ) {
			return (string) $tokens['auth_mode'];
		}
		// Legacy: had refresh_token → user; otherwise treat as application.
		return ! empty( $tokens['refresh_token'] ) ? self::AUTH_USER : self::AUTH_APPLICATION;
	}

	/**
	 * @return array
	 */
	public function get_tokens() {
		$tokens = get_option( HH_VACANCIES_OPTION_TOKENS, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * @param array $tokens Token payload.
	 */
	public function save_tokens( array $tokens ) {
		$current = $this->get_tokens();

		$refresh = '';
		if ( isset( $tokens['refresh_token'] ) && '' !== (string) $tokens['refresh_token'] ) {
			$refresh = (string) $tokens['refresh_token'];
		} elseif ( isset( $tokens['auth_mode'] ) && self::AUTH_APPLICATION === $tokens['auth_mode'] ) {
			$refresh = '';
		} elseif ( ! empty( $current['refresh_token'] ) && ( ! isset( $tokens['auth_mode'] ) || self::AUTH_USER === $tokens['auth_mode'] ) ) {
			$refresh = (string) $current['refresh_token'];
		}

		$auth_mode = isset( $tokens['auth_mode'] ) ? (string) $tokens['auth_mode'] : '';
		if ( '' === $auth_mode ) {
			$auth_mode = $refresh ? self::AUTH_USER : self::AUTH_APPLICATION;
		}

		$payload = array(
			'access_token'  => isset( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '',
			'refresh_token' => $refresh,
			'token_type'    => isset( $tokens['token_type'] ) ? (string) $tokens['token_type'] : 'bearer',
			'expires_at'    => isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0,
			'auth_mode'     => $auth_mode,
		);

		update_option( HH_VACANCIES_OPTION_TOKENS, $payload, false );
	}

	/**
	 * Clear stored tokens and vacancies cache.
	 */
	public function clear_tokens() {
		update_option( HH_VACANCIES_OPTION_TOKENS, array(), false );
		HH_Vacancies_Vacancies::flush_cache( $this->settings->get( 'employer_id' ) );
	}

	/**
	 * Valid access token for API calls.
	 *
	 * Application tokens do not expire; user tokens are refreshed near expiry.
	 *
	 * @return string|\WP_Error
	 */
	public function get_valid_access_token() {
		$tokens = $this->get_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			return new WP_Error( 'hh_vacancies_no_token', __( 'Нет токена. Получите токен приложения в настройках.', 'hh-vacancies' ) );
		}

		$mode = $this->get_auth_mode();

		if ( self::AUTH_APPLICATION === $mode ) {
			return $tokens['access_token'];
		}

		$expires_at = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;
		if ( $expires_at > 0 && ( $expires_at - 60 ) <= time() ) {
			$refreshed = $this->refresh_tokens();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$tokens = $this->get_tokens();
		}

		return $tokens['access_token'];
	}

	/**
	 * Obtain / renew application access_token (client_credentials).
	 *
	 * @return true|\WP_Error
	 */
	public function request_application_token() {
		$client_id     = $this->settings->get( 'client_id' );
		$client_secret = $this->settings->get( 'client_secret' );

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'hh_vacancies_no_creds', __( 'Сохраните Client ID и Client Secret.', 'hh-vacancies' ) );
		}

		$user_agent = $this->settings->get( 'user_agent' );
		if ( '' === $user_agent ) {
			return new WP_Error( 'hh_vacancies_no_ua', __( 'Укажите User-Agent в настройках плагина.', 'hh-vacancies' ) );
		}

		$result = $this->request_token(
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			),
			self::AUTH_APPLICATION
		);

		return $result;
	}

	/**
	 * Admin: fetch application token.
	 */
	public function handle_app_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'hh-vacancies' ) );
		}

		check_admin_referer( 'hh_vacancies_app_token' );

		$result = $this->request_application_token();
		if ( is_wp_error( $result ) ) {
			$this->redirect_settings( 'error', $result->get_error_message() );
		}

		HH_Vacancies_Vacancies::flush_cache( $this->settings->get( 'employer_id' ) );
		$this->redirect_settings( 'success', __( 'Токен приложения получен.', 'hh-vacancies' ) );
	}

	/**
	 * Start user OAuth redirect (optional; for employer cabinet methods).
	 */
	public function handle_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'hh-vacancies' ) );
		}

		check_admin_referer( 'hh_vacancies_oauth_start' );

		$client_id = $this->settings->get( 'client_id' );
		if ( '' === $client_id ) {
			$this->redirect_settings( 'error', __( 'Сначала сохраните Client ID.', 'hh-vacancies' ) );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'response_type'        => 'code',
				'client_id'            => $client_id,
				'state'                => $state,
				'redirect_uri'         => self::get_redirect_uri(),
				'role'                 => 'employer',
				'force_role'           => 'true',
				'skip_choose_account'  => 'true',
			),
			self::AUTHORIZE_URL
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external HH authorize URL.
		exit;
	}

	/**
	 * OAuth callback: exchange code for user tokens.
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'hh-vacancies' ) );
		}

		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->redirect_settings( 'error', sprintf( /* translators: %s: OAuth error code */ __( 'Авторизация отклонена: %s', 'hh-vacancies' ), $error ) );
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$expected = get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );

		if ( ! $code || ! $state || ! $expected || ! hash_equals( (string) $expected, $state ) ) {
			$this->redirect_settings( 'error', __( 'Неверный state или отсутствует код авторизации.', 'hh-vacancies' ) );
		}

		$result = $this->request_token(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $this->settings->get( 'client_id' ),
				'client_secret' => $this->settings->get( 'client_secret' ),
				'redirect_uri'  => self::get_redirect_uri(),
				'code'          => $code,
			),
			self::AUTH_USER
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_settings( 'error', $result->get_error_message() );
		}

		$this->redirect_settings( 'success', __( 'Авторизация работодателя выполнена.', 'hh-vacancies' ) );
	}

	/**
	 * Disconnect: invalidate user token when possible and clear local storage.
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'hh-vacancies' ) );
		}

		check_admin_referer( 'hh_vacancies_oauth_disconnect' );

		if ( self::AUTH_USER === $this->get_auth_mode() ) {
			$token = $this->get_valid_access_token();
			if ( ! is_wp_error( $token ) && $token ) {
				$this->invalidate_remote_token( $token );
			}
		}

		$this->clear_tokens();
		$this->redirect_settings( 'disconnected' );
	}

	/**
	 * Refresh user access/refresh token pair.
	 *
	 * @return true|\WP_Error
	 */
	public function refresh_tokens() {
		if ( self::AUTH_APPLICATION === $this->get_auth_mode() ) {
			return $this->request_application_token();
		}

		$tokens = $this->get_tokens();
		if ( empty( $tokens['refresh_token'] ) ) {
			$this->clear_tokens();
			return new WP_Error( 'hh_vacancies_no_refresh', __( 'Нет refresh-токена. Получите токен приложения или авторизуйтесь заново.', 'hh-vacancies' ) );
		}

		$result = $this->request_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
			),
			self::AUTH_USER
		);

		if ( is_wp_error( $result ) ) {
			$this->clear_tokens();
			return $result;
		}

		return true;
	}

	/**
	 * Re-auth after API rejection: app token re-issue or user refresh.
	 *
	 * @return true|\WP_Error
	 */
	public function recover_token() {
		if ( self::AUTH_APPLICATION === $this->get_auth_mode() || ! $this->is_connected() ) {
			return $this->request_application_token();
		}

		return $this->refresh_tokens();
	}

	/**
	 * @param array  $body Form body.
	 * @param string $auth_mode application|user.
	 * @return true|\WP_Error
	 */
	private function request_token( array $body, $auth_mode ) {
		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept'       => 'application/json',
		);

		$args = array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => $body,
		);

		$user_agent = $this->settings->get( 'user_agent' );
		if ( $user_agent ) {
			$args['user-agent']               = $user_agent;
			$args['headers']['User-Agent']    = $user_agent;
			$args['headers']['HH-User-Agent'] = $user_agent;
		}

		$response = wp_remote_post( self::TOKEN_URL, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$description = '';
			if ( is_array( $data ) ) {
				if ( ! empty( $data['error_description'] ) ) {
					$description = (string) $data['error_description'];
				} elseif ( ! empty( $data['oauth_error'] ) ) {
					$description = (string) $data['oauth_error'];
				} elseif ( ! empty( $data['error'] ) ) {
					$description = (string) $data['error'];
				}
			}

			return new WP_Error(
				'hh_vacancies_token_failed',
				$description ? $description : __( 'Не удалось получить токен.', 'hh-vacancies' )
			);
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 0;

		$to_save = array(
			'access_token' => $data['access_token'],
			'token_type'   => isset( $data['token_type'] ) ? $data['token_type'] : 'bearer',
			'expires_at'   => $expires_in > 0 ? time() + $expires_in : 0,
			'auth_mode'    => $auth_mode,
		);

		if ( self::AUTH_APPLICATION === $auth_mode ) {
			$to_save['refresh_token'] = '';
			$to_save['expires_at']    = 0;
		} elseif ( ! empty( $data['refresh_token'] ) ) {
			$to_save['refresh_token'] = $data['refresh_token'];
		}

		$this->save_tokens( $to_save );

		return true;
	}

	/**
	 * @param string $access_token Bearer token.
	 */
	private function invalidate_remote_token( $access_token ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $access_token,
			'Accept'        => 'application/json',
		);

		$args = array(
			'method'  => 'DELETE',
			'timeout' => 15,
			'headers' => $headers,
		);

		$user_agent = $this->settings->get( 'user_agent' );
		if ( $user_agent ) {
			$args['user-agent']               = $user_agent;
			$args['headers']['User-Agent']    = $user_agent;
			$args['headers']['HH-User-Agent'] = $user_agent;
		}

		wp_remote_request( self::TOKEN_URL, $args );
	}

	/**
	 * @param string $status Query status.
	 * @param string $message Optional message.
	 */
	private function redirect_settings( $status, $message = '' ) {
		$args = array(
			'page'     => HH_Vacancies_Settings::MENU_SLUG,
			'hh_oauth' => $status,
		);
		if ( $message ) {
			$args['message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}
}
