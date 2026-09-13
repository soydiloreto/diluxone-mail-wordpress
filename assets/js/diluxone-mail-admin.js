/**
 * The one thing on the settings screen that a form cannot say on its own.
 *
 * "The server requires a username and password" decides whether the two fields
 * under it mean anything, and without this the form gives no sign of it: you
 * untick the box and the fields sit there, editable, until a round-trip to the
 * server tells you otherwise. That is the kind of nothing-happened that reads
 * as a broken checkbox.
 *
 * `readonly` rather than `disabled` on purpose: a disabled field is not
 * submitted, and a username that disappears from the POST because a box was
 * unticked is a username lost the next time the box is ticked again.
 */
( function () {
	'use strict';

	var auth = document.getElementById( 'diluxone_mail_auth' );

	if ( ! auth ) {
		return;
	}

	var fields = [
		document.getElementById( 'diluxone_mail_user' ),
		document.getElementById( 'diluxone_mail_pass' ),
	].filter( Boolean );

	function apply() {
		var off = ! auth.checked;

		fields.forEach( function ( field ) {
			field.readOnly = off;
			field.closest( 'tr' ).classList.toggle( 'diluxone-mail-row-off', off );
		} );
	}

	auth.addEventListener( 'change', apply );
	apply();
}() );
