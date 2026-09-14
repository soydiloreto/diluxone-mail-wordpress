<?php
/**
 * The steps of the settings screen: what opens what, and the overview that
 * reports on all of it.
 */

namespace Tests\Unit\DiluxOneMail;

class TabsTest extends AdminTestCase {

	public function test_nothing_configured_starts_on_the_first_step(): void {
		$progress = \diluxone_mail_settings_progress();

		$this->assertFalse( $progress['profile'] );
		$this->assertFalse( $progress['server'] );
		$this->assertSame( 'profile', \diluxone_mail_current_tab() );

		$this->assertTrue( \diluxone_mail_tab_open( 'profile', $progress ) );
		$this->assertFalse( \diluxone_mail_tab_open( 'server', $progress ) );
		// The two that are settings rather than steps are always reachable.
		$this->assertTrue( \diluxone_mail_tab_open( 'logging', $progress ) );
		$this->assertTrue( \diluxone_mail_tab_open( 'sending', $progress ) );
	}

	public function test_each_step_opens_the_next(): void {
		$id = $this->conexion( array( 'diluxone_mail_provider' => 'mailjet' ) );
		$this->assertSame( 'server', \diluxone_mail_current_tab() );
		$this->assertFalse( \diluxone_mail_tab_open( 'sender', \diluxone_mail_settings_progress() ) );

		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_host' => 'smtp.x.test' ) );
		\diluxone_mail_verified( 'connection' );
		$this->assertSame( 'sender', \diluxone_mail_current_tab() );

		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_from' => 'hello@x.test' ) );
		$this->assertSame( 'test', \diluxone_mail_current_tab() );

		\diluxone_mail_verified( 'message' );
		// Everything done: the screen stops pushing and opens where asked.
		$this->assertSame( 'profile', \diluxone_mail_current_tab() );
	}

	public function test_a_later_step_stays_closed_over_an_earlier_gap(): void {
		// The From address survives from an earlier configuration while the
		// server it would send through has never answered. Asking only the
		// neighbouring step let the last one open here, and offered to send a
		// test message through a server that was never verified.
		$this->conexion(
			array(
				'diluxone_mail_provider' => 'mailjet',
				'diluxone_mail_from'     => 'hello@x.test',
			)
		);

		$progress = \diluxone_mail_settings_progress();

		$this->assertTrue( $progress['sender'] );
		$this->assertFalse( $progress['server'] );
		$this->assertFalse( \diluxone_mail_tab_open( 'test', $progress ) );
		$this->assertFalse( \diluxone_mail_tab_open( 'sender', $progress ) );

		// And the reason names the gap, not the neighbour.
		\ob_start();
		\diluxone_mail_tabs_nav( 'profile', 'site' );
		$html = (string) \ob_get_clean();
		$this->assertStringContainsString( 'Test the connection on the previous tab', $html );

		\diluxone_mail_verified( 'connection' );
		$this->assertTrue( \diluxone_mail_tab_open( 'test', \diluxone_mail_settings_progress() ) );
	}

	public function test_saving_the_sender_moves_on_to_the_last_step(): void {
		$id = $this->conexion( array( 'diluxone_mail_provider' => 'mailjet', 'diluxone_mail_host' => 'smtp.x.test' ) );
		\diluxone_mail_verified( 'connection' );

		$_POST = array(
			'scope'              => 'site',
			'tab'                => 'sender',
			'connection'         => $id,
			'diluxone_mail_from' => 'hello@x.test',
		);
		$_REQUEST = $_POST;

		$url = $this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertStringContainsString( 'tab=test', $url );
		$this->assertStringContainsString( 'connection=' . $id, $url );

		// A settings tab is not a step and stays where it was.
		$_POST = array( 'scope' => 'site', 'tab' => 'logging' );
		$this->assertStringContainsString( 'tab=logging', $this->redirect_of( 'diluxone_mail_save_settings' ) );
	}

	public function test_a_sender_that_does_not_open_the_last_step_stays_put(): void {
		// No verified server behind it, so there is nowhere to move on to.
		$id = $this->conexion( array( 'diluxone_mail_provider' => 'mailjet' ) );

		$_POST = array(
			'scope'              => 'site',
			'tab'                => 'sender',
			'connection'         => $id,
			'diluxone_mail_from' => 'hello@x.test',
		);
		$_REQUEST = $_POST;

		$this->assertStringContainsString( 'tab=sender', $this->redirect_of( 'diluxone_mail_save_settings' ) );
	}

	public function test_a_closed_tab_cannot_be_reached_by_asking_for_it(): void {
		$_GET['tab'] = 'test';

		$this->assertSame( 'profile', \diluxone_mail_current_tab() );

		$this->configured();
		$this->assertSame( 'test', \diluxone_mail_current_tab() );
	}

	public function test_changing_a_verified_credential_closes_the_step_again(): void {
		$id = $this->conexion(
			array(
				'diluxone_mail_host' => 'smtp.x.test',
				'diluxone_mail_pass' => 'una',
			)
		);

		\diluxone_mail_verified( 'connection' );

		$this->assertTrue( \diluxone_mail_connection_verified() );

		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_pass' => 'otra' ) );
		$this->assertFalse( \diluxone_mail_connection_verified() );

		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_pass' => 'una' ) );
		$this->assertTrue( \diluxone_mail_connection_verified() );

		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_port' => 2525 ) );
		$this->assertFalse( \diluxone_mail_connection_verified() );
	}

	public function test_the_row_of_tabs_locks_what_is_not_reachable(): void {
		$this->conexion( array( 'diluxone_mail_provider' => 'mailjet' ) );

		\ob_start();
		\diluxone_mail_tabs_nav( 'server', 'site' );
		$html = (string) \ob_get_clean();

		// Done, current, and locked, each said in its own way.
		$this->assertStringContainsString( 'tab=profile', $html );
		$this->assertStringContainsString( 'diluxone-mail-tab-done', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( 'diluxone-mail-tab-locked', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		// A locked step is not a link.
		$this->assertStringNotContainsString( 'tab=test"', $html );
	}

	public function test_the_reason_a_tab_is_closed_names_the_step_before_it(): void {
		$this->assertStringContainsString( 'provider profile', \diluxone_mail_tab_blocked_reason( 'server' ) );
		$this->assertStringContainsString( 'Test the connection', \diluxone_mail_tab_blocked_reason( 'sender' ) );
		$this->assertStringContainsString( 'From address', \diluxone_mail_tab_blocked_reason( 'test' ) );
		$this->assertSame( '', \diluxone_mail_tab_blocked_reason( 'logging' ) );
	}

	public function test_the_network_screen_has_a_tab_a_site_does_not(): void {
		$this->assertArrayHasKey( 'sites', \diluxone_mail_settings_tabs( 'network' ) );
		$this->assertArrayNotHasKey( 'sites', \diluxone_mail_settings_tabs( 'site' ) );
		$this->assertStringContainsString( 'settings.php?page=diluxone-mail-network', \diluxone_mail_tab_url( 'sites', 'network' ) );
		// The four steps live on their own screen; the rest on the settings one.
		$this->assertStringContainsString( 'page=diluxone-mail-provider', \diluxone_mail_tab_url( 'sender' ) );
		$this->assertStringContainsString( 'page=diluxone-mail-settings', \diluxone_mail_tab_url( 'logging' ) );
		$this->assertSame( array( 'profile', 'server', 'sender', 'test' ), array_keys( \diluxone_mail_settings_tabs( 'site', 'provider' ) ) );
		$this->assertSame( array( 'sending', 'logging' ), array_keys( \diluxone_mail_settings_tabs( 'site', 'settings' ) ) );
	}

	public function test_the_overview_before_anything_is_set_up(): void {
		$this->db->next_results = array();

		$html = $this->render( 'diluxone_mail_screen_overview' );

		$this->assertStringContainsString( 'watching, not sending', $html );
		$this->assertStringContainsString( 'no server configured yet', $html );
		$this->assertStringContainsString( '4 steps left', $html );
		$this->assertStringContainsString( 'has not been diagnosed yet', $html );
		$this->assertStringContainsString( 'diluxone_mail_test', $html );
	}

	public function test_the_overview_welcomes_and_balances_its_markup(): void {
		$this->db->next_results = array();

		$html = $this->render( 'diluxone_mail_screen_overview' );

		$this->assertStringContainsString( 'Welcome to DiluxOne Mail', $html );
		$this->assertStringContainsString( 'Continue the setup', $html );
		$this->assertStringContainsString( 'dashicons-visibility', $html );
		$this->assertStringContainsString( 'diluxone-mail-card--warn', $html );

		// The cards are opened by a helper and closed by the template, which
		// is exactly the shape that ends up with a stray </div> nobody sees
		// until the admin menu collapses.
		$this->assertSame(
			substr_count( $html, '<div' ),
			substr_count( $html, '</div>' ),
			'the overview leaves a div unbalanced'
		);
	}

	public function test_the_overview_once_it_is_all_done(): void {
		$this->configured();
		\diluxone_mail_verified( 'message' );
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_last_result', array( 'ok' => 1, 'time' => time() - 60, 'error' => '', 'provider' => 'mailjet' ) );
		$this->db->next_results = array( array( 'status' => 'sent', 'n' => 12 ), array( 'status' => 'failed', 'n' => 2 ) );

		$html = $this->render( 'diluxone_mail_screen_overview' );

		$this->assertStringContainsString( 'sends the mail of this site', $html );
		$this->assertStringContainsString( 'Mailjet', $html );
		$this->assertStringContainsString( 'hello@x.test', $html );
		$this->assertStringContainsString( 'All four steps are done', $html );
		$this->assertStringContainsString( '12 sent, 2 failed', $html );
	}

	public function test_the_overview_reads_the_diagnosis_only_from_the_cache(): void {
		$this->configured();
		$this->db->next_results = array();

		// Nothing cached: it says so rather than running twenty lookups.
		$this->assertStringContainsString( 'has not been diagnosed yet', $this->render( 'diluxone_mail_screen_overview' ) );

		\set_site_transient(
			'diluxone_mail_diagnosis_' . md5( 'x.test' ),
			array(
				'findings' => array(
					array( 'level' => 'error' ),
					array( 'level' => 'warning' ),
					array( 'level' => 'ok' ),
				),
			),
			60
		);

		$this->db->next_results = array();
		$html                   = $this->render( 'diluxone_mail_screen_overview' );

		$this->assertStringContainsString( '1 problem found', $html );
		$this->assertStringContainsString( 'x.test', $html );
	}

	public function test_a_clean_diagnosis_says_there_is_nothing_to_fix(): void {
		$this->configured();
		\set_site_transient( 'diluxone_mail_diagnosis_' . md5( 'x.test' ), array( 'findings' => array( array( 'level' => 'ok' ) ) ), 60 );
		$this->db->next_results = array();

		$this->assertStringContainsString( 'Nothing to fix', $this->render( 'diluxone_mail_screen_overview' ) );
	}

	public function test_the_deliverability_screen_carries_the_options_of_the_diagnosis(): void {
		$this->configured();
		\update_option( 'diluxone_mail_dns_selectors', array( 'uno', 'dos' ) );

		$html = $this->render( 'diluxone_mail_screen_dns' );

		$this->assertStringContainsString( 'Options of the diagnosis', $html );
		$this->assertStringContainsString( 'name="diluxone_mail_dns_selectors"', $html );
		$this->assertStringContainsString( 'uno, dos', $html );
		$this->assertStringContainsString( 'value="dns"', $html );
	}

	public function test_a_test_message_marks_the_last_step_and_can_come_back_to_the_overview(): void {
		$this->configured();
		$_POST = array( 'scope' => 'site', 'diluxone_mail_test_to' => 'a@x.test' );

		$url = $this->redirect_of( 'diluxone_mail_test_action' );

		$this->assertStringContainsString( 'tab=test', $url );
		$this->assertTrue( \diluxone_mail_test_passed() );

		// Change the sender and the proof no longer applies to what is stored.
		\diluxone_mail_connection_put( \diluxone_mail_default_id(), array( 'diluxone_mail_host' => 'smtp.other.test' ) );
		$this->assertFalse( \diluxone_mail_test_passed() );

		$_POST = array( 'scope' => 'site', 'diluxone_mail_test_to' => 'a@x.test', 'return' => 'overview' );
		$url   = $this->redirect_of( 'diluxone_mail_test_action' );

		$this->assertStringContainsString( 'page=diluxone-mail', $url );
		$this->assertStringNotContainsString( 'tab=', $url );
	}

	public function test_the_test_message_goes_through_the_provider_being_set_up(): void {
		// The one that sends the site's mail, and a second one being set up.
		$this->configured();

		$otro = $this->conexion(
			array(
				'diluxone_mail_provider' => 'mailpit',
				'diluxone_mail_host'     => 'mailpit',
				'diluxone_mail_from'     => 'dev@x.test',
			)
		);

		$_POST    = array( 'scope' => 'site', 'connection' => $otro, 'diluxone_mail_test_to' => 'a@x.test' );
		$_REQUEST = $_POST;

		$this->redirect_of( 'diluxone_mail_test_action' );

		// Pressing Send test on the fourth step of one provider and having the
		// message leave through another answers nothing.
		$this->assertSame( $otro, \diluxone_mail_active_id() );
		// And what it proved was proved about that one: the mark is a
		// fingerprint, and an empty one is no mark at all.
		$this->assertNotSame( '', (string) ( \diluxone_mail_connection( $otro )['verified']['message'] ?? '' ) );
		$this->assertSame( '', (string) ( \diluxone_mail_connection( \diluxone_mail_default_id() )['verified']['message'] ?? '' ) );
	}

	public function test_a_test_message_is_not_retried_through_the_next_provider(): void {
		$this->conexion( array( 'diluxone_mail_provider' => 'mailjet', 'diluxone_mail_host' => 'uno', 'diluxone_mail_from' => 'a@x.test' ) );
		$this->conexion( array( 'diluxone_mail_provider' => 'sendgrid', 'diluxone_mail_host' => 'dos', 'diluxone_mail_from' => 'a@x.test' ) );

		\update_option( 'diluxone_mail_mode', 'transport' );
		\add_action( 'wp_mail_failed', 'diluxone_mail_failover', 20 );

		$GLOBALS['_test_wp_mail_fails'] = 'nope';

		$resultado = \diluxone_mail_send_test( 'a@x.test' );

		$this->assertFalse( $resultado['ok'] );
		// One attempt: a test is about the provider you are testing.
		$this->assertCount( 1, $GLOBALS['_test_wp_mail_calls'] );
		// And the guard is left as it was found.
		$this->assertFalse( \diluxone_mail_failing_over() );
	}

	public function test_a_failed_send_does_not_mark_the_step(): void {
		$this->configured();
		$GLOBALS['_test_wp_mail_fails'] = 'no route to host';
		$_POST                          = array( 'scope' => 'site', 'diluxone_mail_test_to' => 'a@x.test' );

		$this->redirect_of( 'diluxone_mail_test_action' );

		$this->assertFalse( \diluxone_mail_test_passed() );
	}
}
