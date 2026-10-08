<?php
/**
 * Reading a sheet: fetch, parse, whitelist, cache, and fall back.
 *
 * The order in sheet_tables_fetch() is the point of the plugin. The column
 * whitelist runs on the parsed CSV before anything is cached or stored, so a
 * column that was not chosen exists only for as long as it takes to parse the
 * response. It never reaches the transient, the options table or the page.
 *
 * Every key is derived from the table's post ID, so there is no registry of
 * caches to keep in step.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient holding a table's current copy.
 *
 * @param int $post_id Table post ID.
 * @return string
 */
function sheet_tables_cache_key( $post_id ) {
	return 'sheet_tables_data_' . (int) $post_id;
}

/**
 * Option holding a table's last successful read, served when a read fails.
 *
 * @param int $post_id Table post ID.
 * @return string
 */
function sheet_tables_last_good_key( $post_id ) {
	return 'sheet_tables_last_good_' . (int) $post_id;
}

/**
 * A table's rows, reduced to its chosen columns.
 *
 * Serves the cached copy while it lasts. When a read fails, for any reason,
 * the last good copy is served instead and the reason is recorded for the
 * status screen, so a broken sheet never empties the page.
 *
 * @param int $post_id Table post ID.
 * @return array{header: string[], rows: array[], fetched: int}|WP_Error
 */
function sheet_tables_fetch( $post_id ) {
	$settings = sheet_tables_get_settings( $post_id );

	// Nothing is read until columns are chosen: with no whitelist there is
	// nothing the plugin is allowed to keep.
	if ( '' === $settings['url'] || ! $settings['columns'] ) {
		return new WP_Error( 'sheet_tables_not_configured', __( 'This table needs a sheet URL and at least one column.', 'sheet-tables' ) );
	}

	$cached = get_transient( sheet_tables_cache_key( $post_id ) );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$data = sheet_tables_download( $settings );

	if ( is_wp_error( $data ) ) {
		sheet_tables_record_fault( $post_id, $data->get_error_message() );

		$stale = get_option( sheet_tables_last_good_key( $post_id ) );

		if ( is_array( $stale ) ) {
			// Briefly, so a corrected sheet recovers by itself rather than
			// waiting for someone to clear a cache, but long enough that a
			// broken or unreachable sheet is not re-read on every page view.
			set_transient( sheet_tables_cache_key( $post_id ), $stale, MINUTE_IN_SECONDS );

			return $stale;
		}

		return $data;
	}

	sheet_tables_clear_fault( $post_id );

	set_transient( sheet_tables_cache_key( $post_id ), $data, sheet_tables_cache_ttl( $post_id ) );
	update_option( sheet_tables_last_good_key( $post_id ), $data, false );

	return $data;
}

/**
 * Read the sheet and reduce it to the chosen columns.
 *
 * @param array $settings From sheet_tables_get_settings().
 * @return array{header: string[], rows: array[], fetched: int}|WP_Error
 */
function sheet_tables_download( $settings ) {
	$parsed = sheet_tables_read_sheet( $settings );

	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}

	// Rows, then columns: the filter may test a column that is not chosen,
	// such as an "Include?" column, so it runs while every column is present.
	$parsed = sheet_tables_filter_rows( $parsed, $settings['row_filter'] );

	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}

	// The whitelist. Before set_transient() and update_option(), so whatever
	// is dropped here is never written anywhere.
	$data = sheet_tables_project( $parsed, sheet_tables_column_names( $settings ) );

	// A sheet whose header no longer matches any chosen column has changed
	// shape. Treated as a failure, so the last good copy keeps serving rather
	// than being replaced by an empty table.
	if ( ! $data['header'] ) {
		return new WP_Error( 'sheet_tables_no_columns', __( 'None of the chosen columns were found in the sheet\'s header row.', 'sheet-tables' ) );
	}

	$data['fetched'] = time();

	return $data;
}

/**
 * The sheet ID and tab named by a Google Sheets editor link.
 *
 * @param string $url URL as stored.
 * @return array{id: string, gid: string|null}|null Null if it is not such a link.
 */
function sheet_tables_google_ref( $url ) {
	if ( ! preg_match( '#^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]+)/?(?:edit|view)?(?:[?\#].*)?$#', $url, $sheet ) ) {
		return null;
	}

	return array(
		'id'  => $sheet[1],
		'gid' => preg_match( '/[?&#]gid=(\d+)/', $url, $tab ) ? $tab[1] : null,
	);
}

/**
 * The URL to request for a sheet, given the URL an editor pasted.
 *
 * People paste the link from the address bar or the Share button, which is
 * the sheet's editor, not its data. Google serves the same tab as CSV from an
 * export URL built from the sheet ID and tab (gid), so that is requested
 * instead. Anything else, including a Publish to web link, is used as is.
 *
 * @param string $url URL as stored.
 * @return string
 */
function sheet_tables_csv_url( $url ) {
	$ref = sheet_tables_google_ref( $url );

	if ( ! $ref ) {
		return $url;
	}

	$export = 'https://docs.google.com/spreadsheets/d/' . $ref['id'] . '/export?format=csv';

	if ( null !== $ref['gid'] ) {
		$export .= '&gid=' . $ref['gid'];
	}

	return $export;
}

/**
 * Request the sheet and parse it, all columns included.
 *
 * The result holds every column, so it must never be stored. It is used only
 * by sheet_tables_download(), which whitelists it, and by the settings screen,
 * which shows its header row to the editor and discards the rest.
 *
 * @param array $settings From sheet_tables_get_settings().
 * @return array{header: string[], rows: array[]}|WP_Error
 */
function sheet_tables_read_sheet( $settings ) {
	if ( 'service_account' === $settings['access'] ) {
		$rows = sheet_tables_google_read( $settings['url'] );

		return is_wp_error( $rows ) ? $rows : sheet_tables_parse_rows( $rows, $settings['markers'] );
	}

	// Google will serve a long-stale copy of an export URL: the plain URL has
	// been seen returning a version of a tab that had since been emptied,
	// while the same URL with an unused parameter returned the current one. A
	// read only happens on a cache miss, so this costs nothing.
	//
	// The safe variant refuses local and private addresses, so the URL field
	// cannot be used to make the server request its own network.
	$response = wp_safe_remote_get(
		add_query_arg( 'cachebust', time(), sheet_tables_csv_url( $settings['url'] ) ),
		array(
			'timeout'     => 15,
			'redirection' => 5,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		/* translators: %d: HTTP status code. */
		return new WP_Error( 'sheet_tables_http', sprintf( __( 'The sheet URL answered with HTTP status %d.', 'sheet-tables' ), $code ) );
	}

	// A sheet that stops being published can answer 200 with a sign-in page,
	// which would otherwise be parsed as a one-column CSV.
	$type = implode( ',', (array) wp_remote_retrieve_header( $response, 'content-type' ) );

	if ( false !== stripos( $type, 'text/html' ) ) {
		return new WP_Error( 'sheet_tables_not_csv', __( 'The sheet URL returned a web page instead of CSV. Check that the sheet is still published.', 'sheet-tables' ) );
	}

	return sheet_tables_parse_csv( wp_remote_retrieve_body( $response ), $settings['markers'] );
}

/**
 * Parse CSV into a header row plus data rows.
 *
 * Sheets often carry title and instruction lines above the real header, so
 * with markers the header is located by content rather than assumed to be the
 * first line. Without markers the first non-empty line is the header.
 *
 * @param string   $csv     Raw CSV.
 * @param string[] $markers Header cells that identify the header row.
 * @return array{header: string[], rows: array[]}|WP_Error
 */
function sheet_tables_parse_csv( $csv, $markers = array() ) {
	$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream for fgetcsv(), not the filesystem.
	fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	rewind( $handle );

	$all = array();
	// Separator, enclosure and escape are all passed explicitly: PHP 8.4
	// deprecates relying on the default escape.
	while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		$all[] = array_map( 'trim', array_map( 'strval', (array) $row ) );
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return sheet_tables_parse_rows( $all, $markers );
}

/**
 * Turn rows of cells into a header row plus data rows keyed by header.
 *
 * Shared by both ways of reading a sheet. With markers the header is located
 * by content; without them the first non-empty row is the header.
 *
 * @param string[][] $all     Every row, as lists of trimmed cells.
 * @param string[]   $markers Header cells that identify the header row.
 * @return array{header: string[], rows: array[]}|WP_Error
 */
function sheet_tables_parse_rows( $all, $markers = array() ) {
	$header       = array();
	$header_index = null;

	foreach ( $all as $i => $row ) {
		if ( '' === implode( '', $row ) ) {
			continue;
		}

		$is_header = true;

		foreach ( $markers as $marker ) {
			if ( ! in_array( $marker, $row, true ) ) {
				$is_header = false;
				break;
			}
		}

		if ( $is_header ) {
			$header       = $row;
			$header_index = $i;
			break;
		}
	}

	if ( null === $header_index ) {
		return new WP_Error( 'sheet_tables_no_header', __( 'Could not find the header row in the sheet.', 'sheet-tables' ) );
	}

	$rows = array();
	foreach ( array_slice( $all, $header_index + 1 ) as $row ) {
		if ( '' === implode( '', $row ) ) {
			continue;
		}

		$assoc = array();
		foreach ( $header as $col => $name ) {
			$assoc[ $name ] = isset( $row[ $col ] ) ? $row[ $col ] : '';
		}
		$rows[] = $assoc;
	}

	return array(
		'header' => $header,
		'rows'   => $rows,
	);
}

/**
 * Keep only the rows that meet every row filter condition.
 *
 * Fails closed: a condition that cannot be read, or that names a column the
 * sheet no longer has, is an error, so the last good copy keeps serving. A
 * renamed or deleted filter column must never publish every row.
 *
 * @param array $parsed     Header and rows, all columns included.
 * @param array $conditions From sheet_tables_parse_row_filter().
 * @return array{header: string[], rows: array[]}|WP_Error
 */
function sheet_tables_filter_rows( $parsed, $conditions ) {
	foreach ( $conditions as $condition ) {
		if ( null === $condition['op'] ) {
			/* translators: %s: the row filter line as entered. */
			return new WP_Error( 'sheet_tables_bad_filter', sprintf( __( 'This row filter line is not understood: "%s". Use "Column = value" or "Column != value".', 'sheet-tables' ), $condition['line'] ) );
		}

		if ( ! in_array( $condition['column'], $parsed['header'], true ) ) {
			/* translators: %s: column name. */
			return new WP_Error( 'sheet_tables_filter_column', sprintf( __( 'The row filter\'s column "%s" is not in the sheet\'s header row.', 'sheet-tables' ), $condition['column'] ) );
		}
	}

	$parsed['rows'] = array_values(
		array_filter(
			$parsed['rows'],
			function ( $row ) use ( $conditions ) {
				foreach ( $conditions as $condition ) {
					$equal = sheet_tables_fold( $row[ $condition['column'] ] ?? '' ) === sheet_tables_fold( $condition['value'] );

					if ( ( '=' === $condition['op'] ) !== $equal ) {
						return false;
					}
				}

				return true;
			}
		)
	);

	return $parsed;
}

/**
 * Text for a case-insensitive comparison.
 *
 * @param string $text Text.
 * @return string
 */
function sheet_tables_fold( $text ) {
	$text = trim( (string) $text );

	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
}

/**
 * Reduce parsed data to the chosen columns: the whitelist itself.
 *
 * The header keeps the chosen columns the sheet actually has, in the chosen
 * order. Rows that held nothing in any chosen column are dropped.
 *
 * Also used to re-trim a stored copy when the chosen columns change, which is
 * why it accepts its own output as input.
 *
 * @param array    $parsed  Header and rows, as sheet_tables_parse_csv() returns.
 * @param string[] $columns Chosen source column names.
 * @return array{header: string[], rows: array[]}
 */
function sheet_tables_project( $parsed, $columns ) {
	$header = array_values( array_intersect( $columns, $parsed['header'] ) );
	$rows   = array();

	foreach ( $parsed['rows'] as $row ) {
		$mapped = sheet_tables_map_row( $row, $header );

		if ( $mapped ) {
			$rows[] = $mapped;
		}
	}

	return array(
		'header' => $header,
		'rows'   => $rows,
	);
}

/**
 * Build a row from the wanted columns only.
 *
 * Empty values are skipped rather than written; the renderer treats a missing
 * cell as empty.
 *
 * @param array    $row     Source row.
 * @param string[] $columns Wanted column names.
 * @return array
 */
function sheet_tables_map_row( $row, $columns ) {
	$mapped = array();

	foreach ( $columns as $name ) {
		$value = trim( (string) ( $row[ $name ] ?? '' ) );

		if ( '' !== $value ) {
			$mapped[ $name ] = $value;
		}
	}

	return $mapped;
}

/**
 * Narrow the chosen columns to those the sheet has and that hold a value.
 *
 * @param string[] $columns Chosen columns.
 * @param string[] $header  Stored header.
 * @param array[]  $rows    Stored rows.
 * @return string[]
 */
function sheet_tables_used_columns( $columns, $header, $rows ) {
	return array_values(
		array_filter(
			$columns,
			function ( $name ) use ( $header, $rows ) {
				if ( ! in_array( $name, $header, true ) ) {
					return false;
				}

				foreach ( $rows as $row ) {
					if ( '' !== trim( (string) ( $row[ $name ] ?? '' ) ) ) {
						return true;
					}
				}

				return false;
			}
		)
	);
}

/**
 * Tables that could not be read, as post ID => details.
 *
 * @return array<int, array{message: string, time: int}>
 */
function sheet_tables_get_faults() {
	$faults = get_option( 'sheet_tables_faults' );

	return is_array( $faults ) ? $faults : array();
}

/**
 * Record that a table could not be read.
 *
 * The first time is what matters: it dates the edit that broke the sheet, and
 * a reader wants to know how long the site has been serving an old copy rather
 * than when the most recent page view noticed.
 *
 * @param int    $post_id Table post ID.
 * @param string $message Why the read failed.
 */
function sheet_tables_record_fault( $post_id, $message ) {
	$faults = sheet_tables_get_faults();

	if ( isset( $faults[ $post_id ] ) ) {
		return;
	}

	$faults[ $post_id ] = array(
		'message' => (string) $message,
		'time'    => time(),
	);

	update_option( 'sheet_tables_faults', $faults, false );
}

/**
 * Clear the fault for a table that read cleanly again.
 *
 * @param int $post_id Table post ID.
 */
function sheet_tables_clear_fault( $post_id ) {
	$faults = sheet_tables_get_faults();

	if ( ! isset( $faults[ $post_id ] ) ) {
		return;
	}

	unset( $faults[ $post_id ] );

	update_option( 'sheet_tables_faults', $faults, false );
}

/**
 * Bring stored copies in line with a table's new settings.
 *
 * The cache is always dropped so the next view reads the sheet again. A new
 * source (see sheet_tables_source_signature()) makes the last good copy and
 * any fault meaningless, so both go. With the same source the last good copy
 * is kept as a fallback, but trimmed to the columns now chosen: removing a
 * column from the list must remove its values from the database too, not
 * just from the page.
 *
 * @param int  $post_id        Table post ID.
 * @param bool $source_changed Whether the settings deciding which rows are read changed.
 */
function sheet_tables_settings_changed( $post_id, $source_changed ) {
	delete_transient( sheet_tables_cache_key( $post_id ) );

	if ( $source_changed ) {
		delete_option( sheet_tables_last_good_key( $post_id ) );
		sheet_tables_clear_fault( $post_id );
		return;
	}

	$last_good = get_option( sheet_tables_last_good_key( $post_id ) );

	if ( ! is_array( $last_good ) ) {
		return;
	}

	$trimmed            = sheet_tables_project( $last_good, sheet_tables_column_names( sheet_tables_get_settings( $post_id ) ) );
	$trimmed['fetched'] = $last_good['fetched'] ?? 0;

	update_option( sheet_tables_last_good_key( $post_id ), $trimmed, false );
}

add_action( 'before_delete_post', 'sheet_tables_forget_table' );

/**
 * Remove everything stored for a table when it is deleted.
 *
 * @param int $post_id Post being deleted.
 */
function sheet_tables_forget_table( $post_id ) {
	if ( SHEET_TABLES_POST_TYPE !== get_post_type( $post_id ) ) {
		return;
	}

	delete_transient( sheet_tables_cache_key( $post_id ) );
	delete_option( sheet_tables_last_good_key( $post_id ) );
	sheet_tables_clear_fault( $post_id );
}

/**
 * How long a table's copy is cached, and how long a page may be cached upstream.
 *
 * One value drives both, because they must not drift apart.
 *
 * @param int $post_id Table post ID.
 * @return int Seconds.
 */
function sheet_tables_cache_ttl( $post_id ) {
	$settings = sheet_tables_get_settings( $post_id );

	return (int) apply_filters( 'sheet_tables_cache_ttl', $settings['cache_minutes'] * MINUTE_IN_SECONDS, $post_id );
}

add_filter( 'pantheon_cache_default_max_age', 'sheet_tables_cache_max_age' );

/**
 * Cap how long Pantheon's CDN may hold a page that shows a table.
 *
 * The table's content lives in a spreadsheet, so editing the sheet tells
 * WordPress nothing and nothing purges the CDN. Left alone a page could be
 * served for days after the sheet changed. The page declares the same refresh
 * interval as its shortest table. min() so this can only ever shorten the
 * cache, never extend a stricter site-wide setting.
 *
 * Only fires on Pantheon; elsewhere the filter is never applied.
 *
 * @param int $ttl Site-wide max-age in seconds.
 * @return int
 */
function sheet_tables_cache_max_age( $ttl ) {
	if ( is_admin() || ! is_singular() ) {
		return $ttl;
	}

	$content = (string) get_post_field( 'post_content', get_queried_object_id() );

	foreach ( sheet_tables_ids_in_content( $content ) as $post_id ) {
		$ttl = min( (int) $ttl, sheet_tables_cache_ttl( $post_id ) );
	}

	return $ttl;
}
