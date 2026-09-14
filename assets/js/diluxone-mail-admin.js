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

	/**
	 * Dragging a provider to where it belongs in the order.
	 *
	 * The order is the only thing that decides anything on that screen, so
	 * changing it is a gesture rather than a form. The arrows do the same job
	 * without this — a row that can only be moved by dragging is a row that
	 * cannot be moved with a keyboard.
	 */
	function order() {
		var table = document.querySelector( '.diluxone-mail-connections tbody' );
		var form = document.getElementById( 'diluxone-mail-order' );

		if ( ! table || ! form ) {
			return;
		}

		var dragging = null;

		table.addEventListener( 'dragstart', function ( event ) {
			dragging = event.target.closest( 'tr' );

			if ( dragging ) {
				dragging.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
			}
		} );

		table.addEventListener( 'dragover', function ( event ) {
			if ( ! dragging ) {
				return;
			}

			event.preventDefault();

			var over = event.target.closest( 'tr' );

			if ( ! over || over === dragging ) {
				return;
			}

			var below = over.getBoundingClientRect().top + over.offsetHeight / 2 < event.clientY;

			over.parentNode.insertBefore( dragging, below ? over.nextSibling : over );
		} );

		table.addEventListener( 'dragend', function () {
			if ( ! dragging ) {
				return;
			}

			dragging.classList.remove( 'is-dragging' );
			dragging = null;

			var ids = Array.prototype.map.call( table.querySelectorAll( 'tr[data-id]' ), function ( row ) {
				return row.dataset.id;
			} );

			form.querySelector( 'input[name="order"]' ).value = ids.join( ',' );
			form.submit();
		} );
	}

	/**
	 * The panel behaving like the dialog it says it is.
	 *
	 * The markup already declares `role="dialog"` and `aria-modal="true"`, and
	 * declaring it is a promise: a screen reader stops announcing what is
	 * behind, so a Tab that walks out of the panel lands on controls the
	 * person can no longer be told about — the list under it, the admin menu,
	 * the whole of WordPress — with no way back and nothing saying what
	 * happened. Either the attribute goes or the focus stays in.
	 *
	 * Escape closes it by following the same link the Close button is, so
	 * there is one way out and it is the one that carries the record's id
	 * back to the list for the closing sentence.
	 */
	function panel() {
		var dialog = document.querySelector( '.diluxone-mail-panel[role="dialog"]' );

		if ( ! dialog ) {
			return;
		}

		var close = dialog.querySelector( '.diluxone-mail-panel-close' );

		function tabbable() {
			// Visible and enabled only: a hidden step's fields are in the
			// document — that is how the method swaps the two versions — and
			// tabbing into one would be tabbing into nothing.
			return Array.prototype.filter.call(
				dialog.querySelectorAll( 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])' ),
				function ( el ) {
					return ! el.disabled && null !== el.offsetParent;
				}
			);
		}

		dialog.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();

				if ( close ) {
					window.location.href = close.href;
				}

				return;
			}

			if ( 'Tab' !== event.key ) {
				return;
			}

			var stops = tabbable();

			if ( ! stops.length ) {
				return;
			}

			var first = stops[ 0 ];
			var last = stops[ stops.length - 1 ];

			// Wrapping only at the ends: everywhere else the browser already
			// does the right thing, and taking Tab over would break every
			// keyboard habit the rest of the dashboard teaches.
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );

		// The list behind is long, and a wheel over the backdrop scrolling it
		// instead of the panel is the thing that gives a dialog away as not
		// being one. Done here rather than in the stylesheet because without
		// the script there is no trap either, and a page that cannot scroll
		// and cannot be escaped is worse than one that scrolls.
		document.body.classList.add( 'diluxone-mail-panel-open' );

		// Opening it puts the focus inside, because a dialog nobody is
		// standing in is a dialog whose first Tab goes to the page behind.
		var start = dialog.querySelector( '.diluxone-mail-panel-body input:not([type="hidden"]), .diluxone-mail-panel-body select, .diluxone-mail-panel-body button' );

		( start || close || dialog ).focus();
	}

	/**
	 * The deliverability diagnosis, fetched after the page is on screen.
	 *
	 * The screen renders the shape of the answer and this goes and gets it.
	 * What comes back is only whether it worked: the report is in the site's
	 * cache by then, and reloading renders it through the same template that
	 * has always rendered it. A second renderer here would be a copy of that
	 * template that drifts from it the first time either one changes.
	 */
	function diagnosis() {
		var skeleton = document.querySelector( '[data-diluxone-mail-diagnose]' );

		if ( ! skeleton || ! window.fetch || ! window.ajaxurl ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'diluxone_mail_diagnose' );
		body.append( '_wpnonce', skeleton.dataset.diluxoneMailDiagnose );

		fetch( window.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( 'diagnosis failed' );
				}

				window.location.reload();
			} )
			.catch( function () {
				// Whatever went wrong, the fallback link already on the page
				// does the same job the slow way. Saying so beats a skeleton
				// that shimmers forever.
				skeleton.classList.add( 'is-stuck' );
			} );
	}

	method();
	credentials();
	order();
	panel();
	diagnosis();
}() );
