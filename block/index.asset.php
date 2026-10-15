<?php
/**
 * Dependencies of the block's editor script.
 *
 * Written by hand because the script is plain JavaScript with no build step.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
	'version'      => (string) filemtime( __DIR__ . '/index.js' ),
);
