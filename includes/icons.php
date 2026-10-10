<?php
/**
 * Showing values as Font Awesome icons.
 *
 * A table's Icons setting maps values to icons, one per line: "PDF |
 * file-pdf". Any cell whose value (or one of its values, split on the
 * table's separator) matches a line shows that icon instead of the word.
 * Tables with no lines are untouched.
 *
 * The icons are Font Awesome Free's SVG files, bundled in
 * assets/fontawesome/ with their licence (icons CC BY 4.0). Only the icons a
 * page uses are written into it, inline, so visitors load no font and
 * nothing comes from a CDN. Each value is still kept as visually hidden
 * text, so screen readers, search, the filter dropdowns and sorting all
 * still read "PDF".
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bundled icon styles, by the words people may use for them.
 *
 * Solid is first, so it is the style when none is given. "fas", "far" and
 * "fab" are the older names Font Awesome's site still shows in some places.
 *
 * @return array<string, string> Word => folder.
 */
function sheet_tables_fa_styles() {
	return array(
		'solid'      => 'solid',
		'fa-solid'   => 'solid',
		'fas'        => 'solid',
		'regular'    => 'regular',
		'fa-regular' => 'regular',
		'far'        => 'regular',
		'brands'     => 'brands',
		'fa-brands'  => 'brands',
		'fab'        => 'brands',
	);
}

/**
 * The icon file an Icons line names, or null.
 *
 * Accepts "file-pdf", "regular file-pdf", "fa-regular fa-file-pdf", or the
 * whole tag copied from Font Awesome's site, '<i class="fa-solid fa-utensils"></i>'.
 * Only words of lowercase letters, digits and hyphens are considered, and a
 * name counts only if its file exists, so nothing else can reach a path.
 *
 * @param string $spec The text after the bar.
 * @return array{style: string, name: string}|null
 */
function sheet_tables_fa_parse( $spec ) {
	preg_match_all( '/[a-z0-9-]+/', strtolower( (string) $spec ), $words );

	$styles = sheet_tables_fa_styles();
	$style  = 'solid';
	$names  = array();

	foreach ( $words[0] as $word ) {
		if ( isset( $styles[ $word ] ) ) {
			$style = $styles[ $word ];
		} elseif ( ! in_array( $word, array( 'i', 'class', 'fa' ), true ) ) {
			$names[] = 0 === strpos( $word, 'fa-' ) ? substr( $word, 3 ) : $word;
		}
	}

	// Copied tags can carry size and spacing classes (fa-lg, fa-fw); the
	// first word that is an icon in the chosen style is the icon.
	foreach ( $names as $name ) {
		if ( '' !== $name && is_readable( sheet_tables_fa_file( $style, $name ) ) ) {
			return array(
				'style' => $style,
				'name'  => $name,
			);
		}
	}

	return null;
}

/**
 * Path of a bundled icon file.
 *
 * @param string $style Folder: solid, regular or brands.
 * @param string $name  Icon name, already limited to [a-z0-9-].
 * @return string
 */
function sheet_tables_fa_file( $style, $name ) {
	return SHEET_TABLES_DIR . 'assets/fontawesome/svgs/' . $style . '/' . $name . '.svg';
}

/**
 * An icon's shape, from its bundled file, or null.
 *
 * Only the viewBox and path data are read; the file is never printed as it
 * stands.
 *
 * @param array{style: string, name: string} $icon From sheet_tables_fa_parse().
 * @return array{box: string, paths: string[]}|null
 */
function sheet_tables_fa_shape( $icon ) {
	static $cache = array();

	$key = $icon['style'] . '/' . $icon['name'];

	if ( ! array_key_exists( $key, $cache ) ) {
		$svg = (string) file_get_contents( sheet_tables_fa_file( $icon['style'], $icon['name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled local file, not a URL.

		$cache[ $key ] = ( preg_match( '/viewBox="([0-9.\s-]+)"/', $svg, $box ) && preg_match_all( '/<path[^>]*\sd="([^"]+)"/', $svg, $paths ) )
			? array(
				'box'   => $box[1],
				'paths' => $paths[1],
			)
			: null;
	}

	return $cache[ $key ];
}

/**
 * The id an icon's shape has on the page, within the table being rendered.
 *
 * @param array{style: string, name: string} $icon From sheet_tables_fa_parse().
 * @return string
 */
function sheet_tables_fa_id( $icon ) {
	return 'sheet-tables-fa-' . sheet_tables_fa_scope() . '-' . $icon['style'] . '-' . $icon['name'];
}

/**
 * The current table's number, or, with true, start the next table.
 *
 * Each table carries the shapes of its own icons, under ids of its own. A
 * table never relies on shapes printed by another, because other code (an
 * SEO plugin building a description, say) may render content and throw it
 * away before the real page is built.
 *
 * @param bool $next Whether a new table is starting.
 * @return int
 */
function sheet_tables_fa_scope( $next = false ) {
	static $scope = 0;

	if ( $next ) {
		++$scope;
	}

	return $scope;
}

/**
 * Markup that shows an icon, or an empty string.
 *
 * A reference to the icon's shape, which sheet_tables_fa_symbols() writes
 * once per page, so a long table repeats a few bytes per cell rather than
 * the whole shape. Decorative: the word it stands for is printed beside it.
 *
 * @param array{style: string, name: string} $icon From sheet_tables_fa_parse().
 * @return string
 */
function sheet_tables_fa_svg( $icon ) {
	$shape = sheet_tables_fa_shape( $icon );

	if ( ! $shape ) {
		return '';
	}

	sheet_tables_fa_symbols( $icon );

	return '<svg class="sheet-tables__fa" viewBox="' . esc_attr( $shape['box'] ) . '" aria-hidden="true" focusable="false"><use href="#' . esc_attr( sheet_tables_fa_id( $icon ) ) . '"/></svg>';
}

/**
 * Note an icon as used, or, with no argument, print the current table's
 * shapes and forget them.
 *
 * Each shape is printed once per table, in a hidden SVG after its rows, and
 * every icon in the table refers to it.
 *
 * @param array|null $icon An icon to note, or null to get the markup.
 * @return string Markup when called without an icon.
 */
function sheet_tables_fa_symbols( $icon = null ) {
	static $used = array();

	if ( null !== $icon ) {
		$used[ sheet_tables_fa_id( $icon ) ] = $icon;
		return '';
	}

	$out = '';

	foreach ( $used as $id => $pending ) {
		$shape = sheet_tables_fa_shape( $pending );
		$out  .= '<symbol id="' . esc_attr( $id ) . '" viewBox="' . esc_attr( $shape['box'] ) . '">';

		foreach ( $shape['paths'] as $d ) {
			$out .= '<path fill="currentColor" d="' . esc_attr( $d ) . '"/>';
		}

		$out .= '</symbol>';
	}

	$used = array();

	return '' === $out ? '' : '<svg class="sheet-tables__symbols" aria-hidden="true" focusable="false" width="0" height="0">' . $out . '</svg>';
}

/**
 * A table's Icons lines as folded value => icon.
 *
 * Lines whose icon cannot be found are left out, so their values stay text.
 * Only the icons are cached, never their markup, which belongs to the table
 * being rendered (see sheet_tables_fa_scope()).
 *
 * @param array $settings From sheet_tables_get_settings().
 * @return array<string, array{style: string, name: string}>
 */
function sheet_tables_icon_map( $settings ) {
	static $maps = array();

	$key = md5( wp_json_encode( $settings['icons'] ) );

	if ( isset( $maps[ $key ] ) ) {
		return $maps[ $key ];
	}

	$map = array();

	foreach ( $settings['icons'] as $value => $spec ) {
		$icon = sheet_tables_fa_parse( $spec );

		if ( $icon && sheet_tables_fa_shape( $icon ) ) {
			$map[ sheet_tables_fold( (string) $value ) ] = $icon;
		}
	}

	$maps[ $key ] = $map;

	return $map;
}

/**
 * Icons lines whose icon could not be found, for the table's settings box.
 *
 * @param array $settings From sheet_tables_get_settings().
 * @return string[] The text after the bar, as entered.
 */
function sheet_tables_missing_icons( $settings ) {
	$missing = array();

	foreach ( $settings['icons'] as $spec ) {
		if ( ! sheet_tables_fa_parse( $spec ) ) {
			$missing[] = (string) $spec;
		}
	}

	return $missing;
}

/**
 * A cell's values, split on the table's separator.
 *
 * @param string $text      Cell value.
 * @param string $separator The table's separator, possibly empty.
 * @return string[]
 */
function sheet_tables_split_values( $text, $separator ) {
	$values = '' !== $separator ? explode( $separator, $text ) : array( $text );

	return array_values( array_filter( array_map( 'trim', $values ), 'strlen' ) );
}

/**
 * A cell with any mapped values shown as icons, or null if none matched.
 *
 * Between values the separator is printed: as visible text when a word is
 * beside it, as hidden text between two icons, so the cell's text still
 * reads "PDF, Video" and the filter dropdowns split it as before.
 *
 * @param string $text     Cell value.
 * @param array  $settings From sheet_tables_get_settings().
 * @return string|null Escaped HTML.
 */
function sheet_tables_icons_html( $text, $settings ) {
	$map = $settings['icons'] ? sheet_tables_icon_map( $settings ) : array();

	if ( ! $map ) {
		return null;
	}

	$values = sheet_tables_split_values( $text, $settings['separator'] );
	$parts  = array();
	$found  = false;

	foreach ( $values as $value ) {
		$key = sheet_tables_fold( $value );

		if ( isset( $map[ $key ] ) ) {
			$found   = true;
			$parts[] = array(
				true,
				'<span class="sheet-tables__icon" title="' . esc_attr( $value ) . '">' . sheet_tables_fa_svg( $map[ $key ] ) . '<span class="sheet-tables__sr">' . esc_html( $value ) . '</span></span>',
			);
		} else {
			$parts[] = array( false, esc_html( $value ) );
		}
	}

	if ( ! $found ) {
		return null;
	}

	$html = '';

	foreach ( $parts as $index => $part ) {
		if ( $index > 0 ) {
			$between = esc_html( $settings['separator'] ) . ' ';
			$html   .= ( $part[0] && $parts[ $index - 1 ][0] ) ? '<span class="sheet-tables__sr">' . $between . '</span>' : $between;
		}

		$html .= $part[1];
	}

	return $html;
}

/**
 * Whether every value in a column is shown as an icon.
 *
 * Used by the list layout: such a column, listed before the title, sits
 * beside each item's heading.
 *
 * @param array[] $rows     Stored rows.
 * @param string  $name     Source column name.
 * @param array   $settings From sheet_tables_get_settings().
 * @return bool
 */
function sheet_tables_column_is_icons( $rows, $name, $settings ) {
	$map = $settings['icons'] ? sheet_tables_icon_map( $settings ) : array();
	$any = false;

	if ( ! $map ) {
		return false;
	}

	foreach ( $rows as $row ) {
		foreach ( sheet_tables_split_values( (string) ( $row[ $name ] ?? '' ), $settings['separator'] ) as $value ) {
			if ( ! isset( $map[ sheet_tables_fold( $value ) ] ) ) {
				return false;
			}

			$any = true;
		}
	}

	return $any;
}
