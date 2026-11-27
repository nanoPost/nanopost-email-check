/**
 * The state of Email Canary on the plugin screen, while the site is connected.
 *
 * Asks the plugin (admin-ajax) once when the screen opens. The plugin asks nanoPost on the
 * server, at most once a minute; the token never comes here. When the state changed, the
 * answer has the new HTML of the Email Canary part and of the log, made and escaped on the
 * server.
 */
( function () {
	'use strict';

	var cfg = window.nanopostEmailCheckCanary;
	var canary = document.getElementById( 'nanopost-email-check-canary' );
	var log = document.getElementById( 'nanopost-email-check-log' );
	if ( ! cfg || ! canary || ! window.fetch ) {
		return;
	}

	var body = new window.FormData();
	body.append( 'action', cfg.action );
	body.append( '_ajax_nonce', cfg.nonce );

	window
		.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
		.then( function ( response ) {
			return response.json();
		} )
		.then( function ( answer ) {
			if ( ! answer || ! answer.success || ! answer.data.changed ) {
				return;
			}
			canary.innerHTML = answer.data.canary;
			if ( log && 'string' === typeof answer.data.log ) {
				log.innerHTML = answer.data.log;
			}
		} )
		.catch( function () {} );
} )();
