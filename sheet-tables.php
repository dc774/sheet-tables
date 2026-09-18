<?php
/**
 * Plugin Name:       Sheet Tables
 * Description:       Shows a published spreadsheet as an accessible HTML table. Only the columns you choose are ever stored or shown, and the last good copy keeps serving if the sheet breaks.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            David Cutri
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sheet-tables
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHEET_TABLES_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHEET_TABLES_URL', plugin_dir_url( __FILE__ ) );

// The one post type every table is stored as.
define( 'SHEET_TABLES_POST_TYPE', 'sheet_table' );

require_once SHEET_TABLES_DIR . 'includes/post-type.php';
require_once SHEET_TABLES_DIR . 'includes/settings.php';
require_once SHEET_TABLES_DIR . 'includes/fetch.php';
require_once SHEET_TABLES_DIR . 'includes/render.php';
