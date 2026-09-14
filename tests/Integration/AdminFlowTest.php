<?php
/**
 * The admin flows against a real WordPress: real nonces, real capabilities,
 * real redirects, and the screens painting with data from the database.
 *
 * wp_safe_redirect() ends in exit(); the wp_redirect filter runs before that
 * and here throws an exception carrying the URL, which is what the test wants
 * to see.
 */

namespace Tests\Integration;

class RedirectedException extends \Exception {}

class AdminFlowTest extends IntegrationTestCase {

	private int $admin;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		diluxone_mail_install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . diluxone_mail_log_table() );

		$this->admin = $this->alguien( 'administrator' );
		wp_set_current_user( $this->admin );

		add_filter( 'wp_redirect', array( $this, 'catch_redirect' ) );

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'catch_redirect' ) );
		parent::tearDown();
	}

	/** @param string $url */
	public function catch_redirect( $url ): string {
		throw new RedirectedException( (string) $url );
	}

	private function redirect_of( callable $handler ): string {
		try {
			$handler();
		} catch ( RedirectedException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'it did not redirect' );
	}

	private function nonce( string $action ): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
	}

	/**
	 * One configured provider, which is where a site's settings live.
	 *
	 * @param array<string, mixed> $values
	 */
	private function proveedor( array $values ): string {
		return diluxone_mail_connection_put( '', $values );
	}

	private function interceptar(): callable {
		$fn = static fn( $pre ): bool => null === $pre ? true : (bool) $pre;
		add_filter( 'pre_wp_mail', $fn, 10, 1 );
		return $fn;
	}

	/**
	 * The settings a provider owns go into that provider's record.
	 *
	 * Not into options of their own, which is where they used to live and
	 * where this test used to look for them: a site keeps a list of providers,
	 * and a host stored loose would belong to all of them at once.
	 *
	 * The host and the credential have no plain-save path at all — they are
	 * written by the connection test and by nothing else, which is what makes
	 * "it was never saved without working" true rather than merely encouraged
	 * by the layout of the screen. So what a save writes here is the sender,
	 * and the host is what applying the profile put there.
	 */
	public function test_applying_a_profile_and_saving_the_settings_with_a_real_nonce(): void {
		$this->nonce( 'diluxone_mail_settings' );
		$_POST['scope']                  = 'site';
		$_POST['diluxone_mail_provider'] = 'mailjet';

		$this->assertStringContainsString( 'profile-applied', $this->redirect_of( 'diluxone_mail_apply_provider' ) );

		$id = diluxone_mail_default_id();
		$this->assertNotSame( '', $id, 'applying a profile did not create a provider' );
		$this->assertSame( 'in-v3.mailjet.com', diluxone_mail_connection( $id )['diluxone_mail_host'] );
		$this->assertSame( 'in-v3.mailjet.com', diluxone_mail_option( 'diluxone_mail_host' ) );

		// And nothing was left lying around in an option of its own.
		$this->assertFalse( get_option( 'diluxone_mail_host' ) );

		$_POST = array(
			'scope'              => 'site',
			'tab'                => 'sender',
			'_wpnonce'           => $_REQUEST['_wpnonce'],
			'connection'         => $id,
			'diluxone_mail_from' => 'hello@propio.test',
		);
		$_REQUEST['connection'] = $id;

		$this->assertStringContainsString( 'saved', $this->redirect_of( 'diluxone_mail_save_settings' ) );
		$this->assertSame( 'hello@propio.test', diluxone_mail_connection( $id )['diluxone_mail_from'] );
		$this->assertSame( 'hello@propio.test', diluxone_mail_option( 'diluxone_mail_from' ) );

		// A tab saves its own group and no other: a POST carrying somebody
		// else's field is a POST that writes nothing of theirs.
		$this->assertSame( 0, (int) get_option( 'diluxone_mail_log_extended' ) );
	}

	/**
	 * The credential comes back readable through the plugin and is not
	 * readable in the row. That is the whole of encryption at rest, and the
	 * key is the site's own salts, which only exist for real here.
	 */
	public function test_the_credential_is_unreadable_in_the_row_it_is_stored_in(): void {
		$id = $this->proveedor( array( 'diluxone_mail_provider' => 'mailjet' ) );

		$this->assertTrue( diluxone_mail_store_password( 'clave nueva' ) );

		$this->assertSame( 'clave nueva', diluxone_mail_config_value( 'pass' )['value'] );
		$this->assertStringNotContainsString(
			'clave nueva',
			(string) diluxone_mail_connection( $id )['diluxone_mail_pass']
		);
	}

	public function test_without_a_valid_nonce_nothing_is_saved(): void {
		$_POST['scope']              = 'site';
		$_POST['diluxone_mail_host'] = 'smtp.ataque.test';
		$_REQUEST['_wpnonce']        = 'inventado';

		try {
			diluxone_mail_save_settings();
			$this->fail( 'it should have died' );
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->assertFalse( get_option( 'diluxone_mail_host' ) );
		}
	}

	public function test_without_the_capability_nothing_is_saved(): void {
		wp_set_current_user( $this->alguien( 'subscriber' ) );
		$this->nonce( 'diluxone_mail_settings' );
		$_POST['scope'] = 'site';

		$this->expectException( \WPAjaxDieContinueException::class );
		diluxone_mail_save_settings();
	}

	public function test_taking_over_and_revalidating(): void {
		$this->nonce( 'diluxone_mail_take_over' );
		$this->assertStringContainsString( 'took-over', $this->redirect_of( 'diluxone_mail_take_over' ) );
		$this->assertSame( 'transport', get_option( 'diluxone_mail_mode' ) );

		$this->proveedor( array( 'diluxone_mail_from' => 'hello@example.org' ) );
		update_option( 'diluxone_mail_dns_resolver', 'doh' );

		$clave = 'diluxone_mail_diagnosis_' . md5( 'example.org' );
		set_site_transient( $clave, array( 'domain' => 'example.org', 'findings' => array() ), HOUR_IN_SECONDS );

		$this->nonce( 'diluxone_mail_revalidate' );
		$this->assertStringContainsString( 'revalidated', $this->redirect_of( 'diluxone_mail_revalidate' ) );

		// Revalidating empties the cache and nothing else. It used to run the
		// diagnosis here, which put seconds of DNS into a request with nothing
		// on screen — the exact wait the deliverability screen was changed to
		// get rid of. The screen draws its skeleton off this empty cache and
		// the browser fills it.
		$this->assertFalse( get_site_transient( $clave ) );
		$this->assertNull( diluxone_mail_diagnosis_cached( 'example.org' ) );
	}

	public function test_a_persons_profile_paints_their_own(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		$id   = $this->alguien();
		$user = get_user_by( 'id', $id );

		$fn = $this->interceptar();
		wp_mail( $user->user_email, 'Para vos', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		ob_start();
		diluxone_mail_user_profile_section( $user );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Para vos', $html );
		$this->assertStringContainsString( 'Handed to another plugin', $html );

		wp_set_current_user( $this->alguien( 'subscriber' ) );
		ob_start();
		diluxone_mail_user_profile_section( $user );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_screens_paint_with_real_data(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		$this->proveedor( array( 'diluxone_mail_from' => 'hello@example.org' ) );
		update_option( 'diluxone_mail_dns_resolver', 'doh' );

		$fn = $this->interceptar();
		wp_mail( 'list@example.test', 'En la lista', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		set_current_screen( 'toplevel_page_diluxone-mail' );

		ob_start();
		diluxone_mail_screen_settings();
		$ajustes = (string) ob_get_clean();
		$this->assertStringContainsString( 'diluxone_mail_save_settings', $ajustes );

		ob_start();
		diluxone_mail_screen_log();
		$log = (string) ob_get_clean();
		$this->assertStringContainsString( 'En la lista', $log );

		$row        = diluxone_mail_log_query( array( 'emails' => array( 'list@example.test' ) ) )['rows'][0];
		$_GET['view'] = (string) $row['id'];
		ob_start();
		diluxone_mail_screen_log();
		$detalle = (string) ob_get_clean();
		$this->assertStringContainsString( (string) $row['message_id'], $detalle );

		ob_start();
		diluxone_mail_screen_status();
		$status = (string) ob_get_clean();
		$this->assertStringContainsString( 'Observer mode', $status );

		ob_start();
		diluxone_mail_screen_dns();
		$dns = (string) ob_get_clean();
		$this->assertStringContainsString( 'example.org', $dns );
	}

	/**
	 * A real DoH lookup, against a public resolver, from the container.
	 *
	 * It is the suite's only real DNS query and it is deliberate:
	 * dns_get_record() is exercised in the E2E, DoH here.
	 */
	public function test_doh_against_a_public_resolver(): void {
		update_option( 'diluxone_mail_dns_resolver', 'doh' );

		$r = diluxone_mail_dns_lookup( 'pablodiloreto.com', 'TXT' );

		if ( '' !== $r['error'] ) {
			$this->markTestSkipped( 'sin red: ' . $r['error'] );
		}

		$this->assertSame( 'doh', $r['source'] );
		$this->assertNotEmpty( array_filter( $r['records'], static fn( string $t ): bool => str_starts_with( strtolower( $t ), 'v=spf1' ) ) );
		$this->assertSame( 'cache', diluxone_mail_dns_lookup( 'pablodiloreto.com', 'TXT' )['source'] );
	}

	public function test_the_purge_is_scheduled_and_privacy_is_registered(): void {
		diluxone_mail_schedule_purge();
		$this->assertNotFalse( wp_next_scheduled( 'diluxone_mail_purge' ) );

		$this->assertArrayHasKey( 'diluxone-mail', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'diluxone-mail', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		diluxone_mail_deactivate();
		$this->assertFalse( wp_next_scheduled( 'diluxone_mail_purge' ) );
	}

	public function test_the_log_table_really_paginates(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		$fn = $this->interceptar();
		for ( $i = 0; $i < 35; $i++ ) {
			wp_mail( "p{$i}@example.test", "Mensaje {$i}", 'x' );
		}
		remove_filter( 'pre_wp_mail', $fn, 10 );

		require_once DILUXONE_MAIL_DIR . 'includes/log-list-table.php';

		$_REQUEST['paged'] = '2';
		$table         = new \DiluxOne_Mail_Log_Table();
		$table->prepare_items();

		$this->assertCount( 5, $table->items );
		$this->assertSame( 35, $table->get_pagination_arg( 'total_items' ) );
	}
}
