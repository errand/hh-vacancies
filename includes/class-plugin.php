<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin bootstrap.
 */
class HH_Vacancies_Plugin {

	/**
	 * @var HH_Vacancies_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var HH_Vacancies_Settings
	 */
	public $settings;

	/**
	 * @var HH_Vacancies_OAuth
	 */
	public $oauth;

	/**
	 * @var HH_Vacancies_Api_Client
	 */
	public $api;

	/**
	 * @var HH_Vacancies_Vacancies
	 */
	public $vacancies;

	/**
	 * @var HH_Vacancies_Shortcode
	 */
	public $shortcode;

	/**
	 * @return HH_Vacancies_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire components and hooks.
	 */
	public function init() {
		$this->settings  = new HH_Vacancies_Settings();
		$this->oauth     = new HH_Vacancies_OAuth( $this->settings );
		$this->api       = new HH_Vacancies_Api_Client( $this->settings, $this->oauth );
		$this->vacancies = new HH_Vacancies_Vacancies( $this->settings, $this->api );
		$this->shortcode = new HH_Vacancies_Shortcode( $this->vacancies );

		$this->settings->init();
		$this->oauth->init();
		$this->shortcode->init();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
	}

	/**
	 * Front CSS for shortcode output.
	 */
	public function enqueue_front_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, 'hh_vacancies' ) ) {
			return;
		}

		wp_enqueue_style(
			'hh-vacancies-front',
			HH_VACANCIES_URL . 'assets/css/front.css',
			array(),
			HH_VACANCIES_VERSION
		);
	}
}
