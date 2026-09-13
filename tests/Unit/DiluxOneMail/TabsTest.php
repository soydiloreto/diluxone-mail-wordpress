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
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		$this->assertSame( 'server', \diluxone_mail_current_tab() );
		$this->assertFalse( \diluxone_mail_tab_open( 'sender', \diluxone_mail_settings_progress() ) );

		\update_option( 'diluxone_mail_host', 'smtp.x.test' );
		\diluxone_mail_verified( 'connection' );
		$this->assertSame( 'sender', \diluxone_mail_current_tab() );

		\update_option( 'diluxone_mail_from', 'hello@x.test' );
		$this->assertSame( 'test', \diluxone_mail_current_tab() );

		\diluxone_mail_verified( 'message' );
		// Everything done: the screen stops pushing and opens where asked.
		$this->assertSame( 'profile', \diluxone_mail_current_tab() );
	}

	public function test_a_closed_tab_cannot_be_reached_by_asking_for_it(): void {
		$_GET['tab'] = 'test';

		$this->assertSame( 'profile', \diluxone_mail_current_tab() );

		$this->configured();
		$this->assertSame( 'test', \diluxone_mail_current_tab() );
	}

	public function test_changing_a_verified_credential_closes_the_step_again(): void {
		\update_option( 'diluxone_mail_host', 'smtp.x.test' );
		\update_option( 'diluxone_mail_pass', 'una' );
		\diluxone_mail_verified( 'connection' );

		$this->assertTrue( \diluxone_mail_connection_verified() );

		\update_option( 'diluxone_mail_pass', 'otra' );
		$this->assertFalse( \diluxone_mail_connection_verified() );

		\update_option( 'diluxone_mail_pass', 'una' );
		$this->assertTrue( \diluxone_mail_connection_verified() );

		\update_option( 'diluxone_mail_port', 2525 );
		$this->assertFalse( \diluxone_mail_connection_verified() );
	}

	public function test_the_row_of_tabs_locks_what_is_not_reachable(): void {
		\update_option( 'diluxone_mail_provider', 'mailjet' );

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
		$this->assertStringContainsString( 'page=diluxone-mail-settings', \diluxone_mail_tab_url( 'sender' ) );
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
		\update_option( 'diluxone_mail_host', 'smtp.other.test' );
		$this->assertFalse( \diluxone_mail_test_passed() );

		$_POST = array( 'scope' => 'site', 'diluxone_mail_test_to' => 'a@x.test', 'return' => 'overview' );
		$url   = $this->redirect_of( 'diluxone_mail_test_action' );

		$this->assertStringContainsString( 'page=diluxone-mail', $url );
		$this->assertStringNotContainsString( 'tab=', $url );
	}

	public function test_a_failed_send_does_not_mark_the_step(): void {
		$this->configured();
		$GLOBALS['_test_wp_mail_fails'] = 'no route to host';
		$_POST                          = array( 'scope' => 'site', 'diluxone_mail_test_to' => 'a@x.test' );

		$this->redirect_of( 'diluxone_mail_test_action' );

		$this->assertFalse( \diluxone_mail_test_passed() );
	}
}
