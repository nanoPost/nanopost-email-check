/**
 * Proof of arrival after "Send test now", while the site is connected to nanoPost.
 *
 * Asks the plugin (admin-ajax) every 5 seconds for at most 2 minutes, and stops at the
 * first final answer. The plugin asks nanoPost on the server; the token never comes here.
 * Text from the answer is set with textContent only.
 */
( function () {
	'use strict';

	var box = document.getElementById( 'nanopost-email-check-proof' );
	var cfg = window.nanopostEmailCheckProof;
	if ( ! box || ! cfg || ! window.fetch ) {
		return;
	}

	var testId = box.getAttribute( 'data-test-id' );
	var status = box.querySelector( '.nanopost-email-check-proof-status' );
	var tries = 0;
	var maxTries = 24;

	function format( text, values ) {
		var i = 0;
		return text.replace( /%(\d)\$s|%s/g, function ( match, n ) {
			var value = n ? values[ parseInt( n, 10 ) - 1 ] : values[ i++ ];
			return value === null || value === undefined ? cfg.text.none : String( value );
		} );
	}

	function show( text ) {
		status.textContent = text;
	}

	function localTime( iso ) {
		var date = new Date( iso );
		return isNaN( date.getTime() ) ? iso : date.toLocaleString();
	}

	function ask() {
		tries++;
		var body = new window.FormData();
		body.append( 'action', cfg.action );
		body.append( '_ajax_nonce', cfg.nonce );
		body.append( 'test_id', testId );

		window
			.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( answer ) {
				if ( ! answer || ! answer.success ) {
					var error = answer && answer.data ? answer.data.error : '';
					// No record yet (the mail and the call can come in any order): wait.
					if ( 'unknown_test' === error || 'not_found' === error ) {
						return next();
					}
					return show( cfg.text.unreachable );
				}
				var data = answer.data;
				if ( 'arrived' === data.status ) {
					return show(
						format( cfg.text.arrived, [ localTime( data.arrived_at ) ] ) +
							' ' +
							format( cfg.text.auth, [ data.spf, data.dkim, data.dmarc ] )
					);
				}
				if ( 'missing' === data.status ) {
					return show( cfg.text.missing );
				}
				if ( 'send_failed' === data.status ) {
					return show( cfg.text.send_failed );
				}
				return next();
			} )
			.catch( function () {
				show( cfg.text.unreachable );
			} );
	}

	function next() {
		if ( tries >= maxTries ) {
			return show( cfg.text.timeout );
		}
		show( cfg.text.waiting );
		window.setTimeout( ask, 5000 );
	}

	window.setTimeout( ask, 2000 );
} )();
