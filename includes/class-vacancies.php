<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch and cache trimmed vacancy list for an employer.
 */
class HH_Vacancies_Vacancies {

	/**
	 * @var HH_Vacancies_Settings
	 */
	private $settings;

	/**
	 * @var HH_Vacancies_Api_Client
	 */
	private $api;

	/**
	 * @param HH_Vacancies_Settings   $settings Settings.
	 * @param HH_Vacancies_Api_Client $api API client.
	 */
	public function __construct( HH_Vacancies_Settings $settings, HH_Vacancies_Api_Client $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * @param string $employer_id Employer ID.
	 * @return string
	 */
	public static function cache_key( $employer_id ) {
		return 'hh_vacancies_list_' . sanitize_key( (string) $employer_id );
	}

	/**
	 * @param string $employer_id Employer ID.
	 */
	public static function flush_cache( $employer_id = '' ) {
		if ( $employer_id ) {
			delete_transient( self::cache_key( $employer_id ) );
			return;
		}

		// Best-effort: clear known key from settings if empty id passed later.
	}

	/**
	 * @param string $employer_id Optional override.
	 * @param int    $per_page Optional page size for display (not API page size).
	 * @return array|\WP_Error List of trimmed vacancy arrays.
	 */
	public function get_list( $employer_id = '', $per_page = 0 ) {
		if ( '' === $employer_id ) {
			$employer_id = (string) $this->settings->get( 'employer_id' );
		}

		if ( '' === $employer_id ) {
			return new WP_Error( 'hh_vacancies_no_employer', __( 'Не указан ID работодателя.', 'hh-vacancies' ) );
		}

		$cache_key = self::cache_key( $employer_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$items = $cached;
		} else {
			$fetched = $this->fetch_all( $employer_id );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}

			$items = $fetched;
			$ttl   = (int) $this->settings->get( 'cache_ttl', HH_Vacancies_Settings::DEFAULT_CACHE_TTL ) * MINUTE_IN_SECONDS;
			if ( $ttl < MINUTE_IN_SECONDS ) {
				$ttl = HH_Vacancies_Settings::DEFAULT_CACHE_TTL * MINUTE_IN_SECONDS;
			}
			set_transient( $cache_key, $items, $ttl );
		}

		if ( $per_page > 0 ) {
			$items = array_slice( $items, 0, $per_page );
		}

		return $items;
	}

	/**
	 * @param string $employer_id Employer ID.
	 * @return array|\WP_Error
	 */
	private function fetch_all( $employer_id ) {
		$items    = array();
		$page     = 0;
		$pages    = 1;
		$per_page = 50;

		while ( $page < $pages ) {
			$data = $this->api->get(
				'/vacancies',
				array(
					'employer_id' => $employer_id,
					'per_page'    => $per_page,
					'page'        => $page,
				)
			);

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$pages = isset( $data['pages'] ) ? (int) $data['pages'] : 1;
			if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
				foreach ( $data['items'] as $item ) {
					$trimmed = $this->trim_item( $item );
					if ( $trimmed ) {
						$items[] = $trimmed;
					}
				}
			}

			++$page;

			// Safety: API depth limit ~2000 results.
			if ( $page > 40 ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Keep only fields needed for display (ToS 4.3).
	 *
	 * @param array $item Raw vacancy item.
	 * @return array|null
	 */
	private function trim_item( $item ) {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return null;
		}

		$salary = null;
		if ( ! empty( $item['salary'] ) && is_array( $item['salary'] ) ) {
			$salary = array(
				'from'     => isset( $item['salary']['from'] ) ? $item['salary']['from'] : null,
				'to'       => isset( $item['salary']['to'] ) ? $item['salary']['to'] : null,
				'currency' => isset( $item['salary']['currency'] ) ? $item['salary']['currency'] : null,
				'gross'    => isset( $item['salary']['gross'] ) ? $item['salary']['gross'] : null,
			);
		} elseif ( ! empty( $item['salary_range'] ) && is_array( $item['salary_range'] ) ) {
			$salary = array(
				'from'     => isset( $item['salary_range']['from'] ) ? $item['salary_range']['from'] : null,
				'to'       => isset( $item['salary_range']['to'] ) ? $item['salary_range']['to'] : null,
				'currency' => isset( $item['salary_range']['currency'] ) ? $item['salary_range']['currency'] : null,
				'gross'    => isset( $item['salary_range']['gross'] ) ? $item['salary_range']['gross'] : null,
			);
		}

		return array(
			'id'            => (string) $item['id'],
			'name'          => isset( $item['name'] ) ? (string) $item['name'] : '',
			'area_name'     => ( isset( $item['area'] ) && is_array( $item['area'] ) && isset( $item['area']['name'] ) )
				? (string) $item['area']['name']
				: '',
			'salary'        => $salary,
			'alternate_url' => isset( $item['alternate_url'] ) ? (string) $item['alternate_url'] : '',
		);
	}

	/**
	 * Format salary for display without altering vacancy text fields.
	 *
	 * @param array|null $salary Trimmed salary.
	 * @return string
	 */
	public static function format_salary( $salary ) {
		if ( ! is_array( $salary ) ) {
			return __( 'Зарплата не указана', 'hh-vacancies' );
		}

		$from     = isset( $salary['from'] ) ? $salary['from'] : null;
		$to       = isset( $salary['to'] ) ? $salary['to'] : null;
		$currency = isset( $salary['currency'] ) ? (string) $salary['currency'] : '';

		if ( null === $from && null === $to ) {
			return __( 'Зарплата не указана', 'hh-vacancies' );
		}

		$parts = array();
		if ( null !== $from && null !== $to ) {
			$parts[] = sprintf(
				/* translators: 1: from amount, 2: to amount */
				__( 'от %1$s до %2$s', 'hh-vacancies' ),
				self::format_amount( $from ),
				self::format_amount( $to )
			);
		} elseif ( null !== $from ) {
			$parts[] = sprintf(
				/* translators: %s: amount */
				__( 'от %s', 'hh-vacancies' ),
				self::format_amount( $from )
			);
		} else {
			$parts[] = sprintf(
				/* translators: %s: amount */
				__( 'до %s', 'hh-vacancies' ),
				self::format_amount( $to )
			);
		}

		if ( $currency ) {
			$parts[] = $currency;
		}

		return implode( ' ', $parts );
	}

	/**
	 * @param mixed $amount Amount.
	 * @return string
	 */
	private static function format_amount( $amount ) {
		if ( ! is_numeric( $amount ) ) {
			return (string) $amount;
		}

		return number_format_i18n( (float) $amount, 0 );
	}
}
