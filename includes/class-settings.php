<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings page and option helpers.
 */
class HH_Vacancies_Settings {

	const MENU_SLUG = 'hh-vacancies';

	const MAX_CACHE_TTL = 15;
	const DEFAULT_CACHE_TTL = 10;
	const LAST_CHECK_OPTION = 'hh_vacancies_last_check';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_hh_vacancies_flush_cache', array( $this, 'handle_flush_cache' ) );
		add_action( 'admin_post_hh_vacancies_test_api', array( $this, 'handle_test_api' ) );
		add_filter( 'pre_update_option_' . HH_VACANCIES_OPTION_SETTINGS, array( $this, 'maybe_flush_on_employer_change' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( HH_VACANCIES_FILE ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Settings link on the Plugins list screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$url = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Настройки', 'hh-vacancies' ) . '</a>';

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'client_id'     => '',
			'client_secret' => '',
			'employer_id'   => '',
			'user_agent'    => '',
			'cache_ttl'     => self::DEFAULT_CACHE_TTL,
		);
	}

	/**
	 * @return array
	 */
	public function get_settings() {
		$stored = get_option( HH_VACANCIES_OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( self::default_settings(), $stored );
		$settings['cache_ttl'] = $this->sanitize_cache_ttl( $settings['cache_ttl'] );

		return $settings;
	}

	/**
	 * @param string $key Setting key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = '' ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Admin menu under Settings.
	 */
	public function register_menu() {
		add_options_page(
			__( 'Вакансии компании', 'hh-vacancies' ),
			__( 'Вакансии компании', 'hh-vacancies' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Settings API registration.
	 */
	public function register_settings() {
		register_setting(
			'hh_vacancies_settings_group',
			HH_VACANCIES_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);

		add_settings_section(
			'hh_vacancies_main',
			__( 'Параметры приложения', 'hh-vacancies' ),
			array( $this, 'render_section_intro' ),
			self::MENU_SLUG
		);

		$fields = array(
			'client_id'     => __( 'Client ID', 'hh-vacancies' ),
			'client_secret' => __( 'Client Secret', 'hh-vacancies' ),
			'employer_id'   => __( 'ID работодателя (employer_id)', 'hh-vacancies' ),
			'user_agent'    => __( 'User-Agent', 'hh-vacancies' ),
			'cache_ttl'     => __( 'TTL кэша (минуты, макс. 15)', 'hh-vacancies' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'hh_vacancies_' . $key,
				$label,
				array( $this, 'render_field' ),
				self::MENU_SLUG,
				'hh_vacancies_main',
				array( 'key' => $key )
			);
		}
	}

	/**
	 * Section help / ToS notes.
	 */
	public function render_section_intro() {
		echo '<p>';
		echo esc_html__(
			'Зарегистрируйте приложение на dev.hh.ru и сохраните Client ID / Secret. Для списка вакансий используется токен приложения (client_credentials) — Redirect URI не обязателен.',
			'hh-vacancies'
		);
		echo '</p><p>';
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> · <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>',
			esc_url( 'https://dev.hh.ru/admin' ),
			esc_html__( 'Регистрация приложения', 'hh-vacancies' ),
			esc_url( 'https://dev.hh.ru/admin/developer_agreement' ),
			esc_html__( 'Условия использования API', 'hh-vacancies' )
		);
		echo '</p><p class="description">';
		echo esc_html__(
			'Плагин показывает вакансии работодателя для найма и ведёт отклик на страницу вакансии. Регистрация соискателей на сайте не выполняется.',
			'hh-vacancies'
		);
		echo '</p>';
	}

	/**
	 * @param array $args Field args.
	 */
	public function render_field( $args ) {
		$key      = $args['key'];
		$settings = $this->get_settings();
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		$name     = HH_VACANCIES_OPTION_SETTINGS . '[' . $key . ']';

		if ( 'client_secret' === $key ) {
			printf(
				'<input type="password" class="regular-text" name="%1$s" value="" autocomplete="new-password" placeholder="%2$s" />',
				esc_attr( $name ),
				esc_attr( $value ? __( '•••••••• (сохранён)', 'hh-vacancies' ) : '' )
			);
			echo '<p class="description">' . esc_html__( 'Оставьте поле пустым при сохранении, чтобы не менять секрет.', 'hh-vacancies' ) . '</p>';
			return;
		}

		if ( 'cache_ttl' === $key ) {
			printf(
				'<input type="number" min="1" max="%1$d" class="small-text" name="%2$s" value="%3$s" />',
				(int) self::MAX_CACHE_TTL,
				esc_attr( $name ),
				esc_attr( (string) $value )
			);
			echo '<p class="description">' . esc_html__( 'Короткий кэш нужен для своевременного удаления архивных вакансий.', 'hh-vacancies' ) . '</p>';
			return;
		}

		if ( 'user_agent' === $key ) {
			printf(
				'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="SiteName/1.0 (email@example.com)" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
			echo '<p class="description">' . esc_html__( 'Обязательный заголовок для запросов к API (название приложения и контактный email).', 'hh-vacancies' ) . '</p>';
			return;
		}

		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" />',
			esc_attr( $name ),
			esc_attr( $value )
		);

		if ( 'employer_id' === $key ) {
			echo '<p class="description">' . esc_html__( 'Числовой ID компании на hh.ru (в URL страницы работодателя: hh.ru/employer/XXXX).', 'hh-vacancies' ) . '</p>';
		}
	}

	/**
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$current = $this->get_settings();
		$output  = self::default_settings();

		$output['client_id']   = isset( $input['client_id'] ) ? sanitize_text_field( $input['client_id'] ) : '';
		$output['employer_id'] = isset( $input['employer_id'] ) ? sanitize_text_field( $input['employer_id'] ) : '';
		$output['user_agent']  = isset( $input['user_agent'] ) ? sanitize_text_field( $input['user_agent'] ) : '';
		$output['cache_ttl']   = isset( $input['cache_ttl'] ) ? $this->sanitize_cache_ttl( $input['cache_ttl'] ) : self::DEFAULT_CACHE_TTL;

		$new_secret = isset( $input['client_secret'] ) ? (string) $input['client_secret'] : '';
		if ( '' === $new_secret ) {
			$output['client_secret'] = isset( $current['client_secret'] ) ? $current['client_secret'] : '';
		} else {
			$output['client_secret'] = sanitize_text_field( $new_secret );
		}

		return $output;
	}

	/**
	 * @param mixed $value Raw TTL.
	 * @return int
	 */
	public function sanitize_cache_ttl( $value ) {
		$ttl = absint( $value );
		if ( $ttl < 1 ) {
			$ttl = self::DEFAULT_CACHE_TTL;
		}
		if ( $ttl > self::MAX_CACHE_TTL ) {
			$ttl = self::MAX_CACHE_TTL;
		}

		return $ttl;
	}

	/**
	 * Flush vacancies cache when employer_id changes.
	 *
	 * @param array $value New value.
	 * @param array $old_value Old value.
	 * @return array
	 */
	public function maybe_flush_on_employer_change( $value, $old_value ) {
		$old_id = is_array( $old_value ) && isset( $old_value['employer_id'] ) ? $old_value['employer_id'] : '';
		$new_id = is_array( $value ) && isset( $value['employer_id'] ) ? $value['employer_id'] : '';

		if ( $old_id !== $new_id ) {
			HH_Vacancies_Vacancies::flush_cache( $old_id );
			HH_Vacancies_Vacancies::flush_cache( $new_id );
		}

		return $value;
	}

	/**
	 * Manual cache flush from admin.
	 */
	public function handle_flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'hh-vacancies' ) );
		}

		check_admin_referer( 'hh_vacancies_flush_cache' );

		$employer_id = $this->get( 'employer_id' );
		HH_Vacancies_Vacancies::flush_cache( $employer_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'flushed' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Live API check from settings page.
	 */
	public function handle_test_api() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'hh-vacancies' ) );
		}

		check_admin_referer( 'hh_vacancies_test_api' );

		$employer_id = (string) $this->get( 'employer_id' );
		HH_Vacancies_Vacancies::flush_cache( $employer_id );

		$list = HH_Vacancies_Plugin::instance()->vacancies->get_list( $employer_id );

		if ( is_wp_error( $list ) ) {
			$payload = array(
				'time'    => time(),
				'ok'      => false,
				'count'   => 0,
				'message' => $list->get_error_message(),
			);
		} else {
			$payload = array(
				'time'    => time(),
				'ok'      => true,
				'count'   => count( $list ),
				'message' => sprintf(
					/* translators: %d: vacancy count */
					__( 'Успешно: получено вакансий — %d.', 'hh-vacancies' ),
					count( $list )
				),
			);
		}

		update_option( self::LAST_CHECK_OPTION, $payload, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::MENU_SLUG,
					'hh_test'  => $payload['ok'] ? 'ok' : 'fail',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Settings page markup.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$oauth        = HH_Vacancies_Plugin::instance()->oauth;
		$connected    = $oauth->is_connected();
		$auth_mode    = $oauth->get_auth_mode();
		$redirect_uri = HH_Vacancies_OAuth::get_redirect_uri();
		$employer_id  = (string) $this->get( 'employer_id' );
		$user_agent   = (string) $this->get( 'user_agent' );
		$client_id    = (string) $this->get( 'client_id' );
		$client_secret = (string) $this->get( 'client_secret' );
		$last_check   = get_option( self::LAST_CHECK_OPTION, array() );

		if ( isset( $_GET['flushed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Кэш вакансий очищен.', 'hh-vacancies' ) . '</p></div>';
		}

		if ( isset( $_GET['hh_oauth'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_key( wp_unslash( $_GET['hh_oauth'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'success' === $status ) {
				$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ? $message : __( 'Авторизация выполнена.', 'hh-vacancies' ) ) . '</p></div>';
			} elseif ( 'error' === $status ) {
				$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ? $message : __( 'Ошибка авторизации.', 'hh-vacancies' ) ) . '</p></div>';
			} elseif ( 'disconnected' === $status ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Подключение отключено.', 'hh-vacancies' ) . '</p></div>';
			}
		}

		if ( ( '' === $client_id || '' === $client_secret ) && ! $connected ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Сохраните Client ID, Client Secret и User-Agent, затем нажмите «Получить токен приложения».', 'hh-vacancies' );
			echo '</p></div>';
		}

		if ( $connected && ( '' === $employer_id || '' === $user_agent ) ) {
			$missing = array();
			if ( '' === $employer_id ) {
				$missing[] = 'employer_id';
			}
			if ( '' === $user_agent ) {
				$missing[] = 'User-Agent';
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: missing field names */
					__( 'Токен есть, но не заполнены обязательные поля: %s. Шорткод не сможет получить вакансии.', 'hh-vacancies' ),
					implode( ', ', $missing )
				)
			);
			echo '</p></div>';
		}

		if ( is_array( $last_check ) && ! empty( $last_check['message'] ) ) {
			$notice_class = ! empty( $last_check['ok'] ) ? 'notice-success' : 'notice-error';
			$when         = ! empty( $last_check['time'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_check['time'] ) : '';
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>';
			echo esc_html( $last_check['message'] );
			if ( $when ) {
				echo ' <span class="description">(' . esc_html( $when ) . ')</span>';
			}
			echo '</p></div>';
		}

		$status_label = esc_html__( 'не подключено', 'hh-vacancies' );
		if ( $connected ) {
			if ( HH_Vacancies_OAuth::AUTH_APPLICATION === $auth_mode ) {
				$status_label = esc_html__( 'токен приложения (client_credentials)', 'hh-vacancies' );
			} elseif ( HH_Vacancies_OAuth::AUTH_USER === $auth_mode ) {
				$status_label = esc_html__( 'токен пользователя (OAuth)', 'hh-vacancies' );
			} else {
				$status_label = esc_html__( 'подключено', 'hh-vacancies' );
			}
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Вакансии компании', 'hh-vacancies' ); ?></h1>

			<div class="card" style="max-width:720px;padding:12px 16px;margin:16px 0;">
				<h2 style="margin-top:0;"><?php echo esc_html__( 'Подключение API', 'hh-vacancies' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: connection status */
						esc_html__( 'Статус: %s.', 'hh-vacancies' ),
						$status_label
					);
					?>
				</p>
				<p class="description">
					<?php echo esc_html__( 'Для публичного списка вакансий нужен токен приложения. Он не истекает; при повторном запросе старый токен отзывается.', 'hh-vacancies' ); ?>
				</p>
				<?php if ( $employer_id ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: employer id */
							esc_html__( 'Текущий employer_id: %s', 'hh-vacancies' ),
							esc_html( $employer_id )
						);
						?>
					</p>
				<?php endif; ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hh_vacancies_app_token' ), 'hh_vacancies_app_token' ) ); ?>">
						<?php echo $connected ? esc_html__( 'Обновить токен приложения', 'hh-vacancies' ) : esc_html__( 'Получить токен приложения', 'hh-vacancies' ); ?>
					</a>

					<?php if ( $connected ) : ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hh_vacancies_oauth_disconnect' ), 'hh_vacancies_oauth_disconnect' ) ); ?>">
							<?php echo esc_html__( 'Отключить', 'hh-vacancies' ); ?>
						</a>
					<?php endif; ?>

					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hh_vacancies_flush_cache' ), 'hh_vacancies_flush_cache' ) ); ?>">
						<?php echo esc_html__( 'Обновить кэш', 'hh-vacancies' ); ?>
					</a>

					<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hh_vacancies_test_api' ), 'hh_vacancies_test_api' ) ); ?>">
						<?php echo esc_html__( 'Проверить API', 'hh-vacancies' ); ?>
					</a>
				</p>
			</div>

			<details class="card" style="max-width:720px;padding:12px 16px;margin:16px 0;">
				<summary style="cursor:pointer;font-weight:600;"><?php echo esc_html__( 'Дополнительно: OAuth работодателя', 'hh-vacancies' ); ?></summary>
				<p class="description">
					<?php echo esc_html__( 'Нужен только для методов кабинета работодателя. Для шорткода вакансий не требуется. Укажите Redirect URI в настройках приложения на dev.hh.ru:', 'hh-vacancies' ); ?>
				</p>
				<code style="display:block;padding:8px;word-break:break-all;margin-bottom:12px;"><?php echo esc_html( $redirect_uri ); ?></code>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hh_vacancies_oauth_start' ), 'hh_vacancies_oauth_start' ) ); ?>">
						<?php echo esc_html__( 'Авторизовать работодателя', 'hh-vacancies' ); ?>
					</a>
				</p>
			</details>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'hh_vacancies_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Сохранить настройки', 'hh-vacancies' ) );
				?>
			</form>

			<p class="description">
				<?php echo esc_html__( 'Шорткод для страницы или записи:', 'hh-vacancies' ); ?>
				<code>[hh_vacancies]</code>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Шорткод должен быть в основном содержимом страницы (шаблон с выводом post.content). В кастомных twig-шаблонах без контента используйте fn(\'do_shortcode\', \'[hh_vacancies]\').', 'hh-vacancies' ); ?>
			</p>
		</div>
		<?php
	}
}
