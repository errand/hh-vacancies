<?php
/**
 * Plugin Name: Вакансии компании
 * Description: Вывод актуальных вакансий работодателя через API HH.ru (шорткод [hh_vacancies]).
 * Version: 1.0.0
 * Author: Aleksandr Shatskikh
 * Author URI: https://www.errand.ru
 * Text Domain: hh-vacancies
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HH_VACANCIES_VERSION', '1.0.0' );
define( 'HH_VACANCIES_FILE', __FILE__ );
define( 'HH_VACANCIES_PATH', plugin_dir_path( __FILE__ ) );
define( 'HH_VACANCIES_URL', plugin_dir_url( __FILE__ ) );
define( 'HH_VACANCIES_OPTION_SETTINGS', 'hh_vacancies_settings' );
define( 'HH_VACANCIES_OPTION_TOKENS', 'hh_vacancies_tokens' );

require_once HH_VACANCIES_PATH . 'includes/class-autoloader.php';
HH_Vacancies_Autoloader::register();

add_action(
	'plugins_loaded',
	static function () {
		HH_Vacancies_Plugin::instance()->init();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		$defaults = HH_Vacancies_Settings::default_settings();
		if ( false === get_option( HH_VACANCIES_OPTION_SETTINGS, false ) ) {
			add_option( HH_VACANCIES_OPTION_SETTINGS, $defaults, '', false );
		}
		add_option( HH_VACANCIES_OPTION_TOKENS, array(), '', false );
	}
);
