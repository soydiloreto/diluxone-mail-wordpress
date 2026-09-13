<?php
/**
 * The plugin on a real network.
 *
 * It runs against the wp-env test site converted to multisite with
 * `wp core multisite-convert` and with a second site created. What is tested
 * is what stubs cannot test: that the tables are one set for the whole
 * network, that a send from site 2 carries its site_id, that a person's
 * profile sees every site's, and that the network/site precedence works with
 * real WordPress options.
 */

namespace Tests\Multisite;

use Tests\Integration\IntegrationTestCase;

class NetworkTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Este test necesita una red: wp core multisite-convert.' );
		}

		global $wpdb;

		diluxone_mail_install();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . diluxone_mail_log_table() );
		// phpcs:enable

		delete_site_option( 'diluxone_mail_network_allow_override' );
		delete_site_option( 'diluxone_mail_host' );
		delete_option( 'diluxone_mail_host' );
		update_site_option( 'diluxone_mail_mode', 'observe' );
		update_site_option( 'diluxone_mail_log_enabled', 1 );
	}

	/** The network's second site, created by the suite's script. */
	private function segundo_sitio(): int {
		$sitios = get_sites( array( 'number' => 2, 'orderby' => 'id', 'order' => 'ASC' ) );

		$this->assertGreaterThanOrEqual( 2, count( $sitios ), 'la red necesita un segundo sitio' );

		return (int) $sitios[1]->blog_id;
	}

	private function interceptar(): callable {
		$fn = static fn( $pre ): bool => null === $pre ? true : (bool) $pre;
		add_filter( 'pre_wp_mail', $fn, 10, 1 );
		return $fn;
	}

	public function test_the_tables_belong_to_the_network(): void {
		global $wpdb;

		$this->assertStringStartsWith( $wpdb->base_prefix, diluxone_mail_log_table() );
		$this->assertSame( $wpdb->base_prefix . 'diluxone_mail_log', diluxone_mail_log_table() );
	}

	public function test_a_send_from_another_site_carries_its_site_id(): void {
		$sitio2 = $this->segundo_sitio();

		switch_to_blog( $sitio2 );

		$fn = $this->interceptar();
		wp_mail( 'desde-dos@example.test', 'Desde el sitio 2', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		restore_current_blog();

		$row = diluxone_mail_log_query( array( 'emails' => array( 'desde-dos@example.test' ), 'site_id' => null ) )['rows'][0];

		$this->assertSame( $sitio2, (int) $row['site_id'] );
		// From the main site, filtering by site, it is not visible.
		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'desde-dos@example.test' ) ) )['total'] );
	}

	public function test_a_persons_profile_sees_every_site(): void {
		$id   = $this->alguien();
		$user = get_user_by( 'id', $id );

		$fn = $this->interceptar();
		wp_mail( $user->user_email, 'Sitio 1', 'x' );

		switch_to_blog( $this->segundo_sitio() );
		wp_mail( $user->user_email, 'Sitio 2', 'x' );
		restore_current_blog();

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( 2, diluxone_mail_log_count( diluxone_mail_user_emails( $user ) ) );
	}

	public function test_the_network_wins_and_the_site_only_overrides_with_permission(): void {
		update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$this->assertSame( 'network', diluxone_mail_config_value( 'host' )['source'] );
		$this->assertSame( 'smtp.red.test', diluxone_mail_config_value( 'host' )['value'] );

		update_site_option( 'diluxone_mail_network_allow_override', 1 );

		$this->assertSame( 'site', diluxone_mail_config_value( 'host' )['source'] );
		$this->assertSame( 'smtp.sitio.test', diluxone_mail_config_value( 'host' )['value'] );
	}

	public function test_saving_on_a_site_without_permission_does_nothing(): void {
		diluxone_mail_save_options( array( 'diluxone_mail_host' => 'smtp.intento.test' ), 'site' );

		$this->assertFalse( get_option( 'diluxone_mail_host' ) );
	}

	public function test_the_schema_version_lives_on_the_network(): void {
		$this->assertSame( DILUXONE_MAIL_DB_VERSION, (int) get_site_option( 'diluxone_mail_db_version' ) );
	}
}
