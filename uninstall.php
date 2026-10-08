<?php
/**
 * Remove everything Sheet Tables stored, when the plugin is deleted.
 *
 * The stored copies hold spreadsheet data, so leaving them behind would keep
 * that data on the site after the plugin that promised to limit it has gone.
 * Deleting each table post runs sheet_tables_forget_table(), which removes the
 * table's cached and last good copies along with the post and its settings.
 *
 * @package SheetTables
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/sheet-tables.php';

/**
 * Delete every table and the fault log on the current site.
 */
function sheet_tables_uninstall_site() {
	$ids = get_posts(
		array(
			'post_type'   => SHEET_TABLES_POST_TYPE,
			'post_status' => array_keys( get_post_stati() ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}

	delete_option( 'sheet_tables_faults' );

	// The token is keyed by the account, so it is found from the key before
	// the key itself goes. A key set in wp-config.php is the owner's to remove.
	$email = sheet_tables_google_email();

	if ( '' !== $email ) {
		delete_transient( sheet_tables_google_token_key( $email ) );
	}

	delete_option( SHEET_TABLES_CREDENTIALS_OPTION );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $sheet_tables_site_id ) {
		switch_to_blog( $sheet_tables_site_id );
		sheet_tables_uninstall_site();
		restore_current_blog();
	}
} else {
	sheet_tables_uninstall_site();
}
