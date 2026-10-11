/**
 * Admin behaviour for USD / Toman Pricing.
 *
 * Talks to the plugin's REST namespace with wp.apiFetch and never blocks the
 * browser: jobs run in the background queue and this script only reads state.
 *
 * Every request path is prefixed with the REST namespace that the PHP side
 * registered. wp.apiFetch only prepends the REST root (/wp-json/), so an
 * unprefixed path would ask for a route that does not exist and fail with the
 * REST "rest_no_route" error.
 */
( function () {
	'use strict';

	var data = window.usdtfData || {};
	var labels = data.labels || {};
	var strings = data.strings || {};
	var state = data.state || {};
	var pollTimer = null;

	var TEXT_DOMAIN = 'usd-to-toman-price-sync-for-woocommerce';
	var namespace = ( data.restNamespace || 'usdtf/v1' ).replace( /^\/+|\/+$/g, '' );
	var noRouteWarned = false;

	function i18n() {
		return window.wp && window.wp.i18n ? window.wp.i18n : null;
	}

	function __( text ) {
		if ( Object.prototype.hasOwnProperty.call( strings, text ) ) {
			return strings[ text ];
		}

		var api18n = i18n();

		if ( api18n && api18n.__ ) {
			return api18n.__( text, TEXT_DOMAIN ) || text;
		}

		return text;
	}

	function sprintf( text ) {
		var api18n = i18n();
		var args = Array.prototype.slice.call( arguments, 1 );

		if ( api18n && api18n.sprintf ) {
			return api18n.sprintf.apply( api18n, [ text ].concat( args ) );
		}

		return text;
	}

	function api( path, options ) {
		options = options || {};

		var headers = options.headers || {};
		headers[ 'X-WP-Nonce' ] = data.nonce;

		var method = options.method || 'GET';

		// Live job state must never come from a proxy/browser cache. The server
		// also marks the lightweight status response no-store, but sending this
		// request header protects stores behind over-aggressive admin caching.
		if ( 'GET' === method ) {
			headers[ 'Cache-Control' ] = 'no-cache';
		}
		var fullPath = '/' + namespace + '/' + String( path ).replace( /^\/+/, '' );

		return window.wp.apiFetch( {
			path: fullPath,
			method: method,
			data: options.data,
			headers: headers,
		} ).catch( function ( error ) {
			reportRequestError( error, method, fullPath );

			throw error;
		} );
	}

	/**
	 * Log every failed REST call with its exact method and path, and explain
	 * the "rest_no_route" case instead of leaving the admin to guess.
	 */
	function reportRequestError( error, method, path ) {
		var code = error && error.code ? String( error.code ) : '';
		var message = error && error.message ? error.message : '';

		if ( window.console && window.console.error ) {
			window.console.error(
				'USD to Toman Pricing: REST request ' + method + ' ' + path + ' failed' +
					( code ? ' (' + code + ')' : '' ) + ': ' + ( message || 'unknown error' )
			);
		}

		if ( 'rest_no_route' === code && ! noRouteWarned ) {
			noRouteWarned = true;

			toast(
				sprintf(
					/* translators: %s: REST route, for example GET /usdtf/v1/state. */
					__( 'The REST route %s was not found on this site. Open the Diagnostics tab to see which routes are missing, then resave the permalink settings or reinstall the plugin.', 'usd-to-toman-price-sync-for-woocommerce' ),
					method + ' ' + path
				),
				true
			);
		}
	}

	function $( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function $$( selector, scope ) {
		return Array.prototype.slice.call( ( scope || document ).querySelectorAll( selector ) );
	}

	function toast( message, isError ) {
		var box = $( '#usdtf-toast' );

		if ( ! box ) {
			window.alert( message );
			return;
		}

		box.textContent = message;
		box.classList.toggle( 'is-error', !! isError );
		box.hidden = false;

		window.setTimeout( function () {
			box.hidden = true;
		}, 8000 );
	}

	function escapeHtml( value ) {
		return String( value === undefined || value === null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function errorMessage( error ) {
		if ( error && error.message ) {
			return error.message;
		}

		return labels.genericError || __( 'Something went wrong. Please check the log for details.', 'usd-to-toman-price-sync-for-woocommerce' );
	}

	function formatNumber( value, digits ) {
		var number = Number( value || 0 );

		return number.toLocaleString( undefined, {
			minimumFractionDigits: digits || 0,
			maximumFractionDigits: digits || 0,
		} );
	}

	/**
	 * Format a regular price and its sale price as "regular / sale".
	 *
	 * @param {number|string|null} regular Regular price.
	 * @param {number|string|null} sale    Sale price.
	 * @param {number}             digits  Decimal places.
	 * @param {string}             prefix  Text put before each amount, such as "$".
	 * @return {string} An em dash when there is no price at all.
	 */
	function formatPair( regular, sale, digits, prefix ) {
		var parts = [];

		[ regular, sale ].forEach( function ( amount ) {
			if ( null !== amount && undefined !== amount && '' !== amount ) {
				parts.push( ( prefix || '' ) + formatNumber( amount, digits ) );
			}
		} );

		return parts.length ? parts.join( ' / ' ) : '—';
	}

	function priceDecimals() {
		var decimals = state.settings ? parseInt( state.settings.decimals, 10 ) : 0;

		return isNaN( decimals ) ? 0 : decimals;
	}

	function currencySupported() {
		return ! state.store_currency || !! state.store_currency.supported;
	}

	/**
	 * Why no job can start right now, or an empty string when one can: the
	 * store currency is not Toman or Rial, or the rate was saved in the other
	 * unit (the server sends the translated message).
	 */
	function jobBlocker() {
		if ( ! currencySupported() ) {
			return __( 'Set the store currency to Iranian Toman or Rial', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		return state.unit_changed ? String( state.unit_changed ) : '';
	}

	function statusLabel( status ) {
		var map = {
			changed: __( 'Updated', 'usd-to-toman-price-sync-for-woocommerce' ),
			generated: __( 'USD set', 'usd-to-toman-price-sync-for-woocommerce' ),
			rebased: __( 'Re-based', 'usd-to-toman-price-sync-for-woocommerce' ),
			unchanged: __( 'Unchanged', 'usd-to-toman-price-sync-for-woocommerce' ),
			skipped: __( 'Skipped', 'usd-to-toman-price-sync-for-woocommerce' ),
			failed: __( 'Failed', 'usd-to-toman-price-sync-for-woocommerce' ),
			conflict: __( 'Conflict', 'usd-to-toman-price-sync-for-woocommerce' ),
		};

		return Object.prototype.hasOwnProperty.call( map, status ) ? map[ status ] : String( status || '' );
	}

	function collectScope() {
		var select = $( '#usdtf-scope-mode' );
		var mode = select ? select.value : 'all';
		var scope = {
			mode: 'managed',
			ids: [],
			category: [],
			product_type: [],
			only_outdated: false,
			include_variations: true,
		};

		var includeVariations = $( '#usdtf-include-variations' );

		if ( includeVariations ) {
			scope.include_variations = includeVariations.checked;
		}

		if ( 'outdated' === mode ) {
			scope.only_outdated = true;
		}

		if ( 'category' === mode ) {
			var categories = $( '#usdtf-scope-categories' );

			if ( categories ) {
				scope.category = $$( 'option', categories ).filter( function ( option ) {
					return option.selected;
				} ).map( function ( option ) {
					return parseInt( option.value, 10 );
				} );
			}
		}

		if ( 'type' === mode ) {
			scope.product_type = $$( '.usdtf-scope-type' ).filter( function ( box ) {
				return box.checked;
			} ).map( function ( box ) {
				return box.value;
			} );
		}

		if ( 'selected' === mode ) {
			scope.ids = $$( '#usdtf-selected-products .usdtf-chip' ).map( function ( chip ) {
				return parseInt( chip.getAttribute( 'data-id' ), 10 );
			} );
		}

		return scope;
	}

	function renderProgress( job ) {
		var panel = $( '#usdtf-job-live' );

		if ( ! panel || ! job ) {
			return;
		}

		var bar = $( '.usdtf-progress__bar span', panel );
		var counters = job.counters || {};

		if ( bar ) {
			bar.style.width = job.progress + '%';
		}

		// Dynamic values (product names, status labels, user names) are added
		// with textContent, never with innerHTML, so nothing can inject HTML.
		var progress = document.createElement( 'div' );
		progress.className = 'usdtf-progress';

		var progressTrack = document.createElement( 'div' );
		var discovering = !! job.is_active &&
			( 'discover' === job.phase || 'discover_vars' === job.phase ) &&
			0 === Number( counters.total || 0 );

		progressTrack.className = 'usdtf-progress__bar' + ( discovering ? ' is-indeterminate' : '' );

		var progressFill = document.createElement( 'span' );
		progressFill.style.width = discovering ? '35%' : parseInt( job.progress, 10 ) + '%';
		progressTrack.appendChild( progressFill );
		progress.appendChild( progressTrack );

		var numbers = document.createElement( 'p' );
		numbers.className = 'usdtf-progress__numbers';
		if ( discovering ) {
			numbers.appendChild( document.createTextNode(
				( labels.working || __( 'Working…', 'usd-to-toman-price-sync-for-woocommerce' ) ) + ' · ' +
				formatNumber( job.rate ) + ' ' + ( labels.rateSuffix || '' )
			) );
		} else {
			numbers.appendChild( document.createTextNode(
				formatNumber( counters.processed ) + ' / ' + formatNumber( counters.total ) +
					' (' + parseInt( job.progress, 10 ) + '%) · ' +
					formatNumber( job.rate ) + ' ' + ( labels.rateSuffix || '' )
			) );
		}
		progress.appendChild( numbers );

		if ( job.current_item ) {
			var current = document.createElement( 'p' );
			current.className = 'usdtf-progress__current';
			current.appendChild( document.createTextNode(
				( job.is_active ? ( labels.currentProduct || __( 'Currently processing', 'usd-to-toman-price-sync-for-woocommerce' ) ) : ( labels.lastProduct || __( 'Last product', 'usd-to-toman-price-sync-for-woocommerce' ) ) ) + ': '
			) );

			var currentItem = document.createElement( 'strong' );
			currentItem.textContent = job.current_item;
			current.appendChild( currentItem );
			progress.appendChild( current );
		}

		var list = document.createElement( 'ul' );
		list.className = 'usdtf-counters';

		var counterLabels = [
			[ 'changed', __( 'Updated', 'usd-to-toman-price-sync-for-woocommerce' ) ],
			[ 'generated', __( 'USD set', 'usd-to-toman-price-sync-for-woocommerce' ) ],
			[ 'rebased', __( 'Re-based', 'usd-to-toman-price-sync-for-woocommerce' ) ],
			[ 'unchanged', __( 'Unchanged', 'usd-to-toman-price-sync-for-woocommerce' ) ],
			[ 'skipped', __( 'Skipped', 'usd-to-toman-price-sync-for-woocommerce' ) ],
			[ 'failed', __( 'Failed', 'usd-to-toman-price-sync-for-woocommerce' ) ],
			[ 'conflicts', __( 'Conflicts', 'usd-to-toman-price-sync-for-woocommerce' ) ],
			[ 'variations_processed', __( 'Variations', 'usd-to-toman-price-sync-for-woocommerce' ) ],
		];

		counterLabels.forEach( function ( entry ) {
			var item = document.createElement( 'li' );
			item.appendChild( document.createTextNode( entry[ 1 ] + ': ' ) );

			var value = document.createElement( 'strong' );
			value.textContent = formatNumber( counters[ entry[ 0 ] ] );
			item.appendChild( value );

			list.appendChild( item );
		} );

		progress.appendChild( list );

		var statusLine = document.createElement( 'p' );
		statusLine.className = 'usdtf-job-status';
		statusLine.textContent = [
			job.status_label || job.status,
			job.type_label || job.type,
			job.user || '',
		].filter( function ( part ) {
			return '' !== part;
		} ).join( ' · ' );
		progress.appendChild( statusLine );

		if ( job.message ) {
			var message = document.createElement( 'p' );
			message.className = 'usdtf-message';
			message.textContent = job.message;
			progress.appendChild( message );
		}

		var actions = document.createElement( 'p' );
		actions.className = 'usdtf-actions';

		if ( job.can_pause ) {
			var pause = document.createElement( 'button' );
			pause.type = 'button';
			pause.className = 'button usdtf-job-action';
			pause.setAttribute( 'data-action', 'pause' );
			pause.setAttribute( 'data-job-id', parseInt( job.id, 10 ) );
			pause.textContent = __( 'Pause', 'usd-to-toman-price-sync-for-woocommerce' );
			actions.appendChild( pause );
		}

		if ( job.can_resume ) {
			var resume = document.createElement( 'button' );
			resume.type = 'button';
			resume.className = 'button button-primary usdtf-job-action';
			resume.setAttribute( 'data-action', 'resume' );
			resume.setAttribute( 'data-job-id', parseInt( job.id, 10 ) );
			resume.textContent = __( 'Resume', 'usd-to-toman-price-sync-for-woocommerce' );
			actions.appendChild( resume );
		}

		if ( job.can_cancel ) {
			var cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'button usdtf-job-action';
			cancel.setAttribute( 'data-action', 'cancel' );
			cancel.setAttribute( 'data-job-id', parseInt( job.id, 10 ) );
			cancel.textContent = __( 'Cancel', 'usd-to-toman-price-sync-for-woocommerce' );
			actions.appendChild( cancel );
		}

		var details = document.createElement( 'a' );
		details.className = 'button-link';
		details.href = data.jobUrl + '&job=' + parseInt( job.id, 10 );
		details.textContent = __( 'View details', 'usd-to-toman-price-sync-for-woocommerce' );
		actions.appendChild( details );

		progress.appendChild( actions );

		panel.innerHTML = '';
		panel.appendChild( progress );
	}

	function poll( jobId ) {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
		}

		if ( ! jobId ) {
			return;
		}

		// The status endpoint is deliberately lightweight: the old polling path
		// loaded and formatted 25 product rows every 2.5 seconds even though the
		// progress card only needs the job summary. The timestamp also defeats
		// intermediary caches that ignore response cache headers.
		api( '/jobs/' + jobId + '/status?_usdtf=' + Date.now() ).then( function ( job ) {
			renderProgress( job );

			if ( job.is_active ) {
				pollTimer = window.setTimeout( function () {
					poll( jobId );
				}, 2500 );

				return;
			}

			if ( 'preview' === job.type ) {
				toast( labels.previewFinished || __( 'Dry run finished. Nothing was changed.', 'usd-to-toman-price-sync-for-woocommerce' ), 'completed_with_errors' === job.status || 'failed' === job.status );
			} else {
				toast(
					sprintf(
						/* translators: 1: job ID, 2: job status. */
						__( 'Job #%1$d finished: %2$s', 'usd-to-toman-price-sync-for-woocommerce' ),
						parseInt( job.id, 10 ),
						job.status_label || job.status
					),
					'completed_with_errors' === job.status || 'failed' === job.status
				);
			}

			window.setTimeout( function () {
				window.location.reload();
			}, 1200 );
		} ).catch( function () {
			pollTimer = window.setTimeout( function () {
				poll( jobId );
			}, 5000 );
		} );
	}

	function startJob( path, extra ) {
		var payload = { scope: collectScope() };

		if ( jobBlocker() ) {
			toast( jobBlocker(), true );

			return Promise.resolve( null );
		}

		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				payload[ key ] = extra[ key ];
			} );
		}

		toast( labels.working || __( 'Working…', 'usd-to-toman-price-sync-for-woocommerce' ) );

		return api( path, { method: 'POST', data: payload } ).then( function ( response ) {
			var job = response && response.id ? response : ( response && response.job ? response.job : null );

			toast( labels.jobStarted || __( 'The background job was queued.', 'usd-to-toman-price-sync-for-woocommerce' ) );

			if ( job && job.id ) {
				renderProgress( job );
				poll( job.id );
			} else {
				window.setTimeout( function () {
					window.location.reload();
				}, 1200 );
			}

			return response;
		} ).catch( function ( error ) {
			var running = runningJobFromError( error );

			if ( running ) {
				// Only one write job may run at a time: surface the job that is
				// already queued, with its rate, its progress and its controls.
				toast( ( labels.alreadyRunning || __( 'Another price update is already running. Showing it instead.', 'usd-to-toman-price-sync-for-woocommerce' ) ) +
					' #' + running.id + ' · ' + formatNumber( running.rate ) + ' · ' + running.progress + '%' );

				renderProgress( running );
				poll( running.id );

				return null;
			}

			toast( errorMessage( error ), true );

			throw error;
		} );
	}

	function runningJobFromError( error ) {
		var payload = error && error.data ? error.data : null;
		var job = payload && payload.job ? payload.job : null;

		return job && job.id ? job : null;
	}

	function showConfirmation( result ) {
		var box = $( '#usdtf-confirm' );

		if ( ! box ) {
			return;
		}

		var text = $( '.usdtf-confirm__text', box );

		if ( text ) {
			text.textContent = sprintf(
				/* translators: 1: previous rate, 2: new rate, 3: change in percent, 4: number of affected products. */
				__( 'Rate %1$s → %2$s (%3$s%%). Managed products that would be affected: about %4$s.', 'usd-to-toman-price-sync-for-woocommerce' ),
				formatNumber( result.previous_rate ),
				formatNumber( result.rate ),
				formatNumber( result.change_percent, 2 ),
				formatNumber( result.affected )
			) + ' ' + ( result.message || '' );
		}

		box.hidden = false;
	}

	function initRateForm() {
		var form = $( '#usdtf-rate-form' );

		if ( ! form ) {
			return;
		}

		var input = $( '#usdtf-rate-input' );

		function save( confirmed ) {
			var value = input ? input.value : '';

			if ( ! value ) {
				toast( __( 'Enter a rate first.', 'usd-to-toman-price-sync-for-woocommerce' ), true );

				return;
			}

			api( '/rate', {
				method: 'POST',
				data: {
					rate: value,
					confirmed: !! confirmed,
					confirm_text: confirmed ? ( $( '#usdtf-confirm-input' ) || {} ).value : '',
				},
			} ).then( function ( result ) {
				if ( result.requires_confirmation ) {
					showConfirmation( result );

					return;
				}

				toast( result.message || labels.savedRate || __( 'Exchange rate saved.', 'usd-to-toman-price-sync-for-woocommerce' ) );

				window.setTimeout( function () {
					window.location.reload();
				}, 1000 );
			} ).catch( function ( error ) {
				toast( errorMessage( error ), true );
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			save( false );
		} );

		var confirmButton = $( '#usdtf-confirm-rate' );

		if ( confirmButton ) {
			confirmButton.addEventListener( 'click', function () {
				save( true );
			} );
		}

		var cancelButton = $( '#usdtf-cancel-confirm' );

		if ( cancelButton ) {
			cancelButton.addEventListener( 'click', function () {
				var box = $( '#usdtf-confirm' );

				if ( box ) {
					box.hidden = true;
				}
			} );
		}

		var previewRate = $( '#usdtf-preview-rate' );

		if ( previewRate ) {
			previewRate.addEventListener( 'click', function () {
				var value = input ? input.value : '';

				if ( ! value ) {
					toast( __( 'Enter a rate to preview.', 'usd-to-toman-price-sync-for-woocommerce' ), true );

					return;
				}

				startJob( '/preview', { rate: value } );
			} );
		}
	}

	function initScope() {
		var select = $( '#usdtf-scope-mode' );

		if ( ! select ) {
			return;
		}

		function refresh() {
			$$( '.usdtf-scope-row' ).forEach( function ( row ) {
				row.hidden = row.getAttribute( 'data-scope' ) !== select.value;
			} );
		}

		select.addEventListener( 'change', refresh );
		refresh();

		var search = $( '#usdtf-product-search' );
		var timer = null;

		if ( search ) {
			search.addEventListener( 'input', function () {
				window.clearTimeout( timer );

				timer = window.setTimeout( function () {
					var term = search.value.trim();

					if ( term.length < 2 ) {
						return;
					}

					api( '/products?search=' + encodeURIComponent( term ) ).then( function ( results ) {
						var list = $( '#usdtf-search-results' );

						if ( ! list ) {
							return;
						}

						list.innerHTML = '';

						results.forEach( function ( product ) {
							var item = document.createElement( 'li' );
							var button = document.createElement( 'button' );

							button.type = 'button';
							button.className = 'button-link';

							// Product names come from the database and may contain
							// markup: textContent keeps them plain text.
							button.appendChild( document.createTextNode( product.name || labels.unknownProduct || __( 'Unknown product', 'usd-to-toman-price-sync-for-woocommerce' ) ) );
							button.appendChild( document.createTextNode( ' ' ) );

							var badge = document.createElement( 'span' );
							badge.className = 'usdtf-badge';
							badge.textContent = product.mode;
							button.appendChild( badge );

							button.addEventListener( 'click', function () {
								addChip( product );
								list.innerHTML = '';
								search.value = '';
							} );

							item.appendChild( button );
							list.appendChild( item );
						} );
					} ).catch( function ( error ) {
						toast( errorMessage( error ), true );
					} );
				}, 350 );
			} );
		}

		function addChip( product ) {
			var list = $( '#usdtf-selected-products' );

			if ( ! list || $( '.usdtf-chip[data-id="' + product.id + '"]' ) ) {
				return;
			}

			var chip = document.createElement( 'li' );

			chip.className = 'usdtf-chip';
			chip.setAttribute( 'data-id', product.id );

			var name = document.createElement( 'span' );
			name.textContent = product.name || labels.unknownProduct || __( 'Unknown product', 'usd-to-toman-price-sync-for-woocommerce' );
			chip.appendChild( name );

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'usdtf-chip__remove';
			remove.setAttribute( 'aria-label', __( 'Remove', 'usd-to-toman-price-sync-for-woocommerce' ) );
			remove.textContent = '×';
			remove.addEventListener( 'click', function () {
				chip.remove();
			} );

			chip.appendChild( remove );
			list.appendChild( chip );
		}
	}

	function initButtons() {
		var update = $( '#usdtf-start-update' );

		if ( update ) {
			update.addEventListener( 'click', function () {
				var scope = collectScope();

				if ( 'selected' === ( $( '#usdtf-scope-mode' ) || {} ).value && ! scope.ids.length ) {
					toast( __( 'Select at least one product first.', 'usd-to-toman-price-sync-for-woocommerce' ), true );

					return;
				}

				var message = __( 'Start the background price update now?', 'usd-to-toman-price-sync-for-woocommerce' );

				if ( state.preview_required && ! state.preview_ok ) {
					message += ' ' + __( 'A dry run with the same rate, rounding settings and scope is required first.', 'usd-to-toman-price-sync-for-woocommerce' );
				}

				if ( window.confirm( message ) ) {
					startJob( '/update' );
				}
			} );
		}

		var preview = $( '#usdtf-start-preview' );

		if ( preview ) {
			preview.addEventListener( 'click', function () {
				startJob( '/preview' );
			} );
		}

		var rollback = $( '#usdtf-rollback' );

		if ( rollback ) {
			rollback.addEventListener( 'click', function () {
				if ( ! window.confirm( __( 'Restore the previous exchange rate and recalculate all managed products?', 'usd-to-toman-price-sync-for-woocommerce' ) ) ) {
					return;
				}

				api( '/rollback', { method: 'POST' } ).then( function ( response ) {
					toast( labels.jobStarted || __( 'The background job was queued.', 'usd-to-toman-price-sync-for-woocommerce' ) );

					if ( response && response.id ) {
						poll( response.id );
					} else {
						window.location.reload();
					}
				} ).catch( function ( error ) {
					toast( errorMessage( error ), true );
				} );
			} );
		}

		var loopback = $( '#usdtf-loopback-test' );

		if ( loopback ) {
			loopback.addEventListener( 'click', function () {
				var result = $( '#usdtf-loopback-result' );

				if ( result ) {
					result.textContent = labels.working || __( 'Working…', 'usd-to-toman-price-sync-for-woocommerce' );
				}

				api( '/health/loopback', { method: 'POST' } ).then( function ( response ) {
					if ( result ) {
						result.textContent = ( response.ok ? __( 'OK', 'usd-to-toman-price-sync-for-woocommerce' ) : __( 'Failed', 'usd-to-toman-price-sync-for-woocommerce' ) ) +
							' — HTTP ' + response.status + ' in ' + response.duration + 'ms. ' + response.description;
					}
				} ).catch( function ( error ) {
					if ( result ) {
						result.textContent = errorMessage( error );
					}
				} );
			} );
		}
	}

	function initJobActions() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.usdtf-job-action' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			var action = button.getAttribute( 'data-action' );
			var jobId = parseInt( button.getAttribute( 'data-job-id' ), 10 );

			if ( 'cancel' === action && ! window.confirm( __( 'Cancel this job? Prices that were already written are kept.', 'usd-to-toman-price-sync-for-woocommerce' ) ) ) {
				return;
			}

			button.disabled = true;

			api( '/jobs/' + jobId + '/' + action, { method: 'POST' } ).then( function ( response ) {
				toast( '#' + jobId + ': ' + action + ' — ' + ( response.message || __( 'Done.', 'usd-to-toman-price-sync-for-woocommerce' ) ) );

				if ( response.job && response.job.is_active ) {
					poll( response.job.id );
				} else {
					window.setTimeout( function () {
						window.location.reload();
					}, 1000 );
				}
			} ).catch( function ( error ) {
				button.disabled = false;
				toast( errorMessage( error ), true );
			} );
		} );
	}

	/**
	 * Jobs are refused while the store currency is not Toman or Rial, or while
	 * the rate was saved in the other unit, so the buttons that start one stay
	 * disabled. The server renders them disabled too; this also covers the
	 * rate form's preview button.
	 */
	function initCurrencyGuard() {
		if ( ! jobBlocker() ) {
			return;
		}

		[ '#usdtf-start-update', '#usdtf-start-preview', '#usdtf-preview-rate', '#usdtf-rollback' ].forEach( function ( selector ) {
			var button = $( selector );

			if ( button ) {
				button.disabled = true;
			}
		} );
	}

	/**
	 * One row of the items table, built with textContent only.
	 *
	 * Columns: Product, Status, Current Woo price, USD price, New Woo price, Details.
	 */
	function itemRow( item ) {
		var row = document.createElement( 'tr' );
		var decimals = priceDecimals();

		var product = document.createElement( 'td' );
		var name = item.name || sprintf(
			/* translators: %d: product ID. */
			__( 'Product #%d', 'usd-to-toman-price-sync-for-woocommerce' ),
			parseInt( item.object_id, 10 )
		);

		if ( item.edit_link ) {
			var link = document.createElement( 'a' );
			link.href = item.edit_link;
			link.textContent = name;
			product.appendChild( link );
		} else {
			product.appendChild( document.createTextNode( name ) );
		}

		if ( parseInt( item.parent_id, 10 ) > 0 ) {
			var badge = document.createElement( 'span' );
			badge.className = 'usdtf-badge';
			badge.textContent = __( 'variation', 'usd-to-toman-price-sync-for-woocommerce' );
			product.appendChild( document.createTextNode( ' ' ) );
			product.appendChild( badge );
		}

		row.appendChild( product );

		var status = document.createElement( 'td' );
		var pill = document.createElement( 'span' );
		pill.className = 'usdtf-status usdtf-status--' + String( item.status || '' ).replace( /[^a-z_-]/g, '' );
		pill.textContent = item.status_label || statusLabel( item.status );
		status.appendChild( pill );
		row.appendChild( status );

		var current = document.createElement( 'td' );
		current.textContent = formatPair( item.old_regular, item.old_sale, decimals );
		row.appendChild( current );

		var usd = document.createElement( 'td' );
		usd.textContent = formatPair( item.usd_regular, item.usd_sale, 2, '$' );
		row.appendChild( usd );

		var next = document.createElement( 'td' );
		var strong = document.createElement( 'strong' );
		strong.textContent = formatPair( item.new_regular, item.new_sale, decimals );
		next.appendChild( strong );
		row.appendChild( next );

		var details = document.createElement( 'td' );
		details.appendChild( document.createTextNode( item.message || '' ) );

		var notes = [];

		if ( parseInt( item.child_total, 10 ) > 0 ) {
			notes.push( sprintf(
				/* translators: 1: processed variations, 2: total variations. */
				__( 'Variations processed: %1$d / %2$d', 'usd-to-toman-price-sync-for-woocommerce' ),
				parseInt( item.child_cursor, 10 ),
				parseInt( item.child_total, 10 )
			) );
		}

		if ( parseInt( item.attempts, 10 ) > 0 ) {
			notes.push( sprintf(
				/* translators: %d: attempts. */
				__( 'Attempts: %d', 'usd-to-toman-price-sync-for-woocommerce' ),
				parseInt( item.attempts, 10 )
			) );
		}

		notes.forEach( function ( note ) {
			var line = document.createElement( 'span' );
			line.className = 'description';
			line.textContent = note;
			details.appendChild( document.createElement( 'br' ) );
			details.appendChild( line );
		} );

		row.appendChild( details );

		return row;
	}

	function renderItems( table, items ) {
		var body = $( 'tbody', table );

		if ( ! body ) {
			return;
		}

		body.innerHTML = '';

		if ( ! items.length ) {
			var empty = document.createElement( 'tr' );
			var cell = document.createElement( 'td' );
			cell.colSpan = 6;
			cell.textContent = __( 'No items match this filter.', 'usd-to-toman-price-sync-for-woocommerce' );
			empty.appendChild( cell );
			body.appendChild( empty );

			return;
		}

		items.forEach( function ( item ) {
			body.appendChild( itemRow( item ) );
		} );
	}

	/**
	 * Status tabs of a job's items: All, Updated, USD set, Re-based,
	 * Unchanged, Skipped, Failed, Conflicts. The links work without
	 * JavaScript; with it, the table is reloaded in place over REST.
	 */
	function initStatusTabs() {
		var tabs = $( '.usdtf-status-tabs' );

		if ( ! tabs ) {
			return;
		}

		var jobId = parseInt( tabs.getAttribute( 'data-job-id' ), 10 );
		var table = jobId ? $( '.usdtf-items-table[data-job-id="' + jobId + '"]' ) : null;

		if ( ! table ) {
			return;
		}

		tabs.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[data-status]' );

			if ( ! link ) {
				return;
			}

			event.preventDefault();

			var status = link.getAttribute( 'data-status' ) || '';

			api( '/jobs/' + jobId + '?items_per_page=50&items_status=' + encodeURIComponent( status ) + '&_usdtf=' + Date.now() ).then( function ( job ) {
				renderItems( table, job && job.items ? job.items : [] );

				$$( 'a[data-status]', tabs ).forEach( function ( other ) {
					other.classList.toggle( 'is-current', other === link );
				} );

				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', link.href );
				}
			} ).catch( function ( error ) {
				toast( errorMessage( error ), true );
			} );
		} );
	}

	function initPolling() {
		if ( state && state.active_job && state.active_job.id ) {
			poll( state.active_job.id );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initRateForm();
		initScope();
		initButtons();
		initJobActions();
		initCurrencyGuard();
		initStatusTabs();
		initPolling();
	} );
}() );
