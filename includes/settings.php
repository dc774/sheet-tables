<?php
/**
 * A table's settings: the meta fields, the meta box and its save handler.
 *
 * Core meta boxes rather than a field framework, so the plugin has no
 * dependencies.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every setting a table has, as meta key => definition.
 *
 * The single list that registration, saving and reading all work from, so a
 * new setting is added in one place.
 *
 * @return array<string, array{type: string, default: mixed, sanitize: callable}>
 */
function sheet_tables_meta_fields() {
	return array(
		'_sheet_tables_url'           => array(
			'type'     => 'string',
			'default'  => '',
			'sanitize' => 'sheet_tables_sanitize_url',
		),
		'_sheet_tables_access'        => array(
			'type'     => 'string',
			'default'  => 'link',
			'sanitize' => 'sheet_tables_sanitize_access',
		),
		'_sheet_tables_columns'       => array(
			'type'     => 'string',
			'default'  => '',
			'sanitize' => 'sanitize_textarea_field',
		),
		'_sheet_tables_links'         => array(
			'type'     => 'string',
			'default'  => '',
			'sanitize' => 'sanitize_textarea_field',
		),
		'_sheet_tables_row_filter'    => array(
			'type'     => 'string',
			'default'  => '',
			'sanitize' => 'sanitize_textarea_field',
		),
		'_sheet_tables_markers'       => array(
			'type'     => 'string',
			'default'  => '',
			'sanitize' => 'sanitize_text_field',
		),
		'_sheet_tables_cache_minutes' => array(
			'type'     => 'integer',
			'default'  => 5,
			'sanitize' => 'sheet_tables_sanitize_minutes',
		),
		'_sheet_tables_caption'       => array(
			'type'     => 'string',
			'default'  => '',
			'sanitize' => 'sanitize_text_field',
		),
		'_sheet_tables_sort'          => array(
			'type'     => 'boolean',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		),
		'_sheet_tables_search'        => array(
			'type'     => 'boolean',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		),
		'_sheet_tables_facets'        => array(
			'type'     => 'string',
			'default'  => '',
			'sanitize' => 'sanitize_textarea_field',
		),
		'_sheet_tables_separator'     => array(
			'type'     => 'string',
			'default'  => ',',
			'sanitize' => 'sanitize_text_field',
		),
		'_sheet_tables_page_size'     => array(
			'type'     => 'integer',
			'default'  => 0,
			'sanitize' => 'absint',
		),
	);
}

add_action( 'init', 'sheet_tables_register_meta' );

/**
 * Register the settings as post meta.
 *
 * Sanitizing here means every write is cleaned, however it arrives.
 */
function sheet_tables_register_meta() {
	foreach ( sheet_tables_meta_fields() as $key => $field ) {
		register_post_meta(
			SHEET_TABLES_POST_TYPE,
			$key,
			array(
				'type'              => $field['type'],
				'default'           => $field['default'],
				'single'            => true,
				'sanitize_callback' => $field['sanitize'],
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
}

/**
 * Keep a URL only if it is https.
 *
 * @param string $url Raw URL.
 * @return string The URL, or an empty string if it was rejected.
 */
function sheet_tables_sanitize_url( $url ) {
	return esc_url_raw( trim( (string) $url ), array( 'https' ) );
}

/**
 * How a table reads its sheet: by link, or through the site's service account.
 *
 * @param mixed $access Raw value.
 * @return string
 */
function sheet_tables_sanitize_access( $access ) {
	return 'service_account' === $access ? 'service_account' : 'link';
}

/**
 * Cache duration in whole minutes, at least one.
 *
 * @param mixed $minutes Raw value.
 * @return int
 */
function sheet_tables_sanitize_minutes( $minutes ) {
	return max( 1, (int) $minutes );
}

/**
 * Turn the column setting into source column => heading.
 *
 * One column per line, in display order. A heading other than the sheet's own
 * follows a bar: "Room | Location".
 *
 * @param string $text The stored setting.
 * @return array<string, string>
 */
function sheet_tables_parse_columns( $text ) {
	$columns = array();

	foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
		$parts  = array_map( 'trim', explode( '|', $line, 2 ) );
		$source = $parts[0];

		if ( '' === $source ) {
			continue;
		}

		$columns[ $source ] = ( isset( $parts[1] ) && '' !== $parts[1] ) ? $parts[1] : $source;
	}

	return $columns;
}

/**
 * Turn the row filter setting into conditions.
 *
 * One per line: "Column = value" or "Column != value". A line that is neither
 * is kept with a null operator, so reading the sheet can refuse it rather
 * than silently letting every row through.
 *
 * @param string $text The stored setting.
 * @return array<int, array{line: string, column: string, op: string|null, value: string}>
 */
function sheet_tables_parse_row_filter( $text ) {
	$conditions = array();

	foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		$op    = null;
		$parts = array( $line, '' );

		foreach ( array( '!=', '=' ) as $candidate ) {
			if ( false !== strpos( $line, $candidate ) ) {
				$op    = $candidate;
				$parts = array_map( 'trim', explode( $candidate, $line, 2 ) );
				break;
			}
		}

		if ( '' === $parts[0] ) {
			$op = null;
		}

		$conditions[] = array(
			'line'   => $line,
			'column' => $parts[0],
			'op'     => $op,
			'value'  => $parts[1],
		);
	}

	return $conditions;
}

/**
 * A table's settings, parsed and ready to use.
 *
 * @param int $post_id Table post ID.
 * Links use the same "A | B" lines as columns: the column shown, then the
 * column holding its URL. A line with no bar makes a URL column link to itself.
 *
 * Filter dropdowns are listed by source column name, one per line.
 *
 * @return array{url: string, access: string, columns: array<string, string>, links: array<string, string>, row_filter: array, markers: string[], cache_minutes: int, caption: string, sort: bool, search: bool, facets: string[], separator: string, page_size: int}
 */
function sheet_tables_get_settings( $post_id ) {
	$markers = explode( ',', (string) get_post_meta( $post_id, '_sheet_tables_markers', true ) );

	return array(
		'url'           => (string) get_post_meta( $post_id, '_sheet_tables_url', true ),
		'access'        => sheet_tables_sanitize_access( get_post_meta( $post_id, '_sheet_tables_access', true ) ),
		'columns'       => sheet_tables_parse_columns( get_post_meta( $post_id, '_sheet_tables_columns', true ) ),
		'links'         => sheet_tables_parse_columns( get_post_meta( $post_id, '_sheet_tables_links', true ) ),
		'row_filter'    => sheet_tables_parse_row_filter( get_post_meta( $post_id, '_sheet_tables_row_filter', true ) ),
		'markers'       => array_values( array_filter( array_map( 'trim', $markers ), 'strlen' ) ),
		'cache_minutes' => sheet_tables_sanitize_minutes( get_post_meta( $post_id, '_sheet_tables_cache_minutes', true ) ),
		'caption'       => (string) get_post_meta( $post_id, '_sheet_tables_caption', true ),
		'sort'          => (bool) get_post_meta( $post_id, '_sheet_tables_sort', true ),
		'search'        => (bool) get_post_meta( $post_id, '_sheet_tables_search', true ),
		'facets'        => array_map( 'strval', array_keys( sheet_tables_parse_columns( get_post_meta( $post_id, '_sheet_tables_facets', true ) ) ) ),
		'separator'     => (string) get_post_meta( $post_id, '_sheet_tables_separator', true ),
		'page_size'     => absint( get_post_meta( $post_id, '_sheet_tables_page_size', true ) ),
	);
}

/**
 * The source column names a table shows, in display order.
 *
 * Cast back to strings because PHP turns a numeric array key such as "2024"
 * into an integer, which would then fail a strict comparison with the header.
 *
 * @param array $settings From sheet_tables_get_settings().
 * @return string[]
 */
function sheet_tables_display_columns( $settings ) {
	return array_map( 'strval', array_keys( $settings['columns'] ) );
}

/**
 * The source column names a table is allowed to read: the whitelist.
 *
 * The columns shown, plus the columns that hold their link URLs. A URL
 * column is stored because it was named under Links, but is shown only if it
 * is also listed under Columns.
 *
 * @param array $settings From sheet_tables_get_settings().
 * @return string[]
 */
function sheet_tables_column_names( $settings ) {
	$shown = sheet_tables_display_columns( $settings );
	$urls  = array();

	foreach ( $settings['links'] as $column => $url_column ) {
		if ( in_array( (string) $column, $shown, true ) ) {
			$urls[] = (string) $url_column;
		}
	}

	return array_values( array_unique( array_merge( $shown, $urls ) ) );
}

add_action( 'add_meta_boxes_' . SHEET_TABLES_POST_TYPE, 'sheet_tables_add_meta_box' );

/**
 * Add the settings box to the table edit screen.
 */
function sheet_tables_add_meta_box() {
	add_meta_box(
		'sheet_tables_settings',
		__( 'Table settings', 'sheet-tables' ),
		'sheet_tables_render_meta_box',
		SHEET_TABLES_POST_TYPE,
		'normal',
		'high'
	);
}

/**
 * Print the settings box.
 *
 * @param WP_Post $post The table being edited.
 */
function sheet_tables_render_meta_box( $post ) {
	$settings = sheet_tables_get_settings( $post->ID );
	$columns  = (string) get_post_meta( $post->ID, '_sheet_tables_columns', true );
	$markers  = (string) get_post_meta( $post->ID, '_sheet_tables_markers', true );

	$faults = sheet_tables_get_faults();

	wp_nonce_field( 'sheet_tables_save', 'sheet_tables_nonce' );

	if ( isset( $faults[ $post->ID ] ) ) :
		?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: 1: date and time, 2: error message. */
					esc_html__( 'The sheet could not be read, starting %1$s: %2$s Visitors see the last good copy, if there is one, until this is fixed.', 'sheet-tables' ),
					esc_html( sheet_tables_format_time( $faults[ $post->ID ]['time'] ) ),
					esc_html( $faults[ $post->ID ]['message'] )
				);
				?>
			</p>
		</div>
		<?php
	endif;
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="sheet-tables-shortcode"><?php esc_html_e( 'Shortcode', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="text" id="sheet-tables-shortcode" class="regular-text code" readonly value="<?php echo esc_attr( '[sheet_table id="' . $post->ID . '"]' ); ?>">
				<p class="description"><?php esc_html_e( 'Paste this into any post or page to show the table there.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
		<?php if ( '' !== $settings['url'] && $settings['columns'] && 'auto-draft' !== $post->post_status ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sheet data', 'sheet-tables' ); ?></th>
				<td>
					<?php
					$last_good = get_option( sheet_tables_last_good_key( $post->ID ) );

					if ( is_array( $last_good ) ) {
						printf(
							'<p>%s</p>',
							esc_html(
								sprintf(
									/* translators: 1: date and time, 2: number of rows. */
									_n( 'Last read %1$s: %2$s row.', 'Last read %1$s: %2$s rows.', count( $last_good['rows'] ), 'sheet-tables' ),
									sheet_tables_format_time( $last_good['fetched'] ),
									number_format_i18n( count( $last_good['rows'] ) )
								)
							)
						);
					}

					echo sheet_tables_refresh_link( $post->ID, 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sheet_tables_refresh_link().
					?>
					<p class="description"><?php esc_html_e( 'Reads the sheet now instead of waiting for the refresh interval. Save any changes to the settings first. Pages your host has cached may take a few minutes longer to change.', 'sheet-tables' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><label for="sheet-tables-url"><?php esc_html_e( 'Sheet URL', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="url" id="sheet-tables-url" name="sheet_tables_url" class="large-text code" value="<?php echo esc_attr( $settings['url'] ); ?>" placeholder="https://">
				<p class="description"><?php esc_html_e( 'Paste the Google Sheets link for the tab you want. A "Publish to web" link in CSV format, or any other https link to a CSV file, also works when the sheet is read by link.', 'sheet-tables' ); ?></p>
				<?php
				$ref = sheet_tables_google_ref( $settings['url'] );

				// The Share button's "Copy link" names no tab, and nothing
				// else would tell the editor that another tab is being read.
				if ( $ref && null === $ref['gid'] ) :
					?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'This link does not name a tab, so the sheet\'s first tab is read. To show another tab, open that tab in Google Sheets and copy the link from the browser\'s address bar, which ends in #gid= followed by the tab\'s number.', 'sheet-tables' ); ?></p></div>
					<?php
				endif;
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sheet access', 'sheet-tables' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Sheet access', 'sheet-tables' ); ?></legend>
					<label><input type="radio" name="sheet_tables_access" value="link" <?php checked( 'link', $settings['access'] ); ?>> <?php esc_html_e( 'Shared by link: anyone with the link can view the sheet', 'sheet-tables' ); ?></label><br>
					<label><input type="radio" name="sheet_tables_access" value="service_account" <?php checked( 'service_account', $settings['access'] ); ?>> <?php esc_html_e( 'Private: read through this site\'s Google service account', 'sheet-tables' ); ?></label>
				</fieldset>
				<p class="description"><?php sheet_tables_render_access_help(); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-columns"><?php esc_html_e( 'Columns', 'sheet-tables' ); ?></label></th>
			<td>
				<?php sheet_tables_render_sheet_columns( $settings ); ?>
				<textarea id="sheet-tables-columns" name="sheet_tables_columns" class="large-text code" rows="6"><?php echo esc_textarea( $columns ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One column per line, in the order to show them, spelled exactly as in the sheet\'s header row. To show a different heading, add it after a bar, for example "Room | Location".', 'sheet-tables' ); ?>
					<strong><?php esc_html_e( 'Columns not listed here are dropped as soon as the sheet is read. They are never stored on this site or shown.', 'sheet-tables' ); ?></strong>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-links"><?php esc_html_e( 'Links', 'sheet-tables' ); ?></label></th>
			<td>
				<textarea id="sheet-tables-links" name="sheet_tables_links" class="large-text code" rows="3"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_sheet_tables_links', true ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional. Make a column\'s text a link: one per line, the column shown, a bar, then the column holding the web address, for example "Title | Public URL". The address column is read for this, but shown only if it is also listed under Columns. Only http and https addresses become links.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-row-filter"><?php esc_html_e( 'Show only rows where', 'sheet-tables' ); ?></label></th>
			<td>
				<textarea id="sheet-tables-row-filter" name="sheet_tables_row_filter" class="large-text code" rows="3"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_sheet_tables_row_filter', true ) ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Optional. One condition per line, "Column = value" or "Column != value", for example "Include? = Yes". A row is shown only if every condition holds; letter case is ignored. The column does not need to be listed under Columns.', 'sheet-tables' ); ?>
					<strong><?php esc_html_e( 'Other rows are dropped as soon as the sheet is read. If a condition\'s column disappears from the sheet, the last good copy keeps showing rather than every row.', 'sheet-tables' ); ?></strong>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-markers"><?php esc_html_e( 'Header row', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="text" id="sheet-tables-markers" name="sheet_tables_markers" class="regular-text" value="<?php echo esc_attr( $markers ); ?>">
				<p class="description"><?php esc_html_e( 'Optional. If the sheet has title or instruction rows above its header, list one or more header cells, separated by commas, and the first row holding all of them is used as the header. Leave blank to use the first row.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-cache"><?php esc_html_e( 'Refresh every', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="number" id="sheet-tables-cache" name="sheet_tables_cache_minutes" class="small-text" min="1" step="1" value="<?php echo esc_attr( $settings['cache_minutes'] ); ?>">
				<?php esc_html_e( 'minutes', 'sheet-tables' ); ?>
				<p class="description"><?php esc_html_e( 'How long a copy of the sheet is kept before it is read again. Updating this table drops the copy, so the next page view reads the sheet again.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-caption"><?php esc_html_e( 'Caption', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="text" id="sheet-tables-caption" name="sheet_tables_caption" class="large-text" value="<?php echo esc_attr( $settings['caption'] ); ?>">
				<p class="description"><?php esc_html_e( 'Optional. A title shown with the table and read out by screen readers.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Visitor tools', 'sheet-tables' ); ?></th>
			<td>
				<label><input type="checkbox" name="sheet_tables_sort" value="1" <?php checked( $settings['sort'] ); ?>> <?php esc_html_e( 'Let visitors sort by any column', 'sheet-tables' ); ?></label><br>
				<label><input type="checkbox" name="sheet_tables_search" value="1" <?php checked( $settings['search'] ); ?>> <?php esc_html_e( 'Show a box for filtering rows', 'sheet-tables' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-facets"><?php esc_html_e( 'Filter dropdowns', 'sheet-tables' ); ?></label></th>
			<td>
				<textarea id="sheet-tables-facets" name="sheet_tables_facets" class="large-text code" rows="3"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_sheet_tables_facets', true ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional. Columns that get a dropdown of their values, one per line, spelled as under Columns.', 'sheet-tables' ); ?></p>
				<p>
					<label for="sheet-tables-separator"><?php esc_html_e( 'Values in one cell are separated by', 'sheet-tables' ); ?></label>
					<input type="text" id="sheet-tables-separator" name="sheet_tables_separator" class="small-text code" value="<?php echo esc_attr( $settings['separator'] ); ?>">
				</p>
				<p class="description"><?php esc_html_e( 'So a cell holding "School, Worksite" appears under both values. Leave empty to treat each cell as one value.', 'sheet-tables' ); ?></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: example link. */
						esc_html__( 'A link can open the table already filtered by adding the dropdown\'s heading and a value after #, for example %s. Spaces in the heading become hyphens.', 'sheet-tables' ),
						'<code>' . esc_html( '/library/#program-strategy=School%20wellness' ) . '</code>'
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-page-size"><?php esc_html_e( 'Rows per page', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="number" id="sheet-tables-page-size" name="sheet_tables_page_size" class="small-text" min="0" step="1" value="<?php echo esc_attr( $settings['page_size'] ); ?>">
				<p class="description"><?php esc_html_e( 'Optional. Split a long table into pages. 0 shows every row on one page.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Say who a private sheet must be shared with, or where to set that up.
 */
function sheet_tables_render_access_help() {
	$email = sheet_tables_google_email();

	if ( '' !== $email ) {
		printf(
			/* translators: %s: service account email address. */
			esc_html__( 'For a private sheet, share it with %s as a Viewer.', 'sheet-tables' ),
			'<code>' . esc_html( $email ) . '</code>'
		);
		return;
	}

	esc_html_e( 'Private sheets need a Google service account, which is not set up yet.', 'sheet-tables' );

	if ( current_user_can( 'manage_options' ) ) {
		printf(
			' <a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=sheet-tables-settings' ) ),
			esc_html__( 'Set one up', 'sheet-tables' )
		);
	}
}

/**
 * List the sheet's header cells, so columns can be chosen without guessing
 * at their spelling.
 *
 * Read live each time the screen loads and never stored. The full sheet is
 * in memory only while this runs, and only its header row is printed, on an
 * admin screen, to someone who can edit the table.
 *
 * @param array $settings From sheet_tables_get_settings().
 */
function sheet_tables_render_sheet_columns( $settings ) {
	if ( '' === $settings['url'] ) {
		return;
	}

	$sheet = sheet_tables_read_sheet( $settings );

	if ( is_wp_error( $sheet ) ) {
		printf(
			'<p class="description">%s %s</p>',
			esc_html__( 'Could not read the sheet to list its columns:', 'sheet-tables' ),
			esc_html( $sheet->get_error_message() )
		);
		return;
	}

	$names = array_filter( $sheet['header'], 'strlen' );
	$codes = array_map(
		function ( $name ) {
			return '<code>' . esc_html( $name ) . '</code>';
		},
		$names
	);

	printf(
		'<p>%s %s</p>',
		esc_html__( 'Columns in the sheet:', 'sheet-tables' ),
		implode( ', ', $codes ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each name escaped above.
	);
}

add_action( 'save_post_' . SHEET_TABLES_POST_TYPE, 'sheet_tables_save_settings' );

/**
 * Save the settings box.
 *
 * @param int $post_id Table post ID.
 */
function sheet_tables_save_settings( $post_id ) {
	if ( ! isset( $_POST['sheet_tables_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sheet_tables_nonce'] ) ), 'sheet_tables_save' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$old_source = sheet_tables_source_signature( $post_id );

	foreach ( sheet_tables_meta_fields() as $key => $field ) {
		$name = ltrim( $key, '_' );

		if ( 'boolean' === $field['type'] ) {
			$value = ! empty( $_POST[ $name ] );
		} else {
			// Sanitized by the callback registered in sheet_tables_register_meta().
			$value = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		// A URL that is not https is refused rather than saved as blank, so a
		// typo does not also throw away the working URL it was meant to replace.
		if ( '_sheet_tables_url' === $key && '' !== trim( $value ) && '' === sheet_tables_sanitize_url( $value ) ) {
			add_filter( 'redirect_post_location', 'sheet_tables_flag_rejected_url' );
			continue;
		}

		update_post_meta( $post_id, $key, $value );
	}

	sheet_tables_settings_changed( $post_id, sheet_tables_source_signature( $post_id ) !== $old_source );
}

/**
 * The settings that decide which rows are read, as one comparable value.
 *
 * When any of them changes, the last good copy no longer describes the
 * table and must not be served.
 *
 * @param int $post_id Table post ID.
 * @return string
 */
function sheet_tables_source_signature( $post_id ) {
	$settings = sheet_tables_get_settings( $post_id );

	// The row filter's column is never stored, so a stored copy cannot be
	// re-filtered: a new filter needs a fresh read.
	return wp_json_encode( array( $settings['url'], $settings['access'], $settings['row_filter'] ) );
}

/**
 * Carry a "URL refused" flag through the redirect after saving.
 *
 * @param string $location Redirect URL.
 * @return string
 */
function sheet_tables_flag_rejected_url( $location ) {
	return add_query_arg( 'sheet_tables_url_refused', 1, $location );
}

add_filter(
	'removable_query_args',
	function ( $args ) {
		// Cleared from the address bar once shown, so a reload does not repeat it.
		$args[] = 'sheet_tables_url_refused';
		return $args;
	}
);

add_action( 'admin_notices', 'sheet_tables_rejected_url_notice' );

/**
 * Explain a refused URL, on the table edit screen only.
 */
function sheet_tables_rejected_url_notice() {
	$screen = get_current_screen();

	// Display only: it reads a flag this plugin set on its own redirect.
	if ( ! $screen || SHEET_TABLES_POST_TYPE !== $screen->post_type || empty( $_GET['sheet_tables_url_refused'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'The sheet URL was not saved because it does not start with https://. The previous URL is unchanged.', 'sheet-tables' ); ?></p>
	</div>
	<?php
}
