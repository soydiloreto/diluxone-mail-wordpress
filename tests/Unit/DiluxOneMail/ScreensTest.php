<?php
/**
 * The screens paint without breaking and say what they are meant to say.
 */

namespace Tests\Unit\DiluxOneMail;

class ScreensTest extends AdminTestCase {

	public static function setUpBeforeClass(): void {
		$fluent = WP_PLUGIN_DIR . '/fluent-smtp/fluent-smtp.php';
		$azure  = WP_PLUGIN_DIR . '/azure-app-service-email/plugin.php';
		@mkdir( dirname( $fluent ), 0777, true );
		@mkdir( dirname( $azure ), 0777, true );
		file_put_contents( $fluent, "<?php\nfunction fluent_test_mailer( \$m ) {}\n" );
		file_put_contents( $azure, "<?php\nreturn static function ( \$pre ) { return false; };\n" );
		require_once $fluent;
	}

	public function test_the_menu_and_the_styles(): void {
		$GLOBALS['_test_menu']    = array();
		$GLOBALS['_test_submenu'] = array();
		$GLOBALS['_test_styles']  = array();

		\diluxone_mail_menu();
		\diluxone_mail_network_menu();

		$this->assertCount( 1, $GLOBALS['_test_menu'] );
		$this->assertCount( 7, $GLOBALS['_test_submenu'] );

		\diluxone_mail_admin_styles( 'toplevel_page_diluxone-mail' );
		\diluxone_mail_admin_styles( 'edit.php' );
		$this->assertCount( 1, $GLOBALS['_test_styles'] );

		// The script goes where there is a form, which is every screen that
		// renders the tabs — and not on the ones that do not.
		$GLOBALS['_test_scripts'] = array();

		foreach ( array( 'diluxone-mail_page_diluxone-mail-provider', 'diluxone-mail_page_diluxone-mail-settings', 'settings_page_diluxone-mail-network', 'diluxone-mail_page_diluxone-mail-dns' ) as $con_formulario ) {
			$GLOBALS['_test_scripts'] = array();
			\diluxone_mail_admin_styles( $con_formulario );
			$this->assertCount( 1, $GLOBALS['_test_scripts'], $con_formulario );
		}

		foreach ( array( 'toplevel_page_diluxone-mail', 'diluxone-mail_page_diluxone-mail-log', 'profile.php' ) as $sin_formulario ) {
			$GLOBALS['_test_scripts'] = array();
			\diluxone_mail_admin_styles( $sin_formulario );
			$this->assertCount( 0, $GLOBALS['_test_scripts'], $sin_formulario );
		}
	}

	public function test_the_first_tab_offers_the_profiles(): void {
$this->panel();
		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'Mailjet', $html );
		$this->assertStringContainsString( 'diluxone_mail_apply_provider', $html );
		$this->assertStringContainsString( '1. Provider', $html );
		$this->assertStringNotContainsString( 'name="diluxone_mail_network_allow_override"', $html );
	}

	public function test_the_server_tab_shows_what_a_failed_connection_said(): void {
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\set_transient( 'diluxone_mail_attempt_1', array( 'ok' => false, 'error' => '535 nope', 'transcript' => 'AUTH LOGIN', 'seconds' => 0.1, 'fields' => array( 'diluxone_mail_host' => 'smtp.typo.test' ) ), 60 );

$this->panel();
		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( '535 nope', $html );
		$this->assertStringContainsString( 'AUTH LOGIN', $html );
		// What was typed comes back, so the tab does not lose it.
		$this->assertStringContainsString( 'smtp.typo.test', $html );
		$this->assertStringContainsString( 'Test connection and save', $html );
	}

	public function test_the_test_tab_shows_what_the_send_said(): void {
		$this->configured();
		$_GET['tab'] = 'test';
		\set_transient( 'diluxone_mail_test_1', array( 'ok' => true, 'error' => '', 'transcript' => '', 'seconds' => 0.2, 'to' => 'a@x.test' ), 60 );

$this->panel();
		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'handed to the server', $html );
		$this->assertStringContainsString( 'diluxone_mail_test', $html );
	}

	public function test_the_server_tab_with_the_environment(): void {
		putenv( 'DILUXONE_MAIL_PASS=secreta' );
		putenv( 'DILUXONE_MAIL_PROVIDER=ses' );

$this->panel();
		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'defined by the environment', $html );
		$this->assertStringContainsString( 'Replace the region', $html );
		$this->assertStringNotContainsString( 'secreta', $html );
	}

	public function test_the_network_screen(): void {
		$GLOBALS['_test_multisite'] = true;
		$_GET['tab']                = 'sites';

		$html = $this->render( 'diluxone_mail_screen_network' );

		$this->assertStringContainsString( 'Network settings', $html );
		$this->assertStringContainsString( 'name="diluxone_mail_network_allow_override"', $html );

$this->panel();
		$sitio = $this->render( 'diluxone_mail_screen_provider' );
		$this->assertStringContainsString( 'fixed by the network', $sitio );
	}

	public function test_the_status_screen(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_last_result', array( 'ok' => 0, 'time' => time() - 60, 'error' => 'boom', 'provider' => 'mailjet' ) );
		$GLOBALS['_test_cron']['diluxone_mail_purge'] = time() + 3600;
		$this->db->next_results                        = array( array( 'status' => 'failed', 'n' => 1 ) );

		$html = $this->render( 'diluxone_mail_screen_status' );

		$this->assertStringContainsString( 'boom', $html );
		$this->assertStringContainsString( 'Failed: 1', $html );
		$this->assertStringContainsString( 'Next purge', $html );

		\update_option( 'diluxone_mail_mode', 'observe' );
		\update_option( 'diluxone_mail_log_enabled', 0 );
		$this->assertStringContainsString( 'Observer mode', $this->render( 'diluxone_mail_screen_status' ) );
	}

	public function test_the_status_screen_on_a_network_and_with_an_interceptor(): void {
		$GLOBALS['_test_multisite'] = true;
		\update_site_option( 'diluxone_mail_unhook_pre_wp_mail', 1 );
		\add_action( 'phpmailer_init', 'fluent_test_mailer' );
		$plugin = WP_PLUGIN_DIR . '/azure-app-service-email/plugin.php';
		\add_filter( 'pre_wp_mail', require $plugin, 10, 2 );
		\diluxone_mail_other_mailers( true );
		\update_option( 'diluxone_mail_last_result', array( 'ok' => 1, 'time' => time() - 60, 'error' => '', 'provider' => 'mailjet' ) );

		$html = $this->render( 'diluxone_mail_screen_status' );

		$this->assertStringContainsString( 'Being detached', $html );
		$this->assertStringContainsString( 'fixed by the network', $html );
		$this->assertStringContainsString( 'Delivered to the server', $html );
	}

	public function test_the_log_screen(): void {
		$this->db->next_var     = 2;
		$this->db->next_results = array( $this->row(), $this->row( array( 'id' => 8, 'kind' => 'cc', 'status' => 'failed', 'error' => 'no', 'provider' => 'observer', 'subject' => '' ) ) );

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'ana@x.test', $html );
		$this->assertStringContainsString( '(CC)', $html );
		$this->assertStringContainsString( 'Failed — no', $html );
		$this->assertStringContainsString( 'another plugin', $html );
		$this->assertStringContainsString( '(no subject)', $html );
		$this->assertStringContainsString( 'search-box', $html );

		\update_option( 'diluxone_mail_log_enabled', 0 );
		$this->assertStringContainsString( 'The mail log is off', $this->render( 'diluxone_mail_screen_log' ) );
	}

	public function test_the_log_screen_empty_and_with_filters(): void {
		$_GET['status'] = 'failed';
		$_GET['s']      = 'hola';
		$_GET['all']    = '1';
		$GLOBALS['_test_multisite'] = true;

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'No messages logged yet', $html );
		$this->assertStringContainsString( 'All sites', $html );
		$this->assertStringContainsString( "status = 'failed'", $this->db->of( 'get_var' )[0]['sql'] );
		$this->assertStringNotContainsString( 'site_id', $this->db->of( 'get_var' )[0]['sql'] );
	}

	public function test_the_detail_of_a_message(): void {
		$_GET['view']           = '7';
		$this->db->next_row     = $this->row();
		$this->db->next_results = array( $this->row(), $this->row( array( 'email' => 'b@x.test', 'kind' => 'cc' ) ) );

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'uuid-7', $html );
		$this->assertStringContainsString( 'X-Test', $html );
		$this->assertStringContainsString( 'a.pdf', $html );
		$this->assertStringContainsString( 'b@x.test', $html );
		$this->assertStringContainsString( 'content of the message is not stored', $html );
	}

	public function test_the_detail_with_a_dialogue(): void {
		$_GET['view']       = '7';
		$this->db->next_row = $this->row( array( 'error' => 'x', 'transcript' => 'EHLO' ) );

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'EHLO', $html );
		$this->assertStringNotContainsString( 'Resend', $html );
	}

	public function test_the_detail_of_a_message_that_does_not_exist_or_belongs_to_another_site(): void {
		$_GET['view'] = '99';
		$this->assertStringContainsString( 'no longer in the log', $this->render( 'diluxone_mail_screen_log' ) );

		$GLOBALS['_test_multisite'] = true;
		$GLOBALS['_test_blog_id']   = 2;
		$this->db->next_row         = $this->row();

		// A super admin sees it; the stub says we are one.
		$this->assertStringContainsString( 'uuid-7', $this->render( 'diluxone_mail_screen_log' ) );
		$GLOBALS['_test_blog_id'] = 1;
	}

	/**
	 * With nothing cached the screen draws the shape of the answer.
	 *
	 * The diagnosis is seconds of DNS and it used to run inside the render, so
	 * the screen answered a click with a blank page for as long as it took.
	 * Now it never runs it: what comes back is the skeleton, the options form
	 * — which is exactly what somebody opening a slow diagnosis wants to reach
	 * — and the nonce the browser needs to go and ask.
	 */
	public function test_the_deliverability_screen_draws_before_the_diagnosis_runs(): void {
		\update_option( 'diluxone_mail_from', 'hello@healthy.test' );

		$html = $this->render( 'diluxone_mail_screen_dns' );

		$this->assertStringContainsString( 'healthy.test', $html );
		$this->assertStringContainsString( 'diluxone-mail-skeleton', $html );
		$this->assertStringContainsString( 'data-diluxone-mail-diagnose', $html );

		// Not a dead end without JavaScript, and not a page that hides the
		// four settings the diagnosis runs on while it is running.
		$this->assertStringContainsString( 'diluxone_mail_wait=1', $html );
		$this->assertStringContainsString( 'Options of the diagnosis', $html );

		// And drawing it did not run the diagnosis: there is still nothing
		// cached, which is the whole point — the wait moved out of the render
		// rather than being hidden behind a nicer screen.
		$this->assertNull( \diluxone_mail_diagnosis_cached( 'healthy.test' ) );
	}

	/**
	 * The other half of the skeleton: what the browser calls behind it.
	 *
	 * It answers whether the work got done and nothing else. The report is in
	 * the cache by then and the page reads it from there on the way back in —
	 * so there is one thing that renders a report, not a template and a copy
	 * of it in JavaScript drifting apart.
	 */
	public function test_the_diagnosis_the_browser_asks_for_leaves_it_cached(): void {
		\update_option( 'diluxone_mail_from', 'hello@healthy.test' );

		$this->assertNull( \diluxone_mail_diagnosis_cached( 'healthy.test' ) );

		try {
			\diluxone_mail_diagnose_ajax();
			$this->fail( 'it did not answer' );
		} catch ( \DiluxOne_Test_Json $json ) {
			$this->assertTrue( $json->ok );
			$this->assertSame( 'healthy.test', $json->data['domain'] );
		}

		$this->assertIsArray( \diluxone_mail_diagnosis_cached( 'healthy.test' ) );
	}

	public function test_the_diagnosis_is_not_something_a_visitor_can_set_running(): void {
		$GLOBALS['_test_can'] = false;

		try {
			\diluxone_mail_diagnose_ajax();
			$this->fail( 'it did not refuse' );
		} catch ( \DiluxOne_Test_Json $json ) {
			$this->assertFalse( $json->ok );
			$this->assertSame( 403, $json->status );
		}

		// A pile of DNS lookups on somebody else's resolver, started by
		// anybody who can load a URL, is a way of using this site to make
		// traffic. The nonce is checked too, after the capability.
		$GLOBALS['_test_can']          = true;
		$GLOBALS['_test_nonce_fails']  = true;

		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_diagnose_ajax();
	}

	public function test_with_no_domain_there_is_nothing_for_the_browser_to_fetch(): void {
		// No configured domain, no sender, and a site URL with no host in it —
		// the three places the domain is looked for, in order.
		\add_filter( 'diluxone_mail_option', static fn( $v, $k ) => 'diluxone_mail_dns_domain' === $k ? '' : $v, 10, 2 );
		\update_option( 'diluxone_mail_from', '' );
		$GLOBALS['_test_wp_url_base'] = '/';

		$this->assertSame( '', \diluxone_mail_dns_domain() );

		try {
			\diluxone_mail_diagnose_ajax();
			$this->fail( 'it did not answer' );
		} catch ( \DiluxOne_Test_Json $json ) {
			$this->assertFalse( $json->ok );
			$this->assertSame( 400, $json->status );
		}

		unset( $GLOBALS['_test_wp_url_base'] );
	}

	public function test_the_deliverability_screen(): void {
		\update_option( 'diluxone_mail_from', 'hello@healthy.test' );

		// Waiting on purpose is what the fallback link asks for, and what the
		// screen did for everybody before.
		$_GET['diluxone_mail_wait'] = '1';

		$html = $this->render( 'diluxone_mail_screen_dns' );

		$this->assertStringContainsString( 'healthy.test', $html );
		$this->assertStringContainsString( 'Revalidate', $html );
		$this->assertStringContainsString( 'No record', $html );
		$this->assertStringNotContainsString( 'diluxone-mail-skeleton', $html );

		// And once it has run, the plain screen shows it from the cache
		// without waiting for anything.
		unset( $_GET['diluxone_mail_wait'] );

		$html = $this->render( 'diluxone_mail_screen_dns' );

		$this->assertStringContainsString( 'Revalidate', $html );
		$this->assertStringNotContainsString( 'diluxone-mail-skeleton', $html );
	}

	public function test_the_deliverability_screen_with_no_domain(): void {
		\update_option( 'diluxone_mail_dns_domain', '' );

		// With no sender and no domain configured the site's own comes out; an
		// empty one is forced through the setting the view looks at.
		\add_filter( 'diluxone_mail_option', static fn( $v, $k ) => 'diluxone_mail_dns_domain' === $k ? '' : $v, 10, 2 );

		$this->assertIsString( $this->render( 'diluxone_mail_screen_dns' ) );
	}

	public function test_the_tab_title_on_a_plugin_screen(): void {
		$GLOBALS['_test_screen'] = new \WP_Screen( 'toplevel_page_diluxone-mail' );

		$this->assertSame( 'DiluxOne Mail | Settings — Sitio', \diluxone_mail_admin_title( 'Settings — Sitio', 'Settings' ) );

		$GLOBALS['_test_screen'] = new \WP_Screen( 'profile' );
		$_GET['diluxone_mail_done'] = 'saved';
		$this->assertStringContainsString( 'Settings saved', $this->render( 'diluxone_mail_profile_notices' ) );

		$GLOBALS['_test_screen'] = new \WP_Screen( 'edit-post' );
		$this->assertSame( '', $this->render( 'diluxone_mail_profile_notices' ) );
	}
}
