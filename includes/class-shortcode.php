<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [hh_vacancies].
 */
class HH_Vacancies_Shortcode {

	/**
	 * @var HH_Vacancies_Vacancies
	 */
	private $vacancies;

	/**
	 * @param HH_Vacancies_Vacancies $vacancies Vacancies service.
	 */
	public function __construct( HH_Vacancies_Vacancies $vacancies ) {
		$this->vacancies = $vacancies;
	}

	/**
	 * Register shortcode.
	 */
	public function init() {
		add_shortcode( 'hh_vacancies', array( $this, 'render' ) );
	}

	/**
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$this->enqueue_assets();

		$atts = shortcode_atts(
			array(
				'employer_id' => '',
				'per_page'    => 0,
			),
			$atts,
			'hh_vacancies'
		);

		$employer_id = sanitize_text_field( $atts['employer_id'] );
		$per_page    = absint( $atts['per_page'] );

		$list = $this->vacancies->get_list( $employer_id, $per_page );

		if ( is_wp_error( $list ) ) {
			$html = '<div class="hh-vacancies hh-vacancies--error"><p>' . esc_html( $list->get_error_message() ) . '</p>';
			if ( current_user_can( 'manage_options' ) ) {
				$html .= '<p class="hh-vacancies__admin-hint"><a href="' . esc_url( admin_url( 'options-general.php?page=' . HH_Vacancies_Settings::MENU_SLUG ) ) . '">';
				$html .= esc_html__( 'Открыть настройки плагина', 'hh-vacancies' );
				$html .= '</a> · ';
				$html .= esc_html__( 'Код ошибки:', 'hh-vacancies' ) . ' <code>' . esc_html( $list->get_error_code() ) . '</code></p>';
			}
			$html .= '</div>';
			return $html;
		}

		if ( empty( $list ) ) {
			$html = '<div class="hh-vacancies hh-vacancies--empty"><p>' . esc_html__( 'Сейчас нет открытых вакансий.', 'hh-vacancies' ) . '</p>';
			if ( current_user_can( 'manage_options' ) ) {
				$html .= '<p class="hh-vacancies__admin-hint">';
				$html .= esc_html__( 'Проверьте employer_id и нажмите «Проверить API» в настройках плагина.', 'hh-vacancies' );
				$html .= ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . HH_Vacancies_Settings::MENU_SLUG ) ) . '">';
				$html .= esc_html__( 'Настройки', 'hh-vacancies' );
				$html .= '</a></p>';
			}
			$html .= '</div>';
			return $html;
		}

		$vacancies = $list;
		ob_start();
		include HH_VACANCIES_PATH . 'templates/vacancies-list.php';
		return (string) ob_get_clean();
	}

	/**
	 * Ensure front styles load even if has_shortcode() missed the content.
	 */
	private function enqueue_assets() {
		wp_enqueue_style(
			'hh-vacancies-front',
			HH_VACANCIES_URL . 'assets/css/front.css',
			array(),
			HH_VACANCIES_VERSION
		);
	}
}
