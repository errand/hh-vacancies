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
			return '<div class="hh-vacancies hh-vacancies--error"><p>' . esc_html( $list->get_error_message() ) . '</p></div>';
		}

		if ( empty( $list ) ) {
			return '<div class="hh-vacancies hh-vacancies--empty"><p>' . esc_html__( 'Сейчас нет открытых вакансий.', 'hh-vacancies' ) . '</p></div>';
		}

		$vacancies = $list;
		ob_start();
		include HH_VACANCIES_PATH . 'templates/vacancies-list.php';
		return (string) ob_get_clean();
	}
}
