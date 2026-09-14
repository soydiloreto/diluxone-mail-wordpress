<?php
/**
 * More than one provider configured at a time: the list, its order, and what
 * happens to a site that had only ever had one.
 */

namespace Tests\Unit\DiluxOneMail;

class ConnectionsTest extends AdminTestCase {

	public function test_the_first_on_the_list_is_the_one_that_sends(): void {
		$this->assertSame( '', \diluxone_mail_default_id() );

		$uno = $this->conexion( array( 'diluxone_mail_host' => 'smtp.uno.test' ) );
		$dos = $this->conexion( array( 'diluxone_mail_host' => 'smtp.dos.test' ) );

		$this->assertSame( $uno, \diluxone_mail_default_id() );
		$this->assertSame( 'smtp.uno.test', \diluxone_mail_option( 'diluxone_mail_host' ) );

		// Putting one in charge is moving it to the top; there is no second
		// setting that could disagree with the list.
		\diluxone_mail_connection_promote( $dos );

		$this->assertSame( $dos, \diluxone_mail_default_id() );
		$this->assertSame( 'smtp.dos.test', \diluxone_mail_option( 'diluxone_mail_host' ) );
	}

	public function test_the_next_one_down_is_the_one_to_try(): void {
		$uno  = $this->conexion( array( 'diluxone_mail_host' => 'uno' ) );
		$dos  = $this->conexion( array( 'diluxone_mail_host' => 'dos' ) );
		$tres = $this->conexion( array( 'diluxone_mail_host' => 'tres' ) );

		$this->assertSame( $dos, \diluxone_mail_fallback_id() );
		$this->assertSame( $tres, \diluxone_mail_fallback_id( $dos ) );
		// Nothing after the last one: the chain ends rather than wrapping.
		$this->assertSame( '', \diluxone_mail_fallback_id( $tres ) );
		$this->assertSame( array( $dos, $tres ), \diluxone_mail_connection_chain( $uno ) );
		$this->assertSame( array(), \diluxone_mail_connection_chain( 'no-existe' ) );
	}

	public function test_reordering_keeps_what_the_browser_did_not_mention(): void {
		$uno  = $this->conexion( array( 'diluxone_mail_host' => 'uno' ) );
		$dos  = $this->conexion( array( 'diluxone_mail_host' => 'dos' ) );
		$tres = $this->conexion( array( 'diluxone_mail_host' => 'tres' ) );

		// A reorder that names two of three, and an id that is not ours.
		\diluxone_mail_connections_reorder( array( $tres, 'cn_inventado', $uno ) );

		$this->assertSame( array( $tres, $uno, $dos ), array_keys( \diluxone_mail_connections() ) );
	}

	public function test_forgetting_one_moves_the_rest_up(): void {
		$uno = $this->conexion( array( 'diluxone_mail_host' => 'uno' ) );
		$dos = $this->conexion( array( 'diluxone_mail_host' => 'dos' ) );

		\diluxone_mail_connection_forget( $uno );

		$this->assertSame( array( $dos ), array_keys( \diluxone_mail_connections() ) );
		$this->assertSame( $dos, \diluxone_mail_default_id() );
	}

	public function test_editing_one_is_not_the_same_as_sending_through_it(): void {
		$uno = $this->conexion( array( 'diluxone_mail_host' => 'uno' ) );
		$dos = $this->conexion( array( 'diluxone_mail_host' => 'dos' ) );

		// Nothing asked for: the screen opens on the one in charge.
		$this->assertSame( $uno, \diluxone_mail_editing_id() );

		$_REQUEST['connection'] = $dos;
		$this->assertSame( $dos, \diluxone_mail_editing_id() );
		// And the mail keeps going out through the first until told otherwise.
		$this->assertSame( 'uno', \diluxone_mail_option( 'diluxone_mail_host' ) );

		\diluxone_mail_active_id( $dos );
		$this->assertSame( 'dos', \diluxone_mail_option( 'diluxone_mail_host' ) );

		\diluxone_mail_active_id( '' );
		$this->assertSame( 'uno', \diluxone_mail_option( 'diluxone_mail_host' ) );

		// An id that is not on the list is a new provider, not somebody else's.
		$_REQUEST['connection'] = 'cn_inventado';
		$this->assertSame( '', \diluxone_mail_editing_id() );
	}

	public function test_a_provider_is_named_after_itself_when_nobody_named_it(): void {
		$this->assertSame(
			'Mailjet — hello@x.test',
			\diluxone_mail_connection_label(
				array(
					'diluxone_mail_provider' => 'mailjet',
					'diluxone_mail_from'     => 'hello@x.test',
				)
			)
		);

		$this->assertSame( 'Mailjet', \diluxone_mail_connection_label( array( 'diluxone_mail_provider' => 'mailjet' ) ) );
		$this->assertSame( 'El de siempre', \diluxone_mail_connection_label( array( 'label' => 'El de siempre' ) ) );
	}

	public function test_only_the_provider_fields_belong_to_a_provider(): void {
		$this->assertTrue( \diluxone_mail_is_connection_field( 'diluxone_mail_host' ) );
		$this->assertTrue( \diluxone_mail_is_connection_field( 'diluxone_mail_api_key' ) );
		$this->assertTrue( \diluxone_mail_is_connection_field( 'diluxone_mail_from' ) );

		// The log, the diagnosis and the sending mode describe the site: they
		// do not change when the mail starts going out through somebody else.
		$this->assertFalse( \diluxone_mail_is_connection_field( 'diluxone_mail_log_enabled' ) );
		$this->assertFalse( \diluxone_mail_is_connection_field( 'diluxone_mail_mode' ) );
		$this->assertFalse( \diluxone_mail_is_connection_field( 'diluxone_mail_dns_domain' ) );
	}

	public function test_the_environment_still_wins_over_the_list(): void {
		$this->conexion( array( 'diluxone_mail_host' => 'smtp.de-la-lista.test' ) );

		putenv( 'DILUXONE_MAIL_HOST=smtp.del-entorno.test' );

		$host = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.del-entorno.test', $host['value'] );
		$this->assertSame( 'env', $host['source'] );

		putenv( 'DILUXONE_MAIL_HOST' );
	}

	public function test_saving_writes_the_provider_fields_into_the_provider(): void {
		$id = $this->conexion( array( 'diluxone_mail_host' => 'viejo' ) );

		\diluxone_mail_save_options(
			array(
				'diluxone_mail_host'        => 'nuevo',
				'diluxone_mail_log_enabled' => 1,
			)
		);

		$this->assertSame( 'nuevo', \diluxone_mail_connection( $id )['diluxone_mail_host'] );
		// And the site's own settings stay where they were.
		$this->assertSame( 1, \get_option( 'diluxone_mail_log_enabled' ) );
		$this->assertArrayNotHasKey( 'diluxone_mail_log_enabled', \diluxone_mail_connection( $id ) );
	}

	public function test_a_site_that_had_one_provider_ends_up_with_a_list_of_one(): void {
		// Exactly what such a site has in the database today.
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );
		\update_option( 'diluxone_mail_port', 587 );
		\update_option( 'diluxone_mail_pass', \diluxone_mail_encrypt( 'la-de-siempre' ) );
		\update_option( 'diluxone_mail_from', 'hello@x.test' );
		\update_option( 'diluxone_mail_verified', array( 'connection' => 'huella', 'message' => '', 'time' => 7 ) );
		\update_option( 'diluxone_mail_log_enabled', 1 );

		\diluxone_mail_connections_migrate();

		$connections = \diluxone_mail_connections();
		$this->assertCount( 1, $connections );

		// It sends through the same provider, with the same credential: the
		// one thing a migration of this kind has to get right.
		$this->assertSame( 'in-v3.mailjet.com', \diluxone_mail_option( 'diluxone_mail_host' ) );
		$this->assertSame( 'la-de-siempre', \diluxone_mail_config_value( 'pass' )['value'] );
		$this->assertSame( 'hello@x.test', \diluxone_mail_option( 'diluxone_mail_from' ) );
		// What it had proven travels with it.
		$this->assertSame( 'huella', \diluxone_mail_verification()['connection'] );

		// The flat options are gone, so there is one place to read from.
		$this->assertFalse( \get_option( 'diluxone_mail_host' ) );
		$this->assertFalse( \get_option( 'diluxone_mail_verified' ) );
		// And the site's own settings were not touched.
		$this->assertSame( 1, \get_option( 'diluxone_mail_log_enabled' ) );

		// Running again changes nothing.
		\diluxone_mail_connections_migrate();
		$this->assertSame( $connections, \diluxone_mail_connections() );
	}

	public function test_a_site_that_never_configured_anything_gets_no_list(): void {
		\update_option( 'diluxone_mail_log_enabled', 1 );

		\diluxone_mail_connections_migrate();

		// An empty provider on the list is one somebody never added.
		$this->assertSame( array(), \diluxone_mail_connections() );
	}
}
