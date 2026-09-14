<?php
/**
 * The site/network precedence on a network.
 *
 * The mail server belongs to the network; a site only has its own if the
 * network allows it. If this breaks, either a site ends up overriding the
 * network without permission, or the network cannot pin anything down.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';

class NetworkOptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = true;

		foreach ( \diluxone_mail_config_fields() as $suffix ) {
			putenv( 'DILUXONE_MAIL_' . $suffix );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['_test_multisite'] = false;

		parent::tearDown();
	}

	public function test_the_network_wins_when_the_site_has_no_permission(): void {
		\update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		\update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$v = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.red.test', $v['value'] );
		$this->assertSame( 'network', $v['source'] );
	}

	public function test_the_site_overrides_the_network_only_with_permission(): void {
		\update_site_option( 'diluxone_mail_network_allow_override', 1 );
		\update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		\update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$v = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.sitio.test', $v['value'] );
		$this->assertSame( 'site', $v['source'] );
	}

	public function test_with_permission_but_no_value_of_its_own_it_inherits_from_the_network(): void {
		\update_site_option( 'diluxone_mail_network_allow_override', 1 );
		\update_site_option( 'diluxone_mail_from', 'network@example.test' );

		$this->assertSame( 'network@example.test', \diluxone_mail_config_value( 'from' )['value'] );
	}

	public function test_the_environment_beats_the_network(): void {
		\update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		putenv( 'DILUXONE_MAIL_HOST=smtp.environment.test' );

		$this->assertSame( 'env', \diluxone_mail_config_value( 'host' )['source'] );
	}

	public function test_saving_on_a_site_without_permission_writes_nothing(): void {
		\diluxone_mail_save_options( array( 'diluxone_mail_host' => 'smtp.sitio.test' ), 'site' );

		$this->assertArrayNotHasKey( 'diluxone_mail_host', $GLOBALS['_test_wp_options'] );
	}

	public function test_saving_on_the_network_writes_to_the_network(): void {
		\diluxone_mail_save_options( array( 'diluxone_mail_host' => 'smtp.red.test' ), 'network' );

		$this->assertSame( 'smtp.red.test', \diluxone_mail_option( 'diluxone_mail_host' ) );
		$this->assertArrayNotHasKey( 'diluxone_mail_host', $GLOBALS['_test_wp_options'] );
	}

	/**
	 * A site cannot decide whether sites may override the network.
	 */
	public function test_the_permission_option_is_not_saved_from_a_site(): void {
		\update_site_option( 'diluxone_mail_network_allow_override', 1 );
		\diluxone_mail_save_options( array( 'diluxone_mail_network_allow_override' => 0 ), 'site' );

		$this->assertSame( 1, \get_site_option( 'diluxone_mail_network_allow_override' ) );
		$this->assertArrayNotHasKey( 'diluxone_mail_network_allow_override', $GLOBALS['_test_wp_options'] );
	}

	public function test_off_a_network_the_site_is_all_there_is(): void {
		$GLOBALS['_test_multisite'] = false;

		\update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$this->assertSame( 'site', \diluxone_mail_config_value( 'host' )['source'] );
		$this->assertTrue( \diluxone_mail_site_override_allowed() );
	}
}
