<?php
/**
 * The environment/database precedence, the plugin's most important rule.
 *
 * If this breaks, a production credential ends up stored in the site's
 * database, which is exactly what the design avoids.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';

class ConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = false;

		foreach ( \diluxone_mail_config_fields() as $suffix ) {
			putenv( 'DILUXONE_MAIL_' . $suffix );
		}
	}

	public function test_with_nothing_configured_it_answers_the_default(): void {
		$host = \diluxone_mail_config_value( 'host' );

		$this->assertSame( '', $host['value'] );
		$this->assertSame( 'default', $host['source'] );
	}

	public function test_the_option_wins_when_there_is_no_environment(): void {
		\update_option( 'diluxone_mail_host', 'smtp.stored.test' );

		$host = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.stored.test', $host['value'] );
		$this->assertSame( 'site', $host['source'] );
		$this->assertSame( 'diluxone_mail_host', $host['origin'] );
	}

	public function test_the_environment_variable_beats_the_option(): void {
		\update_option( 'diluxone_mail_host', 'smtp.stored.test' );
		putenv( 'DILUXONE_MAIL_HOST=smtp.environment.test' );

		$host = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.environment.test', $host['value'] );
		$this->assertSame( 'env', $host['source'] );
		$this->assertSame( 'DILUXONE_MAIL_HOST', $host['origin'] );
	}

	/**
	 * A variable that is exported but empty is a half-written line, not the
	 * decision to leave the host blank. If it counted as defined, an
	 * `export DILUXONE_MAIL_HOST=` in a deployment script would leave the site
	 * without mail and with the settings screen read-only, with no way to fix
	 * it from the dashboard.
	 */
	public function test_an_empty_variable_is_the_same_as_not_having_it(): void {
		\update_option( 'diluxone_mail_host', 'smtp.stored.test' );
		putenv( 'DILUXONE_MAIL_HOST=' );

		$this->assertSame( 'site', \diluxone_mail_config_value( 'host' )['source'] );
	}

	/**
	 * The constant beats everything. It is tested with from_name and not with
	 * host because a constant cannot be undefined once defined, and the tests
	 * run in random order: defining DILUXONE_MAIL_HOST here would ruin the
	 * ones above half of the time.
	 */
	public function test_the_constant_beats_the_environment_variable(): void {
		define( 'DILUXONE_MAIL_FROM_NAME', 'From the constant' );

		\update_option( 'diluxone_mail_from_name', 'From the database' );
		putenv( 'DILUXONE_MAIL_FROM_NAME=From the environment' );

		$name = \diluxone_mail_config_value( 'from_name' );

		$this->assertSame( 'From the constant', $name['value'] );
		$this->assertSame( 'constant', $name['source'] );
	}

	public function test_what_the_environment_dictates_is_not_written_to_the_database(): void {
		putenv( 'DILUXONE_MAIL_HOST=smtp.environment.test' );

		\diluxone_mail_save_options(
			array(
				'diluxone_mail_host' => 'smtp.intento.test',
				'diluxone_mail_port' => 2525,
			)
		);

		// The host was left alone: the environment dictates it.
		$this->assertArrayNotHasKey( 'diluxone_mail_host', $GLOBALS['_test_wp_options'] );
		// The port was written: nothing else dictates it.
		$this->assertSame( 2525, \get_option( 'diluxone_mail_port' ) );
	}

	public function test_a_key_that_is_not_a_setting_is_not_saved(): void {
		\diluxone_mail_save_options( array( 'siteurl' => 'https://secuestrado.test' ) );

		$this->assertArrayNotHasKey( 'siteurl', $GLOBALS['_test_wp_options'] );
	}

	public function test_an_internal_option_cannot_be_written_from_the_form(): void {
		\diluxone_mail_save_options( array( 'diluxone_mail_db_version' => 99 ) );

		$this->assertArrayNotHasKey( 'diluxone_mail_db_version', $GLOBALS['_test_wp_options'] );
	}

	/**
	 * The password travels base64-encoded in the SMTP dialogue, not in the
	 * clear. Redacting only the literal leaves the whole credential visible in
	 * the SMTPDebug dump, on a line anybody decodes in a second.
	 */
	public function test_the_password_is_redacted_in_base64_too(): void {
		\update_option( 'diluxone_mail_pass', 'test-secret' );

		$dialogue = "AUTH LOGIN\r\n" . base64_encode( 'test-secret' ) . "\r\n";
		$redacted  = \diluxone_mail_redact( $dialogue . 'and in the clear: test-secret' );

		$this->assertStringNotContainsString( 'test-secret', $redacted );
		$this->assertStringNotContainsString( base64_encode( 'test-secret' ), $redacted );
		$this->assertStringContainsString( '***', $redacted );
	}

	public function test_with_no_password_configured_nothing_is_redacted(): void {
		$this->assertSame( 'un texto cualquiera', \diluxone_mail_redact( 'un texto cualquiera' ) );
	}
}
