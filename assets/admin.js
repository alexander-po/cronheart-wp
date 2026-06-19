/**
 * Cronheart admin — monitor lifecycle actions.
 *
 * Enqueued only on the Settings → Cronheart screen. Wires the pause /
 * resume / snooze / unsnooze buttons in the "Your monitors" table to the
 * authenticated admin-AJAX endpoint and applies the returned state.
 *
 * Every string the server returns (status label, snooze deadline, error
 * message) is written with textContent — never innerHTML — so a value from
 * the API cannot inject markup. User-facing strings the script owns come
 * pre-translated from wp_localize_script (cronheartAdmin.i18n).
 */
( function () {
	'use strict';

	var cfg = window.cronheartAdmin;
	if ( ! cfg || ! cfg.ajaxUrl || ! cfg.action ) {
		return;
	}

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

	function setText( row, selector, text ) {
		var cell = row.querySelector( selector );
		if ( cell ) {
			cell.textContent = text;
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

	function onClick( event ) {
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
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'op', op );
		body.set( 'uuid', row.getAttribute( 'data-cronheart-uuid' ) );
		if ( op === 'snooze' ) {
			var duration = row.querySelector( '.cronheart-snooze-duration' );
			body.set( 'duration', duration ? duration.value : '' );
		}

		setBusy( row, true );
		feedback( row, cfg.i18n.working );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
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

	function ready() {
		var table = document.querySelector( '.cronheart-monitors' );
		if ( ! table ) {
			return;
		}
		var rows = table.querySelectorAll( '[data-cronheart-uuid]' );
		for ( var i = 0; i < rows.length; i++ ) {
			applyState( rows[ i ] );
		}
		table.addEventListener( 'click', onClick );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
