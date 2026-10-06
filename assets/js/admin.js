/**
 * Admin behaviour for USD / Toman Pricing.
 *
 * Talks to the plugin's REST namespace with wp.apiFetch and never blocks the
 * browser: jobs run in the background queue and this script only reads state.
 */
( function () {
	'use strict';

	var data = window.usdtfData || {};
	var labels = data.labels || {};
	var state = data.state || {};
	var pollTimer = null;

	function api( path, options ) {
		options = options || {};

		var headers = options.headers || {};
		headers[ 'X-WP-Nonce' ] = data.nonce;

		return window.wp.apiFetch( {
			path: path,
			method: options.method || 'GET',
			data: options.data,
			headers: headers,
		} );
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

		return labels.genericError || 'Error';
	}

	function formatNumber( value, digits ) {
		var number = Number( value || 0 );

		return number.toLocaleString( undefined, {
			minimumFractionDigits: digits || 0,
			maximumFractionDigits: digits || 0,
		} );
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

		var current = job.current_item ?
			'<p class="usdtf-progress__current">' +
			( job.is_active ? ( labels.currentProduct || 'Currently processing' ) : ( labels.lastProduct || 'Last product' ) ) +
			': <strong>' + escapeHtml( job.current_item ) + '</strong></p>' :
			'';

		panel.innerHTML =
			'<div class="usdtf-progress">' +
			'<div class="usdtf-progress__bar"><span style="width:' + job.progress + '%"></span></div>' +
			'<p class="usdtf-progress__numbers">' +
			formatNumber( counters.processed ) + ' / ' + formatNumber( counters.total ) + ' (' + job.progress + '%) · ' +
			formatNumber( job.rate ) + ' ' + ( labels.rateSuffix || 'Toman / USD' ) +
			'</p>' +
			current +
			'<ul class="usdtf-counters">' +
			'<li>Changed: <strong>' + formatNumber( counters.changed ) + '</strong></li>' +
			'<li>Unchanged: <strong>' + formatNumber( counters.unchanged ) + '</strong></li>' +
			'<li>Skipped: <strong>' + formatNumber( counters.skipped ) + '</strong></li>' +
			'<li>Failed: <strong>' + formatNumber( counters.failed ) + '</strong></li>' +
			'<li>Conflicts: <strong>' + formatNumber( counters.conflicts ) + '</strong></li>' +
			'<li>Variations: <strong>' + formatNumber( counters.variations_processed ) + '</strong></li>' +
			'</ul>' +
			'<p class="usdtf-job-status">' + job.status + ' · ' + job.type + ' · ' + ( job.user || '' ) + '</p>' +
			'<p class="usdtf-actions">' +
			( job.can_pause ? '<button type="button" class="button usdtf-job-action" data-action="pause" data-job-id="' + job.id + '">Pause</button> ' : '' ) +
			( job.can_resume ? '<button type="button" class="button button-primary usdtf-job-action" data-action="resume" data-job-id="' + job.id + '">Resume</button> ' : '' ) +
			( job.can_cancel ? '<button type="button" class="button usdtf-job-action" data-action="cancel" data-job-id="' + job.id + '">Cancel</button> ' : '' ) +
			'<a class="button-link" href="' + data.jobUrl + '&job=' + job.id + '">View details</a>' +
			'</p>' +
			'</div>';
	}

	function poll( jobId ) {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
		}

		if ( ! jobId ) {
			return;
		}

		api( '/jobs/' + jobId ).then( function ( job ) {
			renderProgress( job );

			if ( job.is_active ) {
				pollTimer = window.setTimeout( function () {
					poll( jobId );
				}, 2500 );

				return;
			}

			toast( job.type === 'preview' ? ( labels.previewFinished || 'Dry run finished.' ) : ( 'Job #' + job.id + ' finished: ' + job.status ), 'completed_with_errors' === job.status || 'failed' === job.status );

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

		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				payload[ key ] = extra[ key ];
			} );
		}

		toast( labels.working || 'Working…' );

		return api( path, { method: 'POST', data: payload } ).then( function ( response ) {
			var job = response && response.id ? response : ( response && response.job ? response.job : null );

			toast( labels.jobStarted || 'Job queued.' );

			if ( job && job.id ) {
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
				toast( ( labels.alreadyRunning || 'Another price update is already running.' ) +
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
			text.textContent = 'Rate ' + formatNumber( result.previous_rate ) + ' → ' + formatNumber( result.rate ) +
				' (' + formatNumber( result.change_percent, 2 ) + '%). Managed products that would be affected: about ' +
				formatNumber( result.affected ) + '. ' + ( result.message || '' );
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
				toast( 'Enter a rate first.', true );

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

				toast( result.message || labels.savedRate );

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
					toast( 'Enter a rate to preview.', true );

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

							item.innerHTML = '<button type="button" class="button-link">' +
								( product.name || labels.unknownProduct ) + ' <span class="usdtf-badge">' + product.mode + '</span></button>';

							$( 'button', item ).addEventListener( 'click', function () {
								addChip( product );
								list.innerHTML = '';
								search.value = '';
							} );

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
			chip.innerHTML = '<span>' + ( product.name || labels.unknownProduct ) + '</span> ' +
				'<button type="button" class="usdtf-chip__remove" aria-label="Remove">&times;</button>';

			$( '.usdtf-chip__remove', chip ).addEventListener( 'click', function () {
				chip.remove();
			} );

			list.appendChild( chip );
		}
	}

	function initButtons() {
		var update = $( '#usdtf-start-update' );

		if ( update ) {
			update.addEventListener( 'click', function () {
				var scope = collectScope();

				if ( 'selected' === ( $( '#usdtf-scope-mode' ) || {} ).value && ! scope.ids.length ) {
					toast( 'Select at least one product first.', true );

					return;
				}

				if ( window.confirm( 'Start the background price update now?' ) ) {
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
				if ( ! window.confirm( 'Restore the previous exchange rate and recalculate all managed products?' ) ) {
					return;
				}

				api( '/rollback', { method: 'POST' } ).then( function ( response ) {
					toast( labels.jobStarted || 'Job queued.' );

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
					result.textContent = labels.working || 'Working…';
				}

				api( '/health/loopback', { method: 'POST' } ).then( function ( response ) {
					if ( result ) {
						result.textContent = ( response.ok ? 'OK' : 'Failed' ) + ' — HTTP ' + response.status + ' in ' + response.duration + 'ms. ' + response.description;
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

			if ( 'cancel' === action && ! window.confirm( 'Cancel this job? Prices that were already written are kept.' ) ) {
				return;
			}

			button.disabled = true;

			api( '/jobs/' + jobId + '/' + action, { method: 'POST' } ).then( function ( response ) {
				toast( '#' + jobId + ': ' + action + ' — ' + ( response.message || 'done' ) );

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

	function initProductPanel() {
		var select = $( '#usdtf-mode' );

		if ( ! select ) {
			return;
		}

		function refresh() {
			var managed = 'managed' === select.value;

			[ '#usdtf-source-regular', '#usdtf-source-sale' ].forEach( function ( selector ) {
				var field = $( selector );

				if ( field ) {
					field.disabled = ! managed;
				}
			} );
		}

		select.addEventListener( 'change', refresh );
		refresh();
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
		initProductPanel();
		initPolling();
	} );
}() );
