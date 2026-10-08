<?php
/**
 * The [sheet_table] shortcode and the Sheet Table block.
 *
 * Both go through sheet_tables_render(), so there is one table markup. Every
 * cell is escaped as plain text. Never wp_kses_post() on sheet data: a
 * cell is untrusted input from anyone who can edit the spreadsheet, which is a
 * wider circle than the people who can edit this site.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'sheet_table', 'sheet_tables_shortcode' );
add_action( 'init', 'sheet_tables_register_block' );
add_action( 'init', 'sheet_tables_register_assets' );
add_action( 'enqueue_block_editor_assets', 'sheet_tables_block_editor_data' );

/**
 * Register the block from its block.json.
 */
function sheet_tables_register_block() {
	register_block_type(
		SHEET_TABLES_DIR . 'block',
		array( 'render_callback' => 'sheet_tables_render_block' )
	);
}

/**
 * Render the block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function sheet_tables_render_block( $attributes ) {
	$html = sheet_tables_render( absint( $attributes['id'] ?? 0 ) );

	if ( '' === $html ) {
		return '';
	}

	return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
}

/**
 * Give the block's table picker the list of published tables.
 */
function sheet_tables_block_editor_data() {
	$tables = get_posts(
		array(
			'post_type'   => SHEET_TABLES_POST_TYPE,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	$list = array_map(
		function ( $table ) {
			return array(
				'id'    => $table->ID,
				/* translators: 1: table title, 2: table ID. */
				'title' => sprintf( __( '%1$s (#%2$d)', 'sheet-tables' ), $table->post_title, $table->ID ),
			);
		},
		$tables
	);

	// JSON_HEX_TAG so a title containing "</script>" cannot end the script
	// element early. Titles come from editors, who may lack unfiltered_html.
	wp_add_inline_script(
		generate_block_asset_handle( 'sheet-tables/table', 'editorScript' ),
		'window.sheetTablesBlock = ' . wp_json_encode( array( 'tables' => $list ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
		'before'
	);
}

/**
 * Register the front-end stylesheet and script.
 *
 * Enqueued by sheet_tables_render() only when a table is actually on the page.
 */
function sheet_tables_register_assets() {
	wp_register_style(
		'sheet-tables',
		SHEET_TABLES_URL . 'assets/sheet-tables.css',
		array(),
		(string) filemtime( SHEET_TABLES_DIR . 'assets/sheet-tables.css' )
	);

	wp_register_script(
		'sheet-tables',
		SHEET_TABLES_URL . 'assets/sheet-tables.js',
		array(),
		(string) filemtime( SHEET_TABLES_DIR . 'assets/sheet-tables.js' ),
		true
	);

	wp_localize_script(
		'sheet-tables',
		'sheetTablesL10n',
		array(
			'filter'   => __( 'Filter rows', 'sheet-tables' ),
			/* translators: 1: number of rows shown, 2: number of rows in the table. */
			'count'    => __( 'Showing %1$s of %2$s rows', 'sheet-tables' ),
			'any'      => __( 'Any', 'sheet-tables' ),
			'pages'    => __( 'Table pages', 'sheet-tables' ),
			/* translators: 1: current page number, 2: number of pages. */
			'page'     => __( 'Page %1$s of %2$s', 'sheet-tables' ),
			'previous' => __( 'Previous', 'sheet-tables' ),
			'next'     => __( 'Next', 'sheet-tables' ),
		)
	);
}

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
	$columns  = sheet_tables_used_columns( sheet_tables_display_columns( $settings ), $data['header'], $data['rows'] );

	if ( ! $columns ) {
		return sheet_tables_editor_note( __( 'None of the chosen columns hold any values.', 'sheet-tables' ) );
	}

	$facets = array_intersect( $settings['facets'], $columns );

	wp_enqueue_style( 'sheet-tables' );

	if ( $settings['sort'] || $settings['search'] || $facets || $settings['page_size'] ) {
		wp_enqueue_script( 'sheet-tables' );
	}

	// The visitor tools are switched on by these attributes and built by the
	// script, so with JavaScript off there are no controls that do nothing.
	$attributes = array();

	if ( $settings['sort'] ) {
		$attributes['data-sort'] = '';
	}

	if ( $settings['search'] ) {
		$attributes['data-search'] = '';
	}

	if ( $settings['page_size'] ) {
		$attributes['data-page-size'] = $settings['page_size'];
	}

	if ( $facets ) {
		$attributes['data-separator'] = $settings['separator'];
	}

	ob_start();
	?>
	<div class="sheet-tables"<?php foreach ( $attributes as $attribute => $value ) : ?> <?php echo esc_attr( $attribute ); ?>="<?php echo esc_attr( $value ); ?>"<?php endforeach; ?>>
		<?php // Focusable and named, so keyboard users can scroll a wide table too. ?>
		<div class="sheet-tables__scroll" role="region" tabindex="0" aria-label="<?php echo esc_attr( '' !== $settings['caption'] ? $settings['caption'] : get_the_title( $post ) ); ?>">
		<table class="sheet-tables__table">
			<?php if ( '' !== $settings['caption'] ) : ?>
				<caption><?php echo esc_html( $settings['caption'] ); ?></caption>
			<?php endif; ?>
			<thead>
				<tr>
					<?php foreach ( $columns as $index => $name ) : ?>
						<?php if ( in_array( $name, $facets, true ) ) : ?>
							<th scope="col" data-facet data-param="<?php echo esc_attr( sheet_tables_param( $settings['columns'][ $name ], $index ) ); ?>"><?php echo esc_html( $settings['columns'][ $name ] ); ?></th>
						<?php else : ?>
							<th scope="col"><?php echo esc_html( $settings['columns'][ $name ] ); ?></th>
						<?php endif; ?>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<tr>
						<?php foreach ( $columns as $name ) : ?>
							<td data-label="<?php echo esc_attr( $settings['columns'][ $name ] ); ?>"><?php echo sheet_tables_cell_html( $row, $name, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sheet_tables_cell_html(). ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	</div>
	<?php

	return ob_get_clean();
}

/**
 * The name a filter dropdown uses in a link's #fragment.
 *
 * Taken from the heading visitors see, so a link reads naturally:
 * "Program Strategy" becomes "program-strategy".
 *
 * @param string $heading Column heading.
 * @param int    $index   Column position, used if the heading has no letters or digits.
 * @return string
 */
function sheet_tables_param( $heading, $index ) {
	$param = sanitize_title( $heading );

	return '' !== $param ? $param : 'column-' . ( $index + 1 );
}

/**
 * One cell's contents: escaped text, linked when the table says so.
 *
 * The address comes from the sheet, so only http and https are allowed;
 * anything else, a javascript: address included, leaves plain text.
 *
 * @param array  $row      Stored row.
 * @param string $name     Source column name.
 * @param array  $settings From sheet_tables_get_settings().
 * @return string Escaped HTML.
 */
function sheet_tables_cell_html( $row, $name, $settings ) {
	$text = (string) ( $row[ $name ] ?? '' );
	$html = nl2br( esc_html( $text ) );

	if ( '' === $text || ! isset( $settings['links'][ $name ] ) ) {
		return $html;
	}

	$href = esc_url( (string) ( $row[ (string) $settings['links'][ $name ] ] ?? '' ), array( 'http', 'https' ) );

	return '' === $href ? $html : '<a href="' . $href . '">' . $html . '</a>';
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
 * The table IDs placed in a piece of content, as shortcodes or blocks.
 *
 * @param string $content Post content.
 * @return int[]
 */
function sheet_tables_ids_in_content( $content ) {
	$ids = array();

	if ( has_shortcode( $content, 'sheet_table' ) ) {
		preg_match_all( '/' . get_shortcode_regex( array( 'sheet_table' ) ) . '/', $content, $matches );

		foreach ( $matches[3] as $atts ) {
			$atts = shortcode_parse_atts( $atts );

			if ( ! empty( $atts['id'] ) ) {
				$ids[] = absint( $atts['id'] );
			}
		}
	}

	if ( has_block( 'sheet-tables/table', $content ) ) {
		$ids = array_merge( $ids, sheet_tables_ids_in_blocks( parse_blocks( $content ) ) );
	}

	return array_values( array_unique( $ids ) );
}

/**
 * The table IDs in a list of parsed blocks, including nested ones.
 *
 * @param array[] $blocks Parsed blocks.
 * @return int[]
 */
function sheet_tables_ids_in_blocks( $blocks ) {
	$ids = array();

	foreach ( $blocks as $block ) {
		if ( 'sheet-tables/table' === $block['blockName'] && ! empty( $block['attrs']['id'] ) ) {
			$ids[] = absint( $block['attrs']['id'] );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$ids = array_merge( $ids, sheet_tables_ids_in_blocks( $block['innerBlocks'] ) );
		}
	}

	return $ids;
}
