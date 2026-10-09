/**
 * Sorting, filtering and paging for sheet tables, in both layouts.
 *
 * No library. The table or list is complete in the HTML before this runs;
 * the script only adds controls, so with JavaScript off a visitor gets every
 * row in sheet order and no controls that do nothing.
 *
 * Both layouts are handled as "items" (table rows or list items). A column's
 * value in an item is the element carrying data-col="N", and what is known
 * about each column comes from the wrapper's data-columns, so nothing here
 * depends on which layout is showing.
 *
 * Filters can be set from the address: "#program-strategy=School%20wellness"
 * picks that value in the dropdown whose heading is "Program Strategy", and
 * "#search=apple" fills the filter box. The fragment is used rather than the
 * query string because it never reaches the server, so it cannot clash with
 * WordPress's own query names (year, search, page...) or split a page cache.
 */
( function () {
	'use strict';

	var l10n = window.sheetTablesL10n || {};
	var collator = new Intl.Collator( undefined, { numeric: true, sensitivity: 'base' } );
	var PAGED_OUT = 'sheet-tables__paged-out';
	var ALT = 'sheet-tables__alt';

	function text( key, fallback ) {
		return l10n[ key ] || fallback;
	}

	function format( template, first, second ) {
		return template.replace( '%1$s', first ).replace( '%2$s', second );
	}

	function cellText( item, index ) {
		var cell = item.querySelector( '[data-col="' + index + '"]' );

		return cell ? cell.textContent.trim() : '';
	}

	// "1,200", "$15" and "40%" sort as numbers.
	function asNumber( value ) {
		var plain = value.replace( /[,$%\s]/g, '' );

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

	// A cell's separate values: "School, Worksite" is two when split on ",".
	function cellValues( item, index, separator ) {
		var value = cellText( item, index );
		var parts = separator ? value.split( separator ) : [ value ];

		return parts.map( function ( part ) {
			return part.trim();
		} ).filter( Boolean );
	}

	function readHash() {
		return new URLSearchParams( window.location.hash.replace( /^#/, '' ) );
	}

	function writeHash( values ) {
		var params = readHash();

		Object.keys( values ).forEach( function ( name ) {
			if ( values[ name ] ) {
				params.set( name, values[ name ] );
			} else {
				params.delete( name );
			}
		} );

		var hash = params.toString().replace( /\+/g, '%20' );

		// replaceState, so filtering does not fill the back button's history.
		window.history.replaceState( null, '', hash ? '#' + hash : window.location.pathname + window.location.search );
	}

	function control( className, labelText, field ) {
		var label = document.createElement( 'label' );
		var span = document.createElement( 'span' );

		label.className = 'sheet-tables__control ' + className;
		span.textContent = labelText;
		label.appendChild( span );
		label.appendChild( field );

		return label;
	}

	function setUp( wrapper ) {
		var isList = wrapper.classList.contains( 'sheet-tables--list' );
		var table = wrapper.querySelector( 'table' );
		var container = isList ? wrapper.querySelector( '.sheet-tables__list' ) : table && table.tBodies[ 0 ];
		var columns;

		try {
			columns = JSON.parse( wrapper.getAttribute( 'data-columns' ) || '[]' );
		} catch ( error ) {
			return;
		}

		if ( ! container || ( ! isList && ! table.tHead ) ) {
			return;
		}

		var original = Array.prototype.slice.call( container.children );
		var total = original.length;
		var pageSize = parseInt( wrapper.getAttribute( 'data-page-size' ), 10 ) || 0;
		var separator = wrapper.getAttribute( 'data-separator' ) || '';
		var controls = document.createElement( 'div' );
		var count = document.createElement( 'p' );
		var search = null;
		var facets = [];
		var page = 1;
		var pager = null;

		function items() {
			return Array.prototype.slice.call( container.children );
		}

		wrapper.classList.add( 'is-enhanced' );
		controls.className = 'sheet-tables__controls';

		// Announced to screen readers as the visible rows change.
		count.className = 'sheet-tables__count';
		count.setAttribute( 'aria-live', 'polite' );

		if ( wrapper.hasAttribute( 'data-search' ) ) {
			search = document.createElement( 'input' );
			search.type = 'search';
			controls.appendChild( control( 'sheet-tables__filter', text( 'filter', 'Filter rows' ), search ) );
		}

		columns.forEach( function ( column, index ) {
			if ( ! column.facet ) {
				return;
			}

			var select = document.createElement( 'select' );
			var seen = {};
			var values = [];

			original.forEach( function ( item ) {
				cellValues( item, index, separator ).forEach( function ( value ) {
					if ( ! seen[ value ] ) {
						seen[ value ] = true;
						values.push( value );
					}
				} );
			} );

			values.sort( collator.compare );
			select.add( new Option( text( 'any', 'Any' ), '' ) );
			values.forEach( function ( value ) {
				select.add( new Option( value, value ) );
			} );

			controls.appendChild( control( 'sheet-tables__facet', column.label, select ) );
			facets.push( { index: index, param: column.param, select: select, seen: seen } );
		} );

		// Set the controls from the address. A value from a link that the
		// column does not hold is ignored.
		function fromHash() {
			var hash = readHash();

			if ( search ) {
				search.value = hash.get( 'search' ) || '';
			}
			facets.forEach( function ( facet ) {
				var wanted = hash.get( facet.param );

				facet.select.value = wanted && facet.seen[ wanted ] ? wanted : '';
			} );
		}

		// Search reads the values only, so a list's labels never match.
		function matches( item ) {
			var query = search ? search.value.trim().toLowerCase() : '';

			if ( query ) {
				var values = columns.map( function ( column, index ) {
					return cellText( item, index );
				} ).join( ' ' ).toLowerCase();

				if ( -1 === values.indexOf( query ) ) {
					return false;
				}
			}

			return facets.every( function ( facet ) {
				return ! facet.select.value || -1 !== cellValues( item, facet.index, separator ).indexOf( facet.select.value );
			} );
		}

		function apply() {
			var matched = items().filter( function ( item ) {
				var match = matches( item );

				item.hidden = ! match;

				return match;
			} );
			var pages = pageSize ? Math.max( 1, Math.ceil( matched.length / pageSize ) ) : 1;
			var shown = 0;

			page = Math.min( page, pages );

			// Items on other pages are not hidden, only classed, so print can
			// still carry every row. Stripes follow what is actually visible.
			matched.forEach( function ( item, position ) {
				var pagedOut = pageSize > 0 && Math.floor( position / pageSize ) + 1 !== page;

				item.classList.toggle( PAGED_OUT, pagedOut );
				item.classList.toggle( ALT, ! pagedOut && 1 === shown++ % 2 );
			} );

			count.textContent = matched.length !== total ? format( text( 'count', 'Showing %1$s of %2$s rows' ), matched.length, total ) : '';

			if ( pager ) {
				pager.nav.hidden = pages < 2;
				pager.status.textContent = format( text( 'page', 'Page %1$s of %2$s' ), page, pages );
				pager.previous.disabled = page <= 1;
				pager.next.disabled = page >= pages;
			}
		}

		function changed() {
			var values = {};

			page = 1;
			apply();

			if ( search ) {
				values.search = search.value.trim();
			}
			facets.forEach( function ( facet ) {
				values[ facet.param ] = facet.select.value;
			} );
			writeHash( values );
		}

		function sortBy( index, direction ) {
			var sorted = index < 0 ? original.slice() : items().sort( function ( a, b ) {
				return compare( cellText( a, index ), cellText( b, index ), direction );
			} );

			sorted.forEach( function ( item ) {
				container.appendChild( item );
			} );

			// The order changed, so the pages did too.
			page = 1;
			apply();
		}

		if ( search ) {
			search.addEventListener( 'input', changed );
		}
		facets.forEach( function ( facet ) {
			facet.select.addEventListener( 'change', changed );
		} );

		if ( wrapper.hasAttribute( 'data-sort' ) && isList ) {
			// A list has no column headings to click, so it gets a dropdown.
			var order = document.createElement( 'select' );

			order.add( new Option( text( 'sheetOrder', 'Sheet order' ), '' ) );
			columns.forEach( function ( column, index ) {
				order.add( new Option( text( 'ascending', '%s, A to Z' ).replace( '%s', column.label ), index + ':1' ) );
				order.add( new Option( text( 'descending', '%s, Z to A' ).replace( '%s', column.label ), index + ':-1' ) );
			} );
			order.addEventListener( 'change', function () {
				var parts = order.value.split( ':' );

				sortBy( order.value ? parseInt( parts[ 0 ], 10 ) : -1, parseInt( parts[ 1 ], 10 ) || 1 );
			} );
			controls.appendChild( control( 'sheet-tables__order', text( 'sortBy', 'Sort by' ), order ) );
		} else if ( wrapper.hasAttribute( 'data-sort' ) ) {
			var headers = table.tHead.rows[ 0 ].cells;

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

					Array.prototype.forEach.call( headers, function ( other ) {
						other.removeAttribute( 'aria-sort' );
					} );
					th.setAttribute( 'aria-sort', 1 === direction ? 'ascending' : 'descending' );
					sortBy( index, direction );
				} );
			} );
		}

		if ( pageSize ) {
			pager = {
				nav: document.createElement( 'nav' ),
				previous: document.createElement( 'button' ),
				status: document.createElement( 'span' ),
				next: document.createElement( 'button' ),
			};
			pager.nav.className = 'sheet-tables__pager';
			pager.nav.setAttribute( 'aria-label', text( 'pages', 'Table pages' ) );
			pager.previous.type = 'button';
			pager.previous.textContent = text( 'previous', 'Previous' );
			pager.next.type = 'button';
			pager.next.textContent = text( 'next', 'Next' );
			pager.status.setAttribute( 'aria-live', 'polite' );
			pager.nav.appendChild( pager.previous );
			pager.nav.appendChild( pager.status );
			pager.nav.appendChild( pager.next );

			pager.previous.addEventListener( 'click', function () {
				page -= 1;
				apply();
			} );
			pager.next.addEventListener( 'click', function () {
				page += 1;
				apply();
			} );

			wrapper.appendChild( pager.nav );
		}

		if ( controls.firstChild ) {
			wrapper.insertBefore( controls, wrapper.firstChild );
		}
		wrapper.insertBefore( count, controls.parentNode ? controls.nextSibling : wrapper.firstChild );

		fromHash();
		apply();

		// A link on the same page, such as "#program-setting=Retail", changes
		// only the fragment, which does not reload the page.
		window.addEventListener( 'hashchange', function () {
			fromHash();
			page = 1;
			apply();
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.sheet-tables' ), setUp );
}() );
