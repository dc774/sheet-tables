/**
 * Sorting and filtering for sheet tables.
 *
 * No library. The table is complete in the HTML before this runs; the script
 * only adds controls, so with JavaScript off a visitor gets the full table in
 * sheet order and no controls that do nothing.
 */
( function () {
	'use strict';

	var l10n = window.sheetTablesL10n || {};
	var collator = new Intl.Collator( undefined, { numeric: true, sensitivity: 'base' } );

	function cellText( row, index ) {
		var cell = row.cells[ index ];

		return cell ? cell.textContent.trim() : '';
	}

	// "1,200", "$15" and "40%" sort as numbers.
	function asNumber( text ) {
		var plain = text.replace( /[,$%\s]/g, '' );

		return '' !== plain && isFinite( plain ) ? parseFloat( plain ) : NaN;
	}

	// Empty cells go last whichever way the column is sorted.
	function compare( a, b, direction ) {
		if ( '' === a || '' === b ) {
			return ( '' === a ) - ( '' === b );
		}

		var x = asNumber( a );
		var y = asNumber( b );
		var result = isNaN( x ) || isNaN( y ) ? collator.compare( a, b ) : x - y;

		return result * direction;
	}

	function addSorting( table ) {
		var headers = table.tHead.rows[ 0 ].cells;
		var body = table.tBodies[ 0 ];

		Array.prototype.forEach.call( headers, function ( th, index ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'sheet-tables__sort';

			while ( th.firstChild ) {
				button.appendChild( th.firstChild );
			}
			th.appendChild( button );

			button.addEventListener( 'click', function () {
				var direction = 'ascending' === th.getAttribute( 'aria-sort' ) ? -1 : 1;
				var rows = Array.prototype.slice.call( body.rows );

				Array.prototype.forEach.call( headers, function ( other ) {
					other.removeAttribute( 'aria-sort' );
				} );
				th.setAttribute( 'aria-sort', 1 === direction ? 'ascending' : 'descending' );

				rows.sort( function ( a, b ) {
					return compare( cellText( a, index ), cellText( b, index ), direction );
				} );
				rows.forEach( function ( row ) {
					body.appendChild( row );
				} );
			} );
		} );
	}

	function addFilter( wrapper, table ) {
		var body = table.tBodies[ 0 ];
		var total = body.rows.length;
		var label = document.createElement( 'label' );
		var input = document.createElement( 'input' );
		var count = document.createElement( 'p' );

		label.className = 'sheet-tables__filter';
		label.appendChild( document.createTextNode( l10n.filter || 'Filter rows' ) );
		input.type = 'search';
		label.appendChild( input );

		// Announced to screen readers as the visible rows change.
		count.className = 'sheet-tables__count';
		count.setAttribute( 'aria-live', 'polite' );

		wrapper.insertBefore( count, wrapper.firstChild );
		wrapper.insertBefore( label, count );

		input.addEventListener( 'input', function () {
			var query = input.value.trim().toLowerCase();
			var shown = 0;

			Array.prototype.forEach.call( body.rows, function ( row ) {
				var match = '' === query || -1 !== row.textContent.toLowerCase().indexOf( query );

				row.hidden = ! match;
				shown += match ? 1 : 0;
			} );

			count.textContent = '' === query ? '' : ( l10n.count || 'Showing %1$s of %2$s rows' ).replace( '%1$s', shown ).replace( '%2$s', total );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.sheet-tables' ), function ( wrapper ) {
		var table = wrapper.querySelector( 'table' );

		if ( ! table || ! table.tHead || ! table.tBodies.length ) {
			return;
		}

		if ( wrapper.hasAttribute( 'data-sort' ) ) {
			addSorting( table );
		}

		if ( wrapper.hasAttribute( 'data-search' ) ) {
			addFilter( wrapper, table );
		}
	} );
}() );
