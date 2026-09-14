<?php
/**
 * Base for the tests that need a real WordPress loaded.
 *
 * Between tests every plugin option — the site's and the network's — is
 * deleted, so each one starts out like a freshly installed plugin. The list
 * comes from the defaults and not from a list written here: a new option is
 * covered on its own, and there is no way for a test to see what the previous
 * one left behind and pass — or fail — for the wrong reason.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class IntegrationTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// The multisite suite converts this same site into a network and says
		// so: it is documented as destructive. What it was not is loud, and a
		// site left converted made this suite fail nine tests with messages
		// about none of that. Saying which command puts it back is the whole
		// of the fix.
		if ( $this->needs_single_site() && is_multisite() ) {
			$this->markTestSkipped( 'The tests site is a network — the multisite suite left it that way. `make env-reset` puts it back.' );
		}

		foreach ( array_keys( diluxone_mail_option_defaults() ) as $option ) {
			delete_option( $option );
			delete_site_option( $option );
		}

		// Not one of the defaults, and the one thing that answers for every
		// setting a provider owns: a list another suite left behind would
		// decide these tests instead of them.
		delete_option( 'diluxone_mail_connections' );
		delete_site_option( 'diluxone_mail_connections' );
		delete_option( 'diluxone_mail_last_result' );
	}

	/**
	 * Does this suite need the site not to be a network?
	 *
	 * Everything under tests/Integration does. The multisite suite reuses this
	 * base for the fixtures and says no.
	 */
	protected function needs_single_site(): bool {
		return true;
	}

	/** A new person, with whichever role is passed in. */
	protected function alguien( string $rol = 'subscriber' ): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 'diluxone_mail_' . wp_generate_password( 8, false ),
				'user_email' => wp_generate_password( 8, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 16 ),
				'role'       => $rol,
			)
		);
	}
}
