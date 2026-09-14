<?php
/**
 * Deleting the plugin: that it takes everything, and only when asked.
 *
 * uninstall.php cannot call a single function of the plugin — by the time
 * WordPress runs it the plugin is gone — so the list of option names is
 * written out there by hand. A list written by hand is a list that goes stale
 * the first time somebody adds a setting, and the symptom is silent: twenty
 * orphan rows left in wp_options that nobody ever sees. This is what fails
 * instead.
 */

namespace Tests\Unit\DiluxOneMail;

// The file refuses to load outside an uninstall, which is the point of it.
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'diluxone-mail/diluxone-mail.php' );

require_once __DIR__ . '/../../../uninstall.php';

class UninstallTest extends AdminTestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_cleared_hooks'] = array();
		$GLOBALS['_test_switched']         = array();
		$GLOBALS['_test_sites']            = array();
	}

	public function test_every_setting_the_plugin_has_is_on_the_list(): void {
		$faltan = array_diff(
			array_keys( \diluxone_mail_option_defaults() ),
			\diluxone_mail_uninstall_options()
		);

		$this->assertSame(
			array(),
			array_values( $faltan ),
			'uninstall.php does not know about these settings, so deleting the plugin would leave them behind'
		);
	}

	/**
	 * And the other way round: a name left there after the setting it refers
	 * to was removed is a line nobody will ever delete on purpose.
	 */
	public function test_nothing_on_the_list_is_a_setting_that_no_longer_exists(): void {
		// These are not settings and will not be in the defaults: the list of
		// providers, the record of which DNS transients were written, and the
		// schema version.
		$aparte = array( 'diluxone_mail_connections', 'diluxone_mail_dns_cache_keys', 'diluxone_mail_db_version' );

		$sobran = array_diff(
			\diluxone_mail_uninstall_options(),
			array_keys( \diluxone_mail_option_defaults() ),
			$aparte
		);

		$this->assertSame( array(), array_values( $sobran ) );
	}

	public function test_with_the_setting_off_nothing_is_touched(): void {
		\update_option( 'diluxone_mail_host', 'smtp.sigue.test' );
		\update_option( 'diluxone_mail_delete_data_on_uninstall', 0 );

		\diluxone_mail_uninstall();

		$this->assertSame( 'smtp.sigue.test', \get_option( 'diluxone_mail_host' ) );
		$this->assertSame( array(), $this->db->of( 'query' ) );
	}

	public function test_with_it_on_the_settings_the_tables_and_the_cron_all_go(): void {
		\update_option( 'diluxone_mail_host', 'smtp.se-va.test' );
		\update_option( 'diluxone_mail_delete_data_on_uninstall', 1 );
		\update_site_option( 'diluxone_mail_db_version', 4 );

		\diluxone_mail_uninstall();

		$this->assertNull( \get_option( 'diluxone_mail_host', null ) );
		$this->assertNull( \get_site_option( 'diluxone_mail_db_version', null ) );
		$this->assertContains( 'diluxone_mail_purge', $GLOBALS['_test_wp_cleared_hooks'] );

		$dropped = array_map(
			static fn( array $llamada ): string => (string) $llamada['sql'],
			$this->db->of( 'query' )
		);

		$this->assertCount( 2, $dropped );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_diluxone_mail_log`', $dropped[0] );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_diluxone_mail_detail`', $dropped[1] );
	}

	/**
	 * On a network the tables are shared and the settings are not.
	 *
	 * Dropping one pair of tables is right — they were created once for the
	 * whole network — but a site's own options live on that site, so every
	 * site has to be visited or the network keeps the rows of everybody who
	 * ever configured anything.
	 */
	public function test_on_a_network_every_site_is_visited_and_the_tables_drop_once(): void {
		$GLOBALS['_test_multisite'] = true;
		$GLOBALS['_test_sites']     = array( 1, 7, 12 );

		\update_site_option( 'diluxone_mail_delete_data_on_uninstall', 1 );

		\diluxone_mail_uninstall();

		$this->assertSame( array( 1, 7, 12 ), $GLOBALS['_test_switched'] );
		$this->assertCount( 2, $this->db->of( 'query' ) );

		$GLOBALS['_test_multisite'] = false;
	}

	/**
	 * And on a network the answer is the network's: deleting the plugin there
	 * deletes it for everybody at once, and one site cannot decide what
	 * happens to a log that is shared.
	 */
	public function test_on_a_network_one_sites_preference_does_not_decide(): void {
		$GLOBALS['_test_multisite'] = true;

		\update_option( 'diluxone_mail_delete_data_on_uninstall', 1 );
		\update_option( 'diluxone_mail_host', 'smtp.sigue.test' );

		\diluxone_mail_uninstall();

		$this->assertSame( 'smtp.sigue.test', \get_option( 'diluxone_mail_host' ) );

		$GLOBALS['_test_multisite'] = false;
	}

	/**
	 * The cached DNS answers are transients, which cannot be listed by prefix.
	 * The plugin keeps the keys it wrote for exactly this, and uninstalling is
	 * the last chance to use them.
	 */
	public function test_the_dns_cache_goes_with_it(): void {
		\update_site_option( 'diluxone_mail_dns_cache_keys', array( 'diluxone_mail_dns_abc' ) );
		\set_site_transient( 'diluxone_mail_dns_abc', array( 'records' => array( 'x' ) ), 60 );
		\update_option( 'diluxone_mail_delete_data_on_uninstall', 1 );

		\diluxone_mail_uninstall();

		$this->assertFalse( \get_site_transient( 'diluxone_mail_dns_abc' ) );
		$this->assertNull( \get_site_option( 'diluxone_mail_dns_cache_keys', null ) );
	}
}
