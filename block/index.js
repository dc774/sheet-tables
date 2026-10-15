/**
 * The Sheet Table block in the editor.
 *
 * Plain JavaScript against the editor's globals, so the plugin needs no build
 * step. The block saves nothing but the table ID; the table itself is always
 * rendered by PHP, the same code the shortcode uses.
 */
( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	// Published tables, supplied by sheet_tables_block_editor_data().
	var tables = ( window.sheetTablesBlock && window.sheetTablesBlock.tables ) || [];

	var options = [ { value: 0, label: __( 'Choose a table', 'sheet-tables' ) } ].concat(
		tables.map( function ( table ) {
			return { value: table.id, label: table.title };
		} )
	);

	function picker( props ) {
		return el( components.SelectControl, {
			label: __( 'Table', 'sheet-tables' ),
			value: props.attributes.id,
			options: options,
			onChange: function ( value ) {
				props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
			},
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		} );
	}

	blocks.registerBlockType( 'sheet-tables/table', {
		edit: function ( props ) {
			var body;

			if ( props.attributes.id ) {
				body = el( ServerSideRender, {
					block: 'sheet-tables/table',
					attributes: props.attributes,
				} );
			} else {
				body = el(
					components.Placeholder,
					{
						icon: 'editor-table',
						label: __( 'Sheet Table', 'sheet-tables' ),
						instructions: tables.length
							? __( 'Choose the table to show.', 'sheet-tables' )
							: __( 'There are no published tables yet. Add one under Sheet Tables in the admin menu.', 'sheet-tables' ),
					},
					tables.length ? picker( props ) : null
				);
			}

			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el( components.PanelBody, { title: __( 'Table', 'sheet-tables' ) }, picker( props ) )
				),
				body
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.serverSideRender );
