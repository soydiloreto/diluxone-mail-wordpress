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

	var method = document.getElementsByName( 'diluxone_mail_transport' );
	var picker = document.getElementById( 'diluxone_mail_provider' );

	if ( ! method.length || ! picker ) {
		return;
	}

	/**
	 * Everything on this step that is about one method and not the other.
	 *
	 * Which method is chosen is a radio button, and until it is submitted the
	 * server knows nothing about it — so a screen that only renames itself on
	 * the next page load spends the whole first step describing the method the
	 * person just stopped choosing. The second tab carries both of its names
	 * and is renamed by the same two classes.
	 */
	function follow() {
		var api = document.getElementById( 'diluxone_mail_transport_api' );
		var on = api && api.checked;

		Array.prototype.forEach.call( picker.options, function ( option ) {
			if ( '' === option.value ) {
				return;
			}

			// Over HTTPS only some providers can be reached, and offering the
			// rest is offering a configuration that would fall back to SMTP
			// without the screen saying so.
			var supported = ! on || '1' === option.dataset.api;

			option.hidden = ! supported;
			option.disabled = ! supported;

			if ( ! supported && option.selected ) {
				picker.value = '';
			}
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.diluxone-mail-when-api' ), function ( el ) {
			el.hidden = ! on;
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.diluxone-mail-when-smtp' ), function ( el ) {
			el.hidden = on;
		} );
	}

	Array.prototype.forEach.call( method, function ( radio ) {
		radio.addEventListener( 'change', follow );
	} );

	follow();
}() );
