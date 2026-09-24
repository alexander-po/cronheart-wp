/**
 * Cronheart admin — monitor lifecycle, per-event mapping, channels.
 *
 * Enqueued on the Cronheart admin screens. Wires:
 *   - the "Your monitors" table (Settings → Cronheart): pause / resume /
 *     snooze / unsnooze;
 *   - the "Cron events" table (Settings → Cronheart Events): assign a monitor
 *     to a hook, or auto-create one;
 *   - the "Channels" table (Settings → Cronheart Channels): send a test alert
 *     through a channel, or rotate a webhook channel's signing secret.
 * Each posts to the authenticated admin-AJAX endpoint and applies the result.
 *
 * Every string the server returns (status label, snooze deadline, mapping
 * label, monitor name, error message) is written with textContent — never
 * innerHTML — so an API value cannot inject markup. The script's own
 * user-facing strings come pre-translated via wp_localize_script
 * (cronheartAdmin.i18n).
 */
( function () {
	'use strict';

	var cfg = window.cronheartAdmin;
	if ( ! cfg || ! cfg.ajaxUrl || ! cfg.actions ) {
		return;
	}

	function setText( row, selector, text ) {
		var cell = row.querySelector( selector );
		if ( cell ) {
			cell.textContent = text;
		}
	}

	function post( body ) {
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// ── Monitors table (lifecycle) ──────────────────────────────────────

	function button( row, op ) {
		return row.querySelector( '.cronheart-action[data-cronheart-op="' + op + '"]' );
	}

	function show( el, visible ) {
		if ( el ) {
			el.hidden = ! visible;
		}
	}

	function applyState( row ) {
		var paused = row.getAttribute( 'data-cronheart-status' ) === 'paused';
		var snoozed = row.getAttribute( 'data-cronheart-snoozed' ) === '1';
		show( button( row, 'pause' ), ! paused );
		show( button( row, 'resume' ), paused );
		show( button( row, 'unsnooze' ), snoozed );
	}

	function setBusy( row, busy ) {
		var controls = row.querySelectorAll( '.cronheart-action, .cronheart-snooze-duration' );
		for ( var i = 0; i < controls.length; i++ ) {
			controls[ i ].disabled = busy;
		}
	}

	function feedback( row, message ) {
		setText( row, '.cronheart-action-feedback', message );
	}

	function applyResult( row, data ) {
		row.setAttribute( 'data-cronheart-status', data.status );
		row.setAttribute( 'data-cronheart-snoozed', data.snoozed ? '1' : '0' );
		setText( row, '.cronheart-monitor-status', data.status_label );
		setText( row, '.cronheart-monitor-snooze', data.snoozed_until || '—' );
		applyState( row );
		feedback( row, '' );
	}

	function onMonitorClick( event ) {
		var btn = event.target.closest ? event.target.closest( '.cronheart-action' ) : null;
		if ( ! btn ) {
			return;
		}
		var row = btn.closest( '[data-cronheart-uuid]' );
		if ( ! row ) {
			return;
		}
		event.preventDefault();

		var op = btn.getAttribute( 'data-cronheart-op' );
		var body = new URLSearchParams();
		body.set( 'action', cfg.actions.monitor );
		body.set( 'nonce', cfg.nonce );
		body.set( 'op', op );
		body.set( 'uuid', row.getAttribute( 'data-cronheart-uuid' ) );
		if ( op === 'snooze' ) {
			var duration = row.querySelector( '.cronheart-snooze-duration' );
			body.set( 'duration', duration ? duration.value : '' );
		}

		setBusy( row, true );
		feedback( row, cfg.i18n.working );

		post( body ).then( function ( payload ) {
			if ( payload && payload.success && payload.data ) {
				applyResult( row, payload.data );
			} else {
				feedback( row, ( payload && payload.data && payload.data.message ) || cfg.i18n.error );
			}
		} ).catch( function () {
			feedback( row, cfg.i18n.error );
		} ).then( function () {
			setBusy( row, false );
		} );
	}

	// ── Cron-events table (per-event mapping) ───────────────────────────

	function eventRow( el ) {
		return el.closest ? el.closest( '[data-cronheart-hook]' ) : null;
	}

	function eventBusy( row, busy ) {
		var controls = row.querySelectorAll( '.cronheart-event-monitor, .cronheart-event-create' );
		for ( var i = 0; i < controls.length; i++ ) {
			controls[ i ].disabled = busy;
		}
	}

	function postEvent( row, action, extra, busyMessage ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'hook', row.getAttribute( 'data-cronheart-hook' ) );
		Object.keys( extra ).forEach( function ( key ) {
			body.set( key, extra[ key ] );
		} );

		eventBusy( row, true );
		setText( row, '.cronheart-event-feedback', busyMessage );

		return post( body ).then( function ( payload ) {
			if ( payload && payload.success && payload.data ) {
				setText( row, '.cronheart-event-mapping', payload.data.label || '' );
				setText( row, '.cronheart-event-feedback', '' );
				return payload.data;
			}
			setText( row, '.cronheart-event-feedback', ( payload && payload.data && payload.data.message ) || cfg.i18n.error );
			return null;
		} ).catch( function () {
			setText( row, '.cronheart-event-feedback', cfg.i18n.error );
			return null;
		} ).then( function ( data ) {
			eventBusy( row, false );
			return data;
		} );
	}

	function onEventChange( event ) {
		var select = event.target;
		if ( ! select.classList || ! select.classList.contains( 'cronheart-event-monitor' ) ) {
			return;
		}
		var row = eventRow( select );
		if ( row ) {
			postEvent( row, cfg.actions.mapEvent, { uuid: select.value }, cfg.i18n.saving );
		}
	}

	function onEventClick( event ) {
		var btn = event.target.closest ? event.target.closest( '.cronheart-event-create' ) : null;
		if ( ! btn ) {
			return;
		}
		var row = eventRow( btn );
		if ( ! row ) {
			return;
		}
		event.preventDefault();

		postEvent( row, cfg.actions.createEvent, {}, cfg.i18n.creating ).then( function ( data ) {
			if ( ! data || ! data.uuid ) {
				return;
			}
			// Reflect the freshly-created monitor: add it to this row's
			// dropdown, select it, and retire the create button.
			var select = row.querySelector( '.cronheart-event-monitor' );
			if ( select ) {
				var option = document.createElement( 'option' );
				option.value = data.uuid;
				option.textContent = ( data.name ? data.name + ' — ' : '' ) + data.uuid;
				select.appendChild( option );
				select.value = data.uuid;
			}
			btn.hidden = true;
		} );
	}

	// ── Channels table (test / rotate secret) ───────────────────────────

	function channelRow( el ) {
		return el.closest ? el.closest( '[data-cronheart-channel-id]' ) : null;
	}

	function channelBusy( row, busy ) {
		var controls = row.querySelectorAll( '.cronheart-channel-test, .cronheart-channel-rotate' );
		for ( var i = 0; i < controls.length; i++ ) {
			controls[ i ].disabled = busy;
		}
	}

	function postChannel( row, action, busyMessage ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'channel_id', row.getAttribute( 'data-cronheart-channel-id' ) );

		channelBusy( row, true );
		setText( row, '.cronheart-channel-feedback', busyMessage );

		return post( body ).then( function ( payload ) {
			if ( payload && payload.success && payload.data ) {
				setText( row, '.cronheart-channel-feedback', payload.data.message || '' );
				return payload.data;
			}
			setText( row, '.cronheart-channel-feedback', ( payload && payload.data && payload.data.message ) || cfg.i18n.error );
			return null;
		} ).catch( function () {
			setText( row, '.cronheart-channel-feedback', cfg.i18n.error );
			return null;
		} ).then( function ( data ) {
			channelBusy( row, false );
			return data;
		} );
	}

	function revealSecret( row, secret ) {
		var reveal = row.querySelector( '.cronheart-channel-secret' );
		if ( ! reveal ) {
			return;
		}
		var value = reveal.querySelector( '.cronheart-channel-secret-value' );
		if ( value ) {
			value.textContent = secret;
		}
		reveal.hidden = false;
	}

	function onChannelClick( event ) {
		var closest = event.target.closest ? event.target.closest.bind( event.target ) : null;
		var testBtn = closest ? closest( '.cronheart-channel-test' ) : null;
		var rotateBtn = closest ? closest( '.cronheart-channel-rotate' ) : null;
		if ( ! testBtn && ! rotateBtn ) {
			return;
		}
		var row = channelRow( testBtn || rotateBtn );
		if ( ! row ) {
			return;
		}
		event.preventDefault();

		if ( testBtn ) {
			postChannel( row, cfg.actions.testChannel, cfg.i18n.testing ).then( function ( data ) {
				if ( data && data.newly_verified ) {
					setText( row, '.cronheart-channel-verified', cfg.i18n.verified );
					row.setAttribute( 'data-cronheart-channel-verified', '1' );
				}
			} );
			return;
		}

		// Rotating invalidates the current secret immediately — confirm first.
		if ( cfg.i18n.rotateConfirm && ! window.confirm( cfg.i18n.rotateConfirm ) ) {
			return;
		}
		postChannel( row, cfg.actions.rotateChannelSecret, cfg.i18n.rotating ).then( function ( data ) {
			if ( data && data.secret ) {
				revealSecret( row, data.secret );
			}
		} );
	}

	function ready() {
		var monitorsTable = document.querySelector( '.cronheart-monitors' );
		if ( monitorsTable ) {
			var rows = monitorsTable.querySelectorAll( '[data-cronheart-uuid]' );
			for ( var i = 0; i < rows.length; i++ ) {
				applyState( rows[ i ] );
			}
			monitorsTable.addEventListener( 'click', onMonitorClick );
		}

		var eventsTable = document.querySelector( '.cronheart-events' );
		if ( eventsTable ) {
			eventsTable.addEventListener( 'change', onEventChange );
			eventsTable.addEventListener( 'click', onEventClick );
		}

		var channelsTable = document.querySelector( '.cronheart-channels' );
		if ( channelsTable ) {
			channelsTable.addEventListener( 'click', onChannelClick );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
