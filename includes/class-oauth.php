<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth authorization_code + refresh_token handling.
 */
class HH_Vacancies_OAuth {

	const STATE_TRANSIENT = 'hh_vacancies_oauth_state';
	const AUTHORIZE_URL   = 'https://hh.ru/oauth/authorize';
	const TOKEN_URL       = 'https://api.hh.ru/token';

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
		add_action( 'admin_post_hh_vacancies_oauth_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_hh_vacancies_oauth_callback', array( $this, 'handle_callback' ) );
		add_action( 'admin_post_hh_vacancies_oauth_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Fixed redirect URI for HH application settings.
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
		$payload = array(
			'access_token'  => isset( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '',
			'refresh_token' => isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : '',
			'token_type'    => isset( $tokens['token_type'] ) ? (string) $tokens['token_type'] : 'bearer',
			'expires_at'    => isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0,
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
	 * Valid access token, refreshing when near expiry.
	 *
	 * @return string|\WP_Error
	 */
	public function get_valid_access_token() {
		$tokens = $this->get_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			return new WP_Error( 'hh_vacancies_no_token', __( 'Нет токена авторизации. Подключите API в настройках.', 'hh-vacancies' ) );
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
	 * Start OAuth redirect.
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
				'response_type' => 'code',
				'client_id'     => $client_id,
				'state'         => $state,
				'redirect_uri'  => self::get_redirect_uri(),
				'role'          => 'employer',
			),
			self::AUTHORIZE_URL
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external HH authorize URL.
		exit;
	}

	/**
	 * OAuth callback: exchange code for tokens.
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
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_settings( 'error', $result->get_error_message() );
		}

		$this->redirect_settings( 'success' );
	}

	/**
	 * Disconnect: invalidate remote token when possible and clear local storage.
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'hh-vacancies' ) );
		}

		check_admin_referer( 'hh_vacancies_oauth_disconnect' );

		$token = $this->get_valid_access_token();
		if ( ! is_wp_error( $token ) && $token ) {
			$this->invalidate_remote_token( $token );
		}

		$this->clear_tokens();
		$this->redirect_settings( 'disconnected' );
	}

	/**
	 * Refresh access/refresh token pair.
	 *
	 * @return true|\WP_Error
	 */
	public function refresh_tokens() {
		$tokens = $this->get_tokens();
		if ( empty( $tokens['refresh_token'] ) ) {
			$this->clear_tokens();
			return new WP_Error( 'hh_vacancies_no_refresh', __( 'Нет refresh-токена. Требуется повторная авторизация.', 'hh-vacancies' ) );
		}

		$result = $this->request_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->clear_tokens();
			return $result;
		}

		return true;
	}

	/**
	 * @param array $body Form body.
	 * @return true|\WP_Error
	 */
	private function request_token( array $body ) {
		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept'       => 'application/json',
		);

		$user_agent = $this->settings->get( 'user_agent' );
		if ( $user_agent ) {
			$headers['User-Agent']    = $user_agent;
			$headers['HH-User-Agent'] = $user_agent;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);

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

		$this->save_tokens(
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : '',
				'token_type'    => isset( $data['token_type'] ) ? $data['token_type'] : 'bearer',
				'expires_at'    => $expires_in > 0 ? time() + $expires_in : 0,
			)
		);

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

		$user_agent = $this->settings->get( 'user_agent' );
		if ( $user_agent ) {
			$headers['User-Agent']    = $user_agent;
			$headers['HH-User-Agent'] = $user_agent;
		}

		wp_remote_request(
			self::TOKEN_URL,
			array(
				'method'  => 'DELETE',
				'timeout' => 15,
				'headers' => $headers,
			)
		);
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
