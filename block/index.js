/**
 * The Sheet Table block in the editor.
 *
 * Plain JavaScript against the editor's globals, so the plugin needs no build
 * step. The block saves only the table ID and appearance choices; the table
 * itself is always rendered by PHP, the same code the shortcode uses.
 *
 * Appearance lives here, data on the table: the sidebar chooses how a table
 * looks in this one place, never which rows or columns it holds.
 */
( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	// Published tables and their columns, supplied by sheet_tables_block_editor_data().
	var tables = ( window.sheetTablesBlock && window.sheetTablesBlock.tables ) || [];

	var options = [ { value: 0, label: __( 'Choose a table', 'sheet-tables' ) } ].concat(
		tables.map( function ( table ) {
			return { value: table.id, label: table.title };
		} )
	);

	// Props every control shares, for the editor's current look.
	var modern = { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true };

	function withModern( props ) {
		return Object.assign( {}, modern, props );
	}

	function picker( props ) {
		return el( components.SelectControl, withModern( {
			label: __( 'Table', 'sheet-tables' ),
			value: props.attributes.id,
			options: options,
			onChange: function ( value ) {
				props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
			},
		} ) );
	}

	function select( props, attribute, label, choices, help ) {
		return el( components.SelectControl, withModern( {
			label: label,
			value: String( props.attributes[ attribute ] ),
			options: choices.map( function ( choice ) {
				return { value: String( choice[ 0 ] ), label: choice[ 1 ] };
			} ),
			help: help || null,
			onChange: function ( value ) {
				var update = {};

				update[ attribute ] = 'headingLevel' === attribute ? parseInt( value, 10 ) : value;
				props.setAttributes( update );
			},
		} ) );
	}

	function toggle( props, attribute, label, help ) {
		return el( components.ToggleControl, {
			__nextHasNoMarginBottom: true,
			label: label,
			help: help || null,
			checked: !! props.attributes[ attribute ],
			onChange: function ( value ) {
				var update = {};

				update[ attribute ] = value;
				props.setAttributes( update );
			},
		} );
	}

	// Each layout has its own options; only the chosen layout's are shown.
	function layoutPanel( props ) {
		var isList = 'list' === props.attributes.layout;
		var controls = [
			select( props, 'layout', __( 'Show as', 'sheet-tables' ), [
				[ 'table', __( 'Table', 'sheet-tables' ) ],
				[ 'list', __( 'List', 'sheet-tables' ) ],
			], isList ? __( 'Each row becomes a list item. The first column is its heading; a column of icons listed before it appears beside the heading.', 'sheet-tables' ) : null ),
		];

		if ( isList ) {
			controls.push(
				select( props, 'headingLevel', __( 'Heading level for each item', 'sheet-tables' ), [ 2, 3, 4, 5, 6 ].map( function ( level ) {
					return [ level, 'H' + level ];
				} ), __( 'Pick the level that fits under the page\'s own headings.', 'sheet-tables' ) ),
				select( props, 'itemSpacing', __( 'Space between items', 'sheet-tables' ), [
					[ 'normal', __( 'Normal', 'sheet-tables' ) ],
					[ 'compact', __( 'Compact', 'sheet-tables' ) ],
					[ 'roomy', __( 'Roomy', 'sheet-tables' ) ],
				] ),
				toggle( props, 'dividers', __( 'Divider lines between items', 'sheet-tables' ) ),
				select( props, 'labels', __( 'Labels', 'sheet-tables' ), [
					[ 'beside', __( 'Beside their values', 'sheet-tables' ) ],
					[ 'above', __( 'Above their values', 'sheet-tables' ) ],
				] ),
				select( props, 'detailSpacing', __( 'Space between details', 'sheet-tables' ), [
					[ 'normal', __( 'Normal', 'sheet-tables' ) ],
					[ 'compact', __( 'Compact', 'sheet-tables' ) ],
				] )
			);
		} else {
			controls.push(
				toggle( props, 'striped', __( 'Striped rows', 'sheet-tables' ) ),
				select( props, 'lines', __( 'Lines', 'sheet-tables' ), [
					[ 'theme', __( 'Theme default', 'sheet-tables' ) ],
					[ 'none', __( 'None', 'sheet-tables' ) ],
					[ 'rows', __( 'Between rows', 'sheet-tables' ) ],
					[ 'all', __( 'Around every cell', 'sheet-tables' ) ],
				] ),
				select( props, 'padding', __( 'Cell padding', 'sheet-tables' ), [
					[ 'theme', __( 'Theme default', 'sheet-tables' ) ],
					[ 'compact', __( 'Compact', 'sheet-tables' ) ],
					[ 'roomy', __( 'Roomy', 'sheet-tables' ) ],
				] ),
				select( props, 'narrow', __( 'On small screens', 'sheet-tables' ), [
					[ 'stack', __( 'Stack each row as labelled lines', 'sheet-tables' ) ],
					[ 'scroll', __( 'Keep the columns and scroll sideways', 'sheet-tables' ) ],
				], __( 'Either way, everything in the table stays visible.', 'sheet-tables' ) ),
				toggle( props, 'sticky', __( 'Keep the header row in view while scrolling', 'sheet-tables' ), __( 'The table scrolls inside a box at most as tall as the screen.', 'sheet-tables' ) )
			);
		}

		return el.apply( null, [ components.PanelBody, { title: __( 'Layout', 'sheet-tables' ) } ].concat( controls ) );
	}

	function columnsPanel( props ) {
		var attributes = props.attributes;
		var table = tables.filter( function ( candidate ) {
			return candidate.id === attributes.id;
		} )[ 0 ];

		if ( ! table || ! table.columns.length ) {
			return null;
		}

		function set( name, key, value ) {
			var columns = Object.assign( {}, attributes.columns );

			columns[ name ] = Object.assign( {}, columns[ name ] );
			columns[ name ][ key ] = value;
			props.setAttributes( { columns: columns } );
		}

		return el(
			components.PanelBody,
			{ title: __( 'Columns', 'sheet-tables' ), initialOpen: false },
			table.columns.map( function ( column ) {
				var current = attributes.columns[ column.name ] || {};

				return el(
					components.PanelBody,
					{ key: column.name, title: column.heading, initialOpen: false },
					// Alignment and width shape table columns; a list has none.
					'list' === attributes.layout
						? null
						: el( components.SelectControl, withModern( {
							label: __( 'Alignment', 'sheet-tables' ),
							value: current.align || 'start',
							options: [
								{ value: 'start', label: __( 'Start', 'sheet-tables' ) },
								{ value: 'center', label: __( 'Center', 'sheet-tables' ) },
								{ value: 'end', label: __( 'End', 'sheet-tables' ) },
							],
							onChange: function ( value ) {
								set( column.name, 'align', value );
							},
						} ) ),
					'list' === attributes.layout
						? null
						: el( components.SelectControl, withModern( {
							label: __( 'Width', 'sheet-tables' ),
							value: current.width || 'auto',
							options: [
								{ value: 'auto', label: __( 'Automatic', 'sheet-tables' ) },
								{ value: 'narrow', label: __( 'Narrow', 'sheet-tables' ) },
								{ value: 'medium', label: __( 'Medium', 'sheet-tables' ) },
								{ value: 'wide', label: __( 'Wide', 'sheet-tables' ) },
							],
							onChange: function ( value ) {
								set( column.name, 'width', value );
							},
						} ) ),
					// List only: a table's headings show at every width, so a
					// label is never hidden on phones alone.
					'list' !== attributes.layout
						? null
						: el( components.ToggleControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Show the column\'s label', 'sheet-tables' ),
							help: __( 'A hidden label is still read by screen readers.', 'sheet-tables' ),
							checked: false !== current.label,
							onChange: function ( value ) {
								set( column.name, 'label', value );
							},
						} )
				);
			} )
		);
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
					el( components.PanelBody, { title: __( 'Table', 'sheet-tables' ) }, picker( props ) ),
					props.attributes.id ? layoutPanel( props ) : null,
					props.attributes.id ? columnsPanel( props ) : null
				),
				body
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.serverSideRender );
