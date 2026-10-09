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

	function layoutPanel( props ) {
		var attributes = props.attributes;
		var isList = 'list' === attributes.layout;

		return el(
			components.PanelBody,
			{ title: __( 'Layout', 'sheet-tables' ) },
			el( components.SelectControl, withModern( {
				label: __( 'Show as', 'sheet-tables' ),
				value: attributes.layout,
				options: [
					{ value: 'table', label: __( 'Table', 'sheet-tables' ) },
					{ value: 'list', label: __( 'List', 'sheet-tables' ) },
				],
				help: isList
					? __( 'Each row becomes a list item. The first column not shown as icons is its heading; icon columns listed before it appear beside the heading.', 'sheet-tables' )
					: null,
				onChange: function ( value ) {
					props.setAttributes( { layout: value } );
				},
			} ) ),
			isList
				? el( components.SelectControl, withModern( {
					label: __( 'Heading level for each item', 'sheet-tables' ),
					value: String( attributes.headingLevel ),
					options: [ 2, 3, 4, 5, 6 ].map( function ( level ) {
						return { value: String( level ), label: 'H' + level };
					} ),
					help: __( 'Pick the level that fits under the page\'s own headings.', 'sheet-tables' ),
					onChange: function ( value ) {
						props.setAttributes( { headingLevel: parseInt( value, 10 ) } );
					},
				} ) )
				: el( components.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Keep the header row in view while scrolling', 'sheet-tables' ),
					help: __( 'The table scrolls inside a box at most as tall as the screen.', 'sheet-tables' ),
					checked: !! attributes.sticky,
					onChange: function ( value ) {
						props.setAttributes( { sticky: value } );
					},
				} )
		);
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
					el( components.SelectControl, withModern( {
						label: __( 'Show values as', 'sheet-tables' ),
						value: current.display || 'text',
						options: [
							{ value: 'text', label: __( 'Text', 'sheet-tables' ) },
							{ value: 'icons', label: __( 'Icons', 'sheet-tables' ) },
						],
						help: __( 'Icons replace values such as PDF, Word or Video, or the values listed under Custom icons on the table. Other values stay as text, and every value is still read out by screen readers.', 'sheet-tables' ),
						onChange: function ( value ) {
							set( column.name, 'display', value );
						},
					} ) ),
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
