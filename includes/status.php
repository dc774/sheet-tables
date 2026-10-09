<?php
/**
 * Tools > Sheet Tables: how every table's sheet is doing.
 *
 * Reads only what is already stored. It never requests a sheet, so opening it
 * costs nothing however many tables there are, and it cannot itself be slowed
 * down by the problem it is reporting. A sheet is read only when someone
 * presses a table's "Pull fresh data" button.
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
			'ready'   => '' !== $settings['url'] && $settings['columns'],
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
								<?php if ( $line['ready'] && current_user_can( 'edit_post', $line['post']->ID ) ) : ?>
									<br><?php echo sheet_tables_refresh_link( $line['post']->ID, 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sheet_tables_refresh_link(). ?>
								<?php endif; ?>
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

add_action( 'admin_post_sheet_tables_refresh', 'sheet_tables_refresh' );
add_action( 'admin_notices', 'sheet_tables_refresh_notice' );

/**
 * Link that drops a table's copy and reads its sheet again.
 *
 * A link rather than a form, because on the edit screen it sits inside the
 * post's own form. The nonce is tied to the table.
 *
 * @param int    $post_id Table post ID.
 * @param string $back    Where to return: "edit" or "status".
 * @return string
 */
function sheet_tables_refresh_link( $post_id, $back ) {
	$url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'sheet_tables_refresh',
				'table'  => (int) $post_id,
				'back'   => 'edit' === $back ? 'edit' : 'status',
			),
			admin_url( 'admin-post.php' )
		),
		'sheet_tables_refresh_' . (int) $post_id
	);

	return '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Pull fresh data', 'sheet-tables' ) . '</a>';
}

/**
 * Read a table's sheet now, then return to where the button was.
 */
function sheet_tables_refresh() {
	$post_id = isset( $_GET['table'] ) ? absint( $_GET['table'] ) : 0;

	check_admin_referer( 'sheet_tables_refresh_' . $post_id );

	if ( SHEET_TABLES_POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You are not allowed to refresh this table.', 'sheet-tables' ), 403 );
	}

	delete_transient( sheet_tables_cache_key( $post_id ) );
	$data = sheet_tables_fetch( $post_id );

	// A failed read still returns the last good copy, so success is told by
	// the fault log, which every successful read clears.
	$faults = sheet_tables_get_faults();
	$result = ( is_wp_error( $data ) || isset( $faults[ $post_id ] ) ) ? 'failed' : count( $data['rows'] );

	$back     = isset( $_GET['back'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['back'] ) ) ? get_edit_post_link( $post_id, 'raw' ) : admin_url( 'tools.php?page=sheet-tables-status' );
	$redirect = add_query_arg(
		array(
			'sheet_tables_refreshed' => $result,
			'sheet_tables_table'     => $post_id,
		),
		$back
	);

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Say how the refresh went, on the screen it returned to.
 */
function sheet_tables_refresh_notice() {
	// Display only: these read flags this plugin set on its own redirect.
	if ( ! isset( $_GET['sheet_tables_refreshed'], $_GET['sheet_tables_table'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$result  = sanitize_key( wp_unslash( $_GET['sheet_tables_refreshed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post_id = absint( $_GET['sheet_tables_table'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// The flags can be typed into any admin address, so a title is shown only
	// for a table the viewer could have refreshed.
	if ( SHEET_TABLES_POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$title = get_the_title( $post_id );

	if ( 'failed' === $result ) {
		$faults = sheet_tables_get_faults();
		$reason = $faults[ $post_id ]['message'] ?? __( 'The table needs a sheet URL and at least one column.', 'sheet-tables' );
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: table title, 2: why the read failed. */
					esc_html__( 'Could not read the sheet for "%1$s": %2$s Visitors still see the last good copy, if there is one.', 'sheet-tables' ),
					esc_html( $title ),
					esc_html( $reason )
				);
				?>
			</p>
		</div>
		<?php
		return;
	}
	?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			printf(
				/* translators: 1: number of rows, 2: table title. */
				esc_html( _n( 'Pulled %1$s row for "%2$s" just now.', 'Pulled %1$s rows for "%2$s" just now.', absint( $result ), 'sheet-tables' ) ),
				esc_html( number_format_i18n( absint( $result ) ) ),
				esc_html( $title )
			);
			?>
		</p>
	</div>
	<?php
}

add_filter(
	'removable_query_args',
	function ( $args ) {
		// Cleared from the address bar once shown, so a reload does not repeat it.
		$args[] = 'sheet_tables_refreshed';
		$args[] = 'sheet_tables_table';
		return $args;
	}
);
