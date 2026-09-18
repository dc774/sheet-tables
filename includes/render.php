<?php
/**
 * The [sheet_table] shortcode.
 *
 * Every cell is escaped as plain text. Never wp_kses_post() on sheet data: a
 * cell is untrusted input from anyone who can edit the spreadsheet, which is a
 * wider circle than the people who can edit this site.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'sheet_table', 'sheet_tables_shortcode' );

/**
 * Render [sheet_table id="123"].
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function sheet_tables_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sheet_table' );

	return sheet_tables_render( absint( $atts['id'] ) );
}

/**
 * The HTML for one table.
 *
 * @param int $post_id Table post ID.
 * @return string
 */
function sheet_tables_render( $post_id ) {
	$post = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || SHEET_TABLES_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
		return sheet_tables_editor_note( __( 'There is no published sheet table with this id.', 'sheet-tables' ) );
	}

	$data = sheet_tables_fetch( $post_id );

	if ( is_wp_error( $data ) ) {
		return sheet_tables_editor_note( $data->get_error_message() );
	}

	$settings = sheet_tables_get_settings( $post_id );
	$columns  = sheet_tables_used_columns( sheet_tables_column_names( $settings ), $data['header'], $data['rows'] );

	if ( ! $columns ) {
		return sheet_tables_editor_note( __( 'None of the chosen columns hold any values.', 'sheet-tables' ) );
	}

	ob_start();
	?>
	<div class="sheet-tables">
		<table class="sheet-tables__table">
			<?php if ( '' !== $settings['caption'] ) : ?>
				<caption><?php echo esc_html( $settings['caption'] ); ?></caption>
			<?php endif; ?>
			<thead>
				<tr>
					<?php foreach ( $columns as $name ) : ?>
						<th scope="col"><?php echo esc_html( $settings['columns'][ $name ] ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<tr>
						<?php foreach ( $columns as $name ) : ?>
							<td data-label="<?php echo esc_attr( $settings['columns'][ $name ] ); ?>"><?php echo nl2br( esc_html( $row[ $name ] ?? '' ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php

	return ob_get_clean();
}

/**
 * Explain a missing table to people who can fix it, and to nobody else.
 *
 * @param string $message Why nothing is shown.
 * @return string
 */
function sheet_tables_editor_note( $message ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}

	return '<p class="sheet-tables-note">' . esc_html( $message ) . '</p>';
}

/**
 * The table IDs placed in a piece of content.
 *
 * @param string $content Post content.
 * @return int[]
 */
function sheet_tables_ids_in_content( $content ) {
	$ids = array();

	if ( ! has_shortcode( $content, 'sheet_table' ) ) {
		return $ids;
	}

	preg_match_all( '/' . get_shortcode_regex( array( 'sheet_table' ) ) . '/', $content, $matches );

	foreach ( $matches[3] as $atts ) {
		$atts = shortcode_parse_atts( $atts );

		if ( ! empty( $atts['id'] ) ) {
			$ids[] = absint( $atts['id'] );
		}
	}

	return array_unique( $ids );
}
