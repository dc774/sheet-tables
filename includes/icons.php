<?php
/**
 * Showing a column's values as icons.
 *
 * A value gets a custom icon from the table's "Custom icons" lines, else a
 * built-in file-type icon, else stays as text. The value itself is always
 * kept as visually hidden text, so screen readers, search, the filter
 * dropdowns and sorting all still read "PDF" rather than nothing.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The built-in icons and the cell values each stands for.
 *
 * Keys name the icons in assets/icons/ and the stylesheet, so they are
 * fixed. The values can be changed with the sheet_tables_icon_values filter.
 * Values are compared without regard to case.
 *
 * @return array<string, string[]>
 */
function sheet_tables_builtin_icons() {
	return (array) apply_filters(
		'sheet_tables_icon_values',
		array(
			'pdf'          => array( 'pdf' ),
			'document'     => array( 'word', 'doc', 'docx', 'document' ),
			'spreadsheet'  => array( 'excel', 'xls', 'xlsx', 'csv', 'spreadsheet' ),
			'presentation' => array( 'powerpoint', 'ppt', 'pptx', 'slides', 'presentation' ),
			'video'        => array( 'video', 'mp4' ),
			'audio'        => array( 'audio', 'mp3', 'podcast' ),
			'image'        => array( 'image', 'jpg', 'jpeg', 'png', 'photo' ),
			'link'         => array( 'link', 'web page', 'webpage', 'website', 'url' ),
		)
	);
}

/**
 * The built-in icon for a value, or an empty string.
 *
 * @param string $value Cell value.
 * @return string Icon key.
 */
function sheet_tables_builtin_icon( $value ) {
	// Built once per request: a long table asks for every cell.
	static $lookup = null;

	if ( null === $lookup ) {
		$lookup = array();

		foreach ( sheet_tables_builtin_icons() as $icon => $values ) {
			foreach ( (array) $values as $candidate ) {
				$key = sheet_tables_fold( (string) $candidate );

				// The first icon to claim a value keeps it.
				if ( ! isset( $lookup[ $key ] ) ) {
					$lookup[ $key ] = (string) $icon;
				}
			}
		}
	}

	return $lookup[ sheet_tables_fold( $value ) ] ?? '';
}

/**
 * A cell's values as icons, keeping the words for assistive technology.
 *
 * Each value of a multi-value cell becomes its own icon. The separator is
 * kept as hidden text between them, so the cell's text still reads
 * "PDF, Video" and the filter dropdowns split it as before.
 *
 * @param string $text     Cell value.
 * @param array  $settings From sheet_tables_get_settings().
 * @return string Escaped HTML.
 */
function sheet_tables_icons_html( $text, $settings ) {
	$separator = $settings['separator'];
	$values    = '' !== $separator ? explode( $separator, $text ) : array( $text );
	$values    = array_values( array_filter( array_map( 'trim', $values ), 'strlen' ) );

	// Custom icons by folded value, so "pdf" and "PDF" match the same line.
	$custom = array();
	foreach ( $settings['icons'] as $value => $url ) {
		$custom[ sheet_tables_fold( (string) $value ) ] = (string) $url;
	}

	$parts = array_map(
		function ( $value ) use ( $custom ) {
			return sheet_tables_icon_html( $value, $custom );
		},
		$values
	);

	return implode( '<span class="sheet-tables__sr">' . esc_html( $separator ) . ' </span>', $parts );
}

/**
 * One value as an icon, or as text when no icon matches.
 *
 * The icon is decorative; the hidden word beside it is what is announced,
 * and the tooltip shows the same word to sighted users.
 *
 * @param string $value  One value.
 * @param array  $custom Custom icon URLs by folded value.
 * @return string Escaped HTML.
 */
function sheet_tables_icon_html( $value, $custom ) {
	$key  = sheet_tables_fold( $value );
	$word = '<span class="sheet-tables__sr">' . esc_html( $value ) . '</span>';
	$url  = isset( $custom[ $key ] ) ? esc_url( $custom[ $key ], array( 'http', 'https' ) ) : '';

	if ( '' !== $url ) {
		$icon = '<img class="sheet-tables__icon-img" src="' . $url . '" alt="" aria-hidden="true" loading="lazy" decoding="async">';
	} else {
		$builtin = sheet_tables_builtin_icon( $value );

		if ( '' === $builtin ) {
			return esc_html( $value );
		}

		$icon = '<span class="sheet-tables__icon sheet-tables__icon--' . esc_attr( $builtin ) . '" aria-hidden="true"></span>';
	}

	return '<span class="sheet-tables__icon-wrap" title="' . esc_attr( $value ) . '">' . $icon . $word . '</span>';
}
