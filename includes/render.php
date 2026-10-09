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
	$html = sheet_tables_render( absint( $attributes['id'] ?? 0 ), $attributes );

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
			$settings = sheet_tables_get_settings( $table->ID );
			$columns  = array();

			// From the table's settings, not the sheet: the sidebar lists the
			// chosen columns without reading anything.
			foreach ( $settings['columns'] as $name => $heading ) {
				$columns[] = array(
					'name'    => (string) $name,
					'heading' => (string) $heading,
				);
			}

			return array(
				'id'      => $table->ID,
				/* translators: 1: table title, 2: table ID. */
				'title'   => sprintf( __( '%1$s (#%2$d)', 'sheet-tables' ), $table->post_title, $table->ID ),
				'columns' => $columns,
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
			'filter'     => __( 'Filter rows', 'sheet-tables' ),
			/* translators: 1: number of rows shown, 2: number of rows in the table. */
			'count'      => __( 'Showing %1$s of %2$s rows', 'sheet-tables' ),
			'any'        => __( 'Any', 'sheet-tables' ),
			'pages'      => __( 'Table pages', 'sheet-tables' ),
			/* translators: 1: current page number, 2: number of pages. */
			'page'       => __( 'Page %1$s of %2$s', 'sheet-tables' ),
			'previous'   => __( 'Previous', 'sheet-tables' ),
			'next'       => __( 'Next', 'sheet-tables' ),
			'sortBy'     => __( 'Sort by', 'sheet-tables' ),
			'sheetOrder' => __( 'Sheet order', 'sheet-tables' ),
			/* translators: %s: column heading. */
			'ascending'  => __( '%s, A to Z', 'sheet-tables' ),
			/* translators: %s: column heading. */
			'descending' => __( '%s, Z to A', 'sheet-tables' ),
		)
	);
}

/**
 * Render [sheet_table id="123"].
 *
 * Besides the table's id it takes the appearance options that make sense
 * without the block's sidebar: layout="list", style="striped" (or bordered,
 * compact), heading="2" to "6" for list titles, and sticky="1".
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function sheet_tables_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'      => 0,
			'layout'  => 'table',
			'style'   => '',
			'heading' => 3,
			'sticky'  => '',
		),
		$atts,
		'sheet_table'
	);

	return sheet_tables_render(
		absint( $atts['id'] ),
		array(
			'layout'       => $atts['layout'],
			'style'        => $atts['style'],
			'headingLevel' => $atts['heading'],
			'sticky'       => rest_sanitize_boolean( $atts['sticky'] ),
		)
	);
}

/**
 * Appearance options, reduced to known values.
 *
 * They come from block attributes or shortcode attributes, so from anyone
 * who can edit a post. Everything that reaches the markup is one of a fixed
 * set of words, used as a class name, never as CSS.
 *
 * @param array $raw Block or shortcode attributes.
 * @return array{layout: string, style: string, heading: int, sticky: bool, columns: array}
 */
function sheet_tables_display_options( $raw ) {
	$raw     = is_array( $raw ) ? $raw : array();
	$columns = array();

	foreach ( (array) ( $raw['columns'] ?? array() ) as $name => $options ) {
		if ( ! is_array( $options ) ) {
			continue;
		}

		$columns[ (string) $name ] = array(
			'align'   => in_array( $options['align'] ?? '', array( 'center', 'end' ), true ) ? $options['align'] : 'start',
			'width'   => in_array( $options['width'] ?? '', array( 'narrow', 'medium', 'wide' ), true ) ? $options['width'] : 'auto',
			'label'   => false !== ( $options['label'] ?? true ),
			'display' => 'icons' === ( $options['display'] ?? '' ) ? 'icons' : 'text',
		);
	}

	$level = (int) ( $raw['headingLevel'] ?? 3 );

	return array(
		'layout'  => 'list' === ( $raw['layout'] ?? '' ) ? 'list' : 'table',
		'style'   => in_array( $raw['style'] ?? '', array( 'striped', 'bordered', 'compact' ), true ) ? $raw['style'] : '',
		'heading' => ( $level >= 2 && $level <= 6 ) ? $level : 3,
		'sticky'  => ! empty( $raw['sticky'] ),
		'columns' => $columns,
	);
}

/**
 * One column's appearance options, with defaults for columns not set.
 *
 * @param array  $display From sheet_tables_display_options().
 * @param string $name    Source column name.
 * @return array{align: string, width: string, label: bool, display: string}
 */
function sheet_tables_column_display( $display, $name ) {
	return $display['columns'][ (string) $name ] ?? array(
		'align'   => 'start',
		'width'   => 'auto',
		'label'   => true,
		'display' => 'text',
	);
}

/**
 * The HTML for one table.
 *
 * @param int   $post_id Table post ID.
 * @param array $display Appearance options, as block or shortcode attributes.
 * @return string
 */
function sheet_tables_render( $post_id, $display = array() ) {
	$post = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || SHEET_TABLES_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
		return sheet_tables_editor_note( __( 'There is no published sheet table with this id.', 'sheet-tables' ) );
	}

	$data = sheet_tables_fetch( $post_id );

	if ( is_wp_error( $data ) ) {
		return sheet_tables_editor_note( $data->get_error_message() );
	}

	$settings = sheet_tables_get_settings( $post_id );
	$display  = sheet_tables_display_options( $display );
	$columns  = sheet_tables_used_columns( sheet_tables_display_columns( $settings ), $data['header'], $data['rows'] );

	if ( ! $columns ) {
		return sheet_tables_editor_note( __( 'None of the chosen columns hold any values.', 'sheet-tables' ) );
	}

	$facets = array_intersect( $settings['facets'], $columns );

	wp_enqueue_style( 'sheet-tables' );

	if ( $settings['sort'] || $settings['search'] || $facets || $settings['page_size'] ) {
		wp_enqueue_script( 'sheet-tables' );
	}

	// What the script needs to know about each column, in display order. The
	// same for both layouts, so the script never reads it from the markup.
	$meta = array();

	foreach ( $columns as $index => $name ) {
		$meta[] = array(
			'label' => $settings['columns'][ $name ],
			'param' => sheet_tables_param( $settings['columns'][ $name ], $index ),
			'facet' => in_array( $name, $facets, true ),
		);
	}

	// The visitor tools are switched on by these attributes and built by the
	// script, so with JavaScript off there are no controls that do nothing.
	$attributes = array(
		'class'        => trim(
			'sheet-tables sheet-tables--' . $display['layout']
			. ( $display['style'] ? ' is-style-' . $display['style'] : '' )
			. ( $display['sticky'] && 'table' === $display['layout'] ? ' has-sticky-header' : '' )
		),
		'data-columns' => wp_json_encode( $meta ),
	);

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

	$label = '' !== $settings['caption'] ? $settings['caption'] : get_the_title( $post );

	ob_start();
	?>
	<div<?php foreach ( $attributes as $attribute => $value ) : ?> <?php echo esc_attr( $attribute ); ?>="<?php echo esc_attr( $value ); ?>"<?php endforeach; ?>>
		<?php
		if ( 'list' === $display['layout'] ) {
			sheet_tables_render_list( $data['rows'], $columns, $settings, $display, $label );
		} else {
			sheet_tables_render_table( $data['rows'], $columns, $settings, $display, $label );
		}
		?>
	</div>
	<?php

	return ob_get_clean();
}

/**
 * Print the table layout.
 *
 * @param array[]  $rows     Stored rows.
 * @param string[] $columns  Source column names shown, in order.
 * @param array    $settings From sheet_tables_get_settings().
 * @param array    $display  From sheet_tables_display_options().
 * @param string   $label    Accessible name for the scroll box.
 */
function sheet_tables_render_table( $rows, $columns, $settings, $display, $label ) {
	?>
	<?php // Focusable and named, so keyboard users can scroll a wide table too. ?>
	<div class="sheet-tables__scroll" role="region" tabindex="0" aria-label="<?php echo esc_attr( $label ); ?>">
	<table class="sheet-tables__table">
		<?php if ( '' !== $settings['caption'] ) : ?>
			<caption><?php echo esc_html( $settings['caption'] ); ?></caption>
		<?php endif; ?>
		<thead>
			<tr>
				<?php foreach ( $columns as $name ) : ?>
					<?php $column = sheet_tables_column_display( $display, $name ); ?>
					<th scope="col" class="<?php echo esc_attr( 'sheet-tables__align-' . $column['align'] . ' sheet-tables__width-' . $column['width'] ); ?>"><?php echo esc_html( $settings['columns'][ $name ] ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<?php foreach ( $columns as $index => $name ) : ?>
						<?php $column = sheet_tables_column_display( $display, $name ); ?>
						<td data-col="<?php echo esc_attr( $index ); ?>" data-label="<?php echo esc_attr( $settings['columns'][ $name ] ); ?>" class="<?php echo esc_attr( 'sheet-tables__align-' . $column['align'] ); ?>"><?php echo sheet_tables_cell_html( $row, $name, $settings, 'icons' === $column['display'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sheet_tables_cell_html(). ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php
}

/**
 * Print the list layout.
 *
 * Each row is a list item: the first column not shown as icons is its
 * heading, icon columns listed before it sit beside the heading, and every
 * other column is a labelled detail. Empty cells are left out.
 *
 * @param array[]  $rows     Stored rows.
 * @param string[] $columns  Source column names shown, in order.
 * @param array    $settings From sheet_tables_get_settings().
 * @param array    $display  From sheet_tables_display_options().
 * @param string   $label    Accessible name for the list.
 */
function sheet_tables_render_list( $rows, $columns, $settings, $display, $label ) {
	$title = null;

	foreach ( $columns as $index => $name ) {
		if ( 'icons' !== sheet_tables_column_display( $display, $name )['display'] ) {
			$title = $index;
			break;
		}
	}

	$tag = 'h' . $display['heading'];
	?>
	<?php if ( '' !== $settings['caption'] ) : ?>
		<p class="sheet-tables__caption"><?php echo esc_html( $settings['caption'] ); ?></p>
	<?php endif; ?>
	<?php // role="list" because Safari stops announcing a list once its bullets are removed. ?>
	<ul class="sheet-tables__list" role="list" aria-label="<?php echo esc_attr( $label ); ?>">
		<?php foreach ( $rows as $row ) : ?>
			<li class="sheet-tables__item">
				<?php if ( null !== $title && '' !== (string) ( $row[ $columns[ $title ] ] ?? '' ) ) : ?>
					<<?php echo esc_attr( $tag ); ?> class="sheet-tables__title">
						<?php foreach ( array_slice( $columns, 0, $title, true ) as $index => $name ) : ?>
							<?php if ( '' !== (string) ( $row[ $name ] ?? '' ) ) : ?>
								<span class="sheet-tables__lead" data-col="<?php echo esc_attr( $index ); ?>"><?php echo sheet_tables_cell_html( $row, $name, $settings, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sheet_tables_cell_html(). ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
						<span data-col="<?php echo esc_attr( $title ); ?>"><?php echo sheet_tables_cell_html( $row, $columns[ $title ], $settings, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sheet_tables_cell_html(). ?></span>
					</<?php echo esc_attr( $tag ); ?>>
				<?php endif; ?>
				<dl class="sheet-tables__details">
					<?php foreach ( $columns as $index => $name ) : ?>
						<?php
						// The heading and the icons beside it are already shown.
						if ( null !== $title && $index <= $title && '' !== (string) ( $row[ $columns[ $title ] ] ?? '' ) ) {
							continue;
						}

						if ( '' === (string) ( $row[ $name ] ?? '' ) ) {
							continue;
						}

						$column = sheet_tables_column_display( $display, $name );
						?>
						<div class="sheet-tables__detail">
							<dt class="<?php echo $column['label'] ? '' : 'sheet-tables__sr'; ?>"><?php echo esc_html( $settings['columns'][ $name ] ); ?></dt>
							<dd data-col="<?php echo esc_attr( $index ); ?>"><?php echo sheet_tables_cell_html( $row, $name, $settings, 'icons' === $column['display'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sheet_tables_cell_html(). ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
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
 * One cell's contents: escaped text or icons, linked when the table says so.
 *
 * The address comes from the sheet, so only http and https are allowed;
 * anything else, a javascript: address included, leaves plain text.
 *
 * @param array  $row      Stored row.
 * @param string $name     Source column name.
 * @param array  $settings From sheet_tables_get_settings().
 * @param bool   $as_icons Whether the column is shown as icons.
 * @return string Escaped HTML.
 */
function sheet_tables_cell_html( $row, $name, $settings, $as_icons = false ) {
	$text = (string) ( $row[ $name ] ?? '' );
	$html = $as_icons ? sheet_tables_icons_html( $text, $settings ) : nl2br( esc_html( $text ) );

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
