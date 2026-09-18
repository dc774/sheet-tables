<?php
/**
 * The sheet_table post type.
 *
 * Every table on the site is one post of this type, so ten tables are ten
 * entries under one admin menu, each with its own settings and its own
 * shortcode id. The post has no front end of its own: a table appears wherever
 * its shortcode is placed.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'sheet_tables_register_post_type' );

/**
 * Register the post type.
 */
function sheet_tables_register_post_type() {
	register_post_type(
		SHEET_TABLES_POST_TYPE,
		array(
			'labels'          => array(
				'name'               => __( 'Sheet Tables', 'sheet-tables' ),
				'singular_name'      => __( 'Sheet Table', 'sheet-tables' ),
				'add_new'            => __( 'Add New', 'sheet-tables' ),
				'add_new_item'       => __( 'Add New Table', 'sheet-tables' ),
				'edit_item'          => __( 'Edit Table', 'sheet-tables' ),
				'new_item'           => __( 'New Table', 'sheet-tables' ),
				'search_items'       => __( 'Search Tables', 'sheet-tables' ),
				'not_found'          => __( 'No tables found.', 'sheet-tables' ),
				'not_found_in_trash' => __( 'No tables found in Trash.', 'sheet-tables' ),
				'all_items'          => __( 'All Tables', 'sheet-tables' ),
				'menu_name'          => __( 'Sheet Tables', 'sheet-tables' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'show_in_rest'    => false,
			'capability_type' => 'page',
			'map_meta_cap'    => true,
			'supports'        => array( 'title' ),
			'menu_icon'       => 'dashicons-editor-table',
			'rewrite'         => false,
			'query_var'       => false,
		)
	);
}
