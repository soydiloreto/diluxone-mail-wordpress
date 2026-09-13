<?php
/**
 * The section on a person's profile.
 */

namespace Tests\Unit\DiluxOneMail;

class UserProfileTest extends AdminTestCase {

	public function test_the_profile_shows_their_own_with_the_resend_button(): void {
		$user                   = $this->user( 3, 'Ana@X.test' );
		$this->db->next_var     = 30;
		$this->db->next_results = array( $this->row(), $this->row( array( 'status' => 'failed', 'error' => 'boom' ) ) );
		\update_option( 'diluxone_mail_log_body', 1 );

		$html = $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) );

		$this->assertStringContainsString( 'Mail sent to this person', $html );
		$this->assertStringContainsString( 'ana@x.test', $html );
		$this->assertStringContainsString( '30 messages', $html );
		$this->assertStringContainsString( 'boom', $html );
		$this->assertStringContainsString( 'diluxone_mail_resend', $html );
		$this->assertStringContainsString( 'See all in the mail log', $html );
		$this->assertStringContainsString( "email IN ('ana@x.test')", $this->db->of( 'get_results' )[0]['sql'] );
	}

	public function test_with_no_body_there_is_no_button_and_with_nothing_it_says_so(): void {
		$user = $this->user( 3, 'ana@x.test' );

		$html = $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) );

		$this->assertStringContainsString( 'Nothing yet', $html );
		$this->assertStringNotContainsString( 'diluxone_mail_resend', $html );
		$this->assertStringContainsString( 'cannot be resent', $html );
	}

	public function test_with_the_log_off_or_without_permission(): void {
		$user = $this->user( 3, 'ana@x.test' );

		\update_option( 'diluxone_mail_log_enabled', 0 );
		$this->assertStringContainsString( 'The mail log is off', $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) ) );

		$GLOBALS['_test_can'] = false;
		$this->assertSame( '', $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) ) );
	}

	public function test_on_a_network_each_rows_site_is_shown(): void {
		$GLOBALS['_test_multisite'] = true;
		$GLOBALS['_test_sites'][]   = new \WP_Site( 1, 'Principal' );
		$user                       = $this->user( 3, 'ana@x.test' );
		$this->db->next_results     = array( $this->row() );

		$this->assertStringContainsString( 'Principal', $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) ) );
	}

	public function test_previous_addresses_arrive_through_the_filter(): void {
		\add_filter( 'diluxone_mail_user_emails', static fn( array $e ) => array_merge( $e, array( 'Vieja@X.test', '' ) ), 10, 1 );

		$this->assertSame( array( 'ana@x.test', 'vieja@x.test' ), \diluxone_mail_user_emails( $this->user( 3, 'ana@x.test' ) ) );
	}
}
