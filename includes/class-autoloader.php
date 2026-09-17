<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple class map autoloader for the plugin.
 */
class HH_Vacancies_Autoloader {

	/**
	 * @var array
	 */
	private static $map = array(
		'HH_Vacancies_Plugin'     => 'class-plugin.php',
		'HH_Vacancies_Settings'   => 'class-settings.php',
		'HH_Vacancies_OAuth'      => 'class-oauth.php',
		'HH_Vacancies_Api_Client' => 'class-api-client.php',
		'HH_Vacancies_Vacancies'  => 'class-vacancies.php',
		'HH_Vacancies_Shortcode'  => 'class-shortcode.php',
	);

	/**
	 * Register spl_autoload.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * @param string $class Class name.
	 */
	public static function load( $class ) {
		if ( ! isset( self::$map[ $class ] ) ) {
			return;
		}

		$file = HH_VACANCIES_PATH . 'includes/' . self::$map[ $class ];
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
