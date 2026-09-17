<?php
/**
 * Vacancy list template.
 *
 * @var array $vacancies Trimmed vacancy items.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="hh-vacancies">
	<ul class="hh-vacancies__list">
		<?php foreach ( $vacancies as $vacancy ) : ?>
			<?php
			$name   = isset( $vacancy['name'] ) ? $vacancy['name'] : '';
			$area   = isset( $vacancy['area_name'] ) ? $vacancy['area_name'] : '';
			$salary = HH_Vacancies_Vacancies::format_salary( isset( $vacancy['salary'] ) ? $vacancy['salary'] : null );
			$url    = isset( $vacancy['alternate_url'] ) ? $vacancy['alternate_url'] : '';
			?>
			<li class="hh-vacancies__item">
				<?php if ( $url ) : ?>
					<a class="hh-vacancies__title" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $name ); ?>
					</a>
				<?php else : ?>
					<span class="hh-vacancies__title"><?php echo esc_html( $name ); ?></span>
				<?php endif; ?>

				<?php if ( $area ) : ?>
					<span class="hh-vacancies__meta hh-vacancies__area"><?php echo esc_html( $area ); ?></span>
				<?php endif; ?>

				<?php if ( $salary ) : ?>
					<span class="hh-vacancies__meta hh-vacancies__salary"><?php echo esc_html( $salary ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
