<?php
/**
 * The screen that lists the configured providers, and the four steps over it.
 */

namespace Tests\Unit\DiluxOneMail;

class ProviderListTest extends AdminTestCase {

	public function test_with_nothing_configured_it_asks_for_the_first_one(): void {
		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'No provider configured yet', $html );
		$this->assertStringContainsString( 'Add a provider', $html );
		// Nothing to order, and nothing open over it.
		$this->assertStringNotContainsString( 'diluxone-mail-connections', $html );
		$this->assertStringNotContainsString( 'diluxone-mail-backdrop', $html );
	}

	public function test_the_list_says_what_each_one_is_for(): void {
		$this->conexion(
			array(
				'diluxone_mail_provider'  => 'mailjet',
				'diluxone_mail_transport' => 'smtp',
				'diluxone_mail_from'      => 'hello@x.test',
			)
		);

		$this->conexion(
			array(
				'diluxone_mail_provider'  => 'mailtrap_sending',
				'diluxone_mail_transport' => 'api',
				'diluxone_mail_from'      => 'otro@x.test',
			)
		);

		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'Mailjet', $html );
		$this->assertStringContainsString( 'Mailtrap', $html );
		$this->assertStringContainsString( 'SMTP', $html );
		$this->assertStringContainsString( 'API', $html );

		// The order is the meaning, and the first column says it in words.
		$this->assertStringContainsString( 'Sends', $html );
		$this->assertStringContainsString( 'If that fails', $html );

		// Neither is verified, and the list does not pretend otherwise.
		$this->assertStringContainsString( 'Half set up', $html );
	}

	public function test_the_steps_open_over_the_list(): void {
		$id = $this->conexion( array( 'diluxone_mail_provider' => 'mailjet' ) );

		$this->panel( $id );

		$html = $this->render( 'diluxone_mail_screen_provider' );

		// The list is still there behind it.
		$this->assertStringContainsString( 'diluxone-mail-connections', $html );
		$this->assertStringContainsString( 'diluxone-mail-backdrop', $html );
		$this->assertStringContainsString( 'nav-tab', $html );
		// And the way out goes back to the list.
		$this->assertStringContainsString( 'page=diluxone-mail-provider', $html );
		// Every form says which provider it is writing into.
		$this->assertStringContainsString( 'name="connection" value="' . $id . '"', $html );
	}

	public function test_the_arrows_move_one_and_the_list_keeps_the_rest(): void {
		$uno = $this->conexion( array( 'diluxone_mail_host' => 'uno' ) );
		$dos = $this->conexion( array( 'diluxone_mail_host' => 'dos' ) );

		$_POST = array( 'connection' => $dos, 'up' => '1' );

		$this->assertStringContainsString( 'reordered', $this->redirect_of( 'diluxone_mail_connection_move_action' ) );
		$this->assertSame( array( $dos, $uno ), array_keys( \diluxone_mail_connections() ) );

		$_POST = array( 'connection' => $dos, 'down' => '1' );

		$this->redirect_of( 'diluxone_mail_connection_move_action' );
		$this->assertSame( array( $uno, $dos ), array_keys( \diluxone_mail_connections() ) );

		// The first one cannot go higher, and nothing breaks asking.
		$_POST = array( 'connection' => $uno, 'up' => '1' );
		$this->redirect_of( 'diluxone_mail_connection_move_action' );
		$this->assertSame( array( $uno, $dos ), array_keys( \diluxone_mail_connections() ) );
	}

	public function test_dropping_one_somewhere_sends_the_whole_order(): void {
		$uno  = $this->conexion( array( 'diluxone_mail_host' => 'uno' ) );
		$dos  = $this->conexion( array( 'diluxone_mail_host' => 'dos' ) );
		$tres = $this->conexion( array( 'diluxone_mail_host' => 'tres' ) );

		$_POST = array( 'order' => $tres . ',' . $uno . ',' . $dos );

		$this->assertStringContainsString( 'reordered', $this->redirect_of( 'diluxone_mail_connections_reorder_action' ) );
		$this->assertSame( array( $tres, $uno, $dos ), array_keys( \diluxone_mail_connections() ) );
		$this->assertSame( 'tres', \diluxone_mail_option( 'diluxone_mail_host' ) );
	}

	public function test_removing_one_takes_its_credentials_with_it(): void {
		$uno = $this->conexion( array( 'diluxone_mail_host' => 'uno', 'diluxone_mail_pass' => \diluxone_mail_encrypt( 'secreta' ) ) );
		$dos = $this->conexion( array( 'diluxone_mail_host' => 'dos' ) );

		$_POST = array( 'connection' => $uno );

		$this->assertStringContainsString( 'forgotten', $this->redirect_of( 'diluxone_mail_connection_forget_action' ) );
		$this->assertSame( array( $dos ), array_keys( \diluxone_mail_connections() ) );
		// Whoever was behind moves up, and the site keeps sending.
		$this->assertSame( 'dos', \diluxone_mail_option( 'diluxone_mail_host' ) );
	}

	public function test_two_of_the_same_provider_can_be_told_apart(): void {
		$id = $this->conexion( array( 'diluxone_mail_provider' => 'mailjet', 'diluxone_mail_from' => 'uno@x.test' ) );

		$_POST = array( 'connection' => $id, 'label' => 'El de facturación' );

		$this->assertStringContainsString( 'renamed', $this->redirect_of( 'diluxone_mail_connection_rename_action' ) );
		$this->assertStringContainsString( 'El de facturación', $this->render( 'diluxone_mail_screen_provider' ) );

		// A name nobody gave falls back to what the row is.
		$_POST = array( 'connection' => $id, 'label' => '' );
		$this->redirect_of( 'diluxone_mail_connection_rename_action' );
		$this->assertStringContainsString( 'Mailjet — uno@x.test', $this->render( 'diluxone_mail_screen_provider' ) );
	}

	public function test_the_name_is_given_where_the_provider_is_chosen(): void {
		$_POST = array(
			'scope'                  => 'site',
			'diluxone_mail_provider' => 'mailjet',
			'label'                  => 'El de facturación',
		);

		$this->redirect_of( 'diluxone_mail_apply_provider' );

		$this->assertStringContainsString( 'El de facturación', $this->render( 'diluxone_mail_screen_provider' ) );
	}

	public function test_none_of_it_without_the_capability(): void {
		$GLOBALS['_test_can'] = false;

		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_connections_authorize();
	}
}
