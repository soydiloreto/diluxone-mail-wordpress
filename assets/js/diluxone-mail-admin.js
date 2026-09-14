/**
 * The two things the settings form cannot say on its own.
 *
 * Both are about a control that decides what the controls under it mean, and
 * both live on different tabs. They are separate functions that each look for
 * their own elements and do nothing when they are not on the page: one early
 * return covering the whole file means whichever feature is checked first
 * silently switches off the other on every tab where its own control is
 * absent — which is exactly what happened when this was one block.
 */
( function () {
	'use strict';

	/**
	 * Step 1: the method decides what the rest of the step is talking about.
	 *
	 * Which method is chosen is a radio button, and until it is submitted the
	 * server knows nothing about it. A screen that only renames itself on the
	 * next page load spends the whole first step describing the method the
	 * person has just stopped choosing. Both versions are in the page with the
	 * one that does not apply hidden, and this swaps them — the second tab's
	 * two names included.
	 */
	function method() {
		var picker = document.getElementById( 'diluxone_mail_provider' );
		var api = document.getElementById( 'diluxone_mail_transport_api' );
		var radios = document.getElementsByName( 'diluxone_mail_transport' );

		if ( ! picker || ! api || ! radios.length ) {
			return;
		}

		function follow() {
			var on = api.checked;

			Array.prototype.forEach.call( picker.options, function ( option ) {
				if ( '' === option.value ) {
					return;
				}

				// Over HTTPS only some providers can be reached, and offering
				// the rest is offering a configuration that would fall back to
				// SMTP without the screen saying so.
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

		Array.prototype.forEach.call( radios, function ( radio ) {
			radio.addEventListener( 'change', follow );
		} );

		follow();
	}

	/**
	 * Step 2 over SMTP: the checkbox decides whether the credentials mean
	 * anything.
	 *
	 * `readonly` rather than `disabled` on purpose: a disabled field is not
	 * submitted, and a username that disappears from the POST because a box
	 * was unticked is a username lost the next time the box is ticked again.
	 */
	function credentials() {
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
	}

	method();
	credentials();
}() );
