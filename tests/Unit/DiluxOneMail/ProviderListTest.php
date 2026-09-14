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

	/**
	 * A dialog that says it is one has to behave like one.
	 *
	 * aria-modal tells a screen reader to stop announcing everything behind
	 * the panel. If the focus can then walk out of it — into the list, into
	 * the admin menu — the person is standing on controls nobody is telling
	 * them about, with no way back. The script traps Tab and closes on
	 * Escape; what the markup has to carry for that to be possible is the
	 * tabindex, without which the focus cannot be put inside on open.
	 */
	public function test_the_panel_can_be_focused_the_way_a_dialog_must(): void {
		$this->panel( $this->conexion( array( 'diluxone_mail_provider' => 'mailjet' ) ) );

		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
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

	public function test_adding_one_never_writes_over_the_one_in_charge(): void {
		$viejo = $this->conexion(
			array(
				'diluxone_mail_provider' => 'mailtrap_sending',
				'diluxone_mail_host'     => 'el.que.ya.estaba',
				'diluxone_mail_from'     => 'hello@x.test',
			)
		);

		// Exactly what the first step submits for a provider that does not
		// exist yet: no id, and the marker that says so.
		$_POST = array(
			'scope'                  => 'site',
			'new'                    => '1',
			'diluxone_mail_provider' => 'sendgrid',
			'label'                  => 'El nuevo',
		);
		$_REQUEST = $_POST;

		$this->redirect_of( 'diluxone_mail_apply_provider' );

		$conexiones = \diluxone_mail_connections();

		$this->assertCount( 2, $conexiones );
		// The one that was there is untouched, and still the one that sends.
		$this->assertSame( 'el.que.ya.estaba', $conexiones[ $viejo ]['diluxone_mail_host'] );
		$this->assertSame( $viejo, \diluxone_mail_default_id() );
	}

	public function test_the_first_step_of_a_new_one_carries_the_marker(): void {
		$this->conexion( array( 'diluxone_mail_provider' => 'mailjet' ) );

		$_GET     = array( 'new' => '1' );
		$_REQUEST = $_GET;

		$html = $this->render( 'diluxone_mail_screen_provider' );
		// Only the panel: the rows behind it each carry their own id, which is
		// how the arrows and the remove button know what they act on.
		$panel = substr( $html, (int) strpos( $html, 'diluxone-mail-backdrop' ) );

		$this->assertStringContainsString( 'name="new" value="1"', $panel );
		$this->assertStringNotContainsString( 'name="connection"', $panel );
	}

	public function test_opening_a_new_one_over_a_configured_list_starts_at_the_first_step(): void {
		// The flow exactly as a browser walks it: a provider already set up and
		// verified, and then the link that adds another. Drawing the list must
		// not leave the panel pointing at a row of it.
		$this->configured();

		$_GET     = array( 'page' => 'diluxone-mail-provider', 'new' => '1' );
		$_REQUEST = $_GET;

		$html = $this->render( 'diluxone_mail_screen_provider' );

		$panel = substr( $html, (int) strpos( $html, 'diluxone-mail-backdrop' ) );

		// The first step, with nothing done and nothing borrowed.
		$this->assertStringContainsString( 'diluxone_mail_apply_provider', $panel );
		$this->assertStringNotContainsString( 'diluxone-mail-tab-done', $html );
		$this->assertStringNotContainsString( 'diluxone_mail_api_key', $panel );
		$this->assertSame( 'profile', \diluxone_mail_current_tab( 'site', 'provider' ) );
	}

	public function test_drawing_the_list_leaves_the_request_where_it_found_it(): void {
		$this->configured();

		$_GET     = array( 'new' => '1' );
		$_REQUEST = $_GET;

		\diluxone_mail_focus_editing();
		$antes = \diluxone_mail_focus();

		\diluxone_mail_connections_data();

		// Each row points the request at itself for a moment; all of them put
		// it back, and what they put back is what was there.
		$this->assertSame( $antes, \diluxone_mail_focus() );
		$this->assertSame( '', \diluxone_mail_active_id() );
	}

	public function test_a_notice_goes_inside_whatever_is_open_over_the_list(): void {
		$id = $this->conexion( array( 'diluxone_mail_provider' => 'mailjet' ) );

		$_GET = array( 'diluxone_mail_done' => 'saved' );
		$this->panel( $id );

		$html  = $this->render( 'diluxone_mail_screen_provider' );
		$antes = substr( $html, 0, (int) strpos( $html, 'diluxone-mail-backdrop' ) );

		// Behind a dialog is where a notice goes unread.
		$this->assertStringNotContainsString( 'Settings saved', $antes );
		$this->assertStringContainsString( 'Settings saved', $html );

		// With nothing open it is where it always was.
		$_GET     = array( 'diluxone_mail_done' => 'saved' );
		$_REQUEST = $_GET;

		$html = $this->render( 'diluxone_mail_screen_provider' );
		$this->assertStringContainsString( 'Settings saved', $html );
		$this->assertStringNotContainsString( 'diluxone-mail-backdrop', $html );
	}

	public function test_closing_says_what_the_provider_ended_up_being(): void {
		$id = $this->conexion(
			array(
				'diluxone_mail_provider' => 'mailjet',
				'diluxone_mail_host'     => 'smtp.x.test',
				'diluxone_mail_from'     => 'hello@x.test',
			)
		);

		// Half set up: it says so rather than congratulating anybody.
		$_GET     = array( 'closed' => $id );
		$_REQUEST = $_GET;

		$this->assertStringContainsString( 'still has a step open', $this->render( 'diluxone_mail_screen_provider' ) );

		// Finished, and first: it is the one that sends.
		\diluxone_mail_active_id( $id );
		\diluxone_mail_verified( 'connection' );
		\diluxone_mail_active_id( '' );

		$_GET     = array( 'closed' => $id );
		$_REQUEST = $_GET;

		$html = $this->render( 'diluxone_mail_screen_provider' );
		$this->assertStringContainsString( 'is the one that sends', $html );

		// Finished, and second: it says what it is for instead.
		$otro = $this->conexion( array( 'diluxone_mail_provider' => 'sendgrid' ) );
		\diluxone_mail_connection_promote( $otro );

		$_GET     = array( 'closed' => $id );
		$_REQUEST = $_GET;

		$html = $this->render( 'diluxone_mail_screen_provider' );
		$this->assertStringContainsString( 'only be used if that one will not take a message', $html );
	}

	public function test_each_row_is_judged_against_itself(): void {
		// One verified provider and one that is not, in that order. Asking the
		// list means asking each row about its own marks: reading one row's
		// fingerprint against another row's marks says nobody is verified.
		$verificado = $this->conexion(
			array(
				'diluxone_mail_provider' => 'mailjet',
				'diluxone_mail_host'     => 'smtp.x.test',
				'diluxone_mail_from'     => 'hello@x.test',
			)
		);

		\diluxone_mail_active_id( $verificado );
		\diluxone_mail_verified( 'connection' );
		\diluxone_mail_active_id( '' );

		$a_medias = $this->conexion( array( 'diluxone_mail_provider' => 'sendgrid' ) );

		$por_id = array_column( \diluxone_mail_connections_data()['rows'], null, 'id' );

		$this->assertTrue( $por_id[ $verificado ]['ready'] );
		$this->assertFalse( $por_id[ $a_medias ]['ready'] );

		// And the order it is asked in does not change the answer.
		\diluxone_mail_connection_promote( $a_medias );

		$por_id = array_column( \diluxone_mail_connections_data()['rows'], null, 'id' );

		$this->assertTrue( $por_id[ $verificado ]['ready'] );
		$this->assertFalse( $por_id[ $a_medias ]['ready'] );
	}

	public function test_none_of_it_without_the_capability(): void {
		$GLOBALS['_test_can'] = false;

		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_connections_authorize();
	}
}
