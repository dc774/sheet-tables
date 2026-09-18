<?php
/**
 * Tools > Sheet Tables: how every table's sheet is doing.
 *
 * Reads only what is already stored. It never requests a sheet, so opening it
 * costs nothing however many tables there are, and it cannot itself be slowed
 * down by the problem it is reporting.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may see the status screen.
 */
function sheet_tables_status_capability() {
	return apply_filters( 'sheet_tables_status_capability', 'manage_options' );
}

/**
 * A timestamp in the site's own date and time format.
 *
 * @param int $time Unix timestamp.
 * @return string
 */
function sheet_tables_format_time( $time ) {
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $time );
}

add_action( 'admin_menu', 'sheet_tables_status_menu' );

/**
 * Put the screen under Tools.
 */
function sheet_tables_status_menu() {
	add_management_page(
		__( 'Sheet Tables Status', 'sheet-tables' ),
		__( 'Sheet Tables', 'sheet-tables' ),
		sheet_tables_status_capability(),
		'sheet-tables-status',
		'sheet_tables_status_page'
	);
}

/**
 * One line of the report per table.
 *
 * @return array[]
 */
function sheet_tables_status_report() {
	$faults = sheet_tables_get_faults();
	$report = array();

	$tables = get_posts(
		array(
			'post_type'   => SHEET_TABLES_POST_TYPE,
			'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	foreach ( $tables as $table ) {
		$settings  = sheet_tables_get_settings( $table->ID );
		$last_good = get_option( sheet_tables_last_good_key( $table->ID ) );
		$last_good = is_array( $last_good ) ? $last_good : null;
		$fault     = $faults[ $table->ID ] ?? null;

		if ( '' === $settings['url'] || ! $settings['columns'] ) {
			$state = __( 'Not set up', 'sheet-tables' );
		} elseif ( $fault && $last_good ) {
			$state = __( 'Showing the last good copy', 'sheet-tables' );
		} elseif ( $fault ) {
			$state = __( 'Failing, nothing to show', 'sheet-tables' );
		} elseif ( $last_good ) {
			$state = __( 'OK', 'sheet-tables' );
		} else {
			$state = __( 'Not read yet', 'sheet-tables' );
		}

		$report[] = array(
			'post'    => $table,
			'state'   => $state,
			'fault'   => $fault,
			'fetched' => $last_good['fetched'] ?? 0,
			'rows'    => $last_good ? count( $last_good['rows'] ) : null,
			'missing' => $last_good ? array_diff( sheet_tables_column_names( $settings ), $last_good['header'] ) : array(),
		);
	}

	return $report;
}

/**
 * Render the screen.
 */
function sheet_tables_status_page() {
	if ( ! current_user_can( sheet_tables_status_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to view this page.', 'sheet-tables' ) );
	}

	$report = sheet_tables_status_report();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Sheet Tables Status', 'sheet-tables' ); ?></h1>
		<p><?php esc_html_e( 'How each table\'s sheet looked the last time it was read. When a sheet cannot be read, visitors keep seeing the last good copy and the reason is shown here.', 'sheet-tables' ); ?></p>

		<?php if ( ! $report ) : ?>
			<p><?php esc_html_e( 'There are no tables yet.', 'sheet-tables' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Table', 'sheet-tables' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'sheet-tables' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last good read', 'sheet-tables' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rows', 'sheet-tables' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Chosen columns missing from the sheet', 'sheet-tables' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Problem', 'sheet-tables' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report as $line ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $line['post']->ID ) ); ?>"><?php echo esc_html( get_the_title( $line['post'] ) ); ?></a>
								<br><code><?php echo esc_html( '[sheet_table id="' . $line['post']->ID . '"]' ); ?></code>
							</td>
							<td><?php echo esc_html( $line['state'] ); ?></td>
							<td><?php echo $line['fetched'] ? esc_html( sheet_tables_format_time( $line['fetched'] ) ) : '&ndash;'; ?></td>
							<td><?php echo null === $line['rows'] ? '&ndash;' : esc_html( number_format_i18n( $line['rows'] ) ); ?></td>
							<td><?php echo $line['missing'] ? esc_html( implode( ', ', $line['missing'] ) ) : '&ndash;'; ?></td>
							<td>
								<?php if ( $line['fault'] ) : ?>
									<?php echo esc_html( $line['fault']['message'] ); ?>
									<br>
									<?php
									printf(
										/* translators: %s: date and time. */
										esc_html__( 'Since %s', 'sheet-tables' ),
										esc_html( sheet_tables_format_time( $line['fault']['time'] ) )
									);
									?>
								<?php else : ?>
									&ndash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
