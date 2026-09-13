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

		foreach ( array_keys( diluxone_mail_option_defaults() ) as $option ) {
			delete_option( $option );
			delete_site_option( $option );
		}

		delete_option( 'diluxone_mail_last_result' );
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
