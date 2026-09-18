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
		'_sheet_tables_columns'       => array(
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
 * A table's settings, parsed and ready to use.
 *
 * @param int $post_id Table post ID.
 * @return array{url: string, columns: array<string, string>, markers: string[], cache_minutes: int, caption: string, sort: bool, search: bool}
 */
function sheet_tables_get_settings( $post_id ) {
	$markers = explode( ',', (string) get_post_meta( $post_id, '_sheet_tables_markers', true ) );

	return array(
		'url'           => (string) get_post_meta( $post_id, '_sheet_tables_url', true ),
		'columns'       => sheet_tables_parse_columns( get_post_meta( $post_id, '_sheet_tables_columns', true ) ),
		'markers'       => array_values( array_filter( array_map( 'trim', $markers ), 'strlen' ) ),
		'cache_minutes' => sheet_tables_sanitize_minutes( get_post_meta( $post_id, '_sheet_tables_cache_minutes', true ) ),
		'caption'       => (string) get_post_meta( $post_id, '_sheet_tables_caption', true ),
		'sort'          => (bool) get_post_meta( $post_id, '_sheet_tables_sort', true ),
		'search'        => (bool) get_post_meta( $post_id, '_sheet_tables_search', true ),
	);
}

/**
 * The source column names a table is allowed to read.
 *
 * Cast back to strings because PHP turns a numeric array key such as "2024"
 * into an integer, which would then fail a strict comparison with the header.
 *
 * @param array $settings From sheet_tables_get_settings().
 * @return string[]
 */
function sheet_tables_column_names( $settings ) {
	return array_map( 'strval', array_keys( $settings['columns'] ) );
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

	wp_nonce_field( 'sheet_tables_save', 'sheet_tables_nonce' );
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="sheet-tables-shortcode"><?php esc_html_e( 'Shortcode', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="text" id="sheet-tables-shortcode" class="regular-text code" readonly value="<?php echo esc_attr( '[sheet_table id="' . $post->ID . '"]' ); ?>">
				<p class="description"><?php esc_html_e( 'Paste this into any post or page to show the table there.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-url"><?php esc_html_e( 'Sheet CSV URL', 'sheet-tables' ); ?></label></th>
			<td>
				<input type="url" id="sheet-tables-url" name="sheet_tables_url" class="large-text code" value="<?php echo esc_attr( $settings['url'] ); ?>" placeholder="https://">
				<p class="description"><?php esc_html_e( 'In Google Sheets choose File, Share, Publish to web, pick the sheet and "Comma-separated values (.csv)", then paste the link here. It must start with https://.', 'sheet-tables' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sheet-tables-columns"><?php esc_html_e( 'Columns', 'sheet-tables' ); ?></label></th>
			<td>
				<textarea id="sheet-tables-columns" name="sheet_tables_columns" class="large-text code" rows="6"><?php echo esc_textarea( $columns ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One column per line, in the order to show them, spelled exactly as in the sheet\'s header row. To show a different heading, add it after a bar, for example "Room | Location".', 'sheet-tables' ); ?>
					<strong><?php esc_html_e( 'Columns not listed here are dropped as soon as the sheet is read. They are never stored on this site or shown.', 'sheet-tables' ); ?></strong>
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
				<p class="description"><?php esc_html_e( 'How long a copy of the sheet is kept before it is read again.', 'sheet-tables' ); ?></p>
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
	</table>
	<?php
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

	$old_url = (string) get_post_meta( $post_id, '_sheet_tables_url', true );

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

	sheet_tables_settings_changed( $post_id, (string) get_post_meta( $post_id, '_sheet_tables_url', true ) !== $old_url );
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
