<?php
/**
 * Sending over the provider's HTTP API: the message that gets built, the
 * request that goes out, and what happens to the log either way.
 */

namespace Tests\Unit\DiluxOneMail;

class ApiTest extends AdminTestCase {

	/**
	 * The plugin's own hooks, which the base case clears between tests.
	 *
	 * The order matters and is WordPress's: the `wp_mail` filter records the
	 * send, and only afterwards does `pre_wp_mail` decide who delivers it.
	 */
	private function enganchar(): void {
		\add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_maybe_suppress', 1, 2 );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_api_send', 20, 2 );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_watch_pre_wp_mail', PHP_INT_MAX, 2 );
		\add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );
		\add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );
	}

	/** A site set up to send over Mailtrap's API. */
	private function por_api( string $key = 'tok_123' ): void {
		$this->enganchar();

		\update_option( 'diluxone_mail_provider', 'mailtrap_sending' );
		\update_option( 'diluxone_mail_transport', 'api' );
		\update_option( 'diluxone_mail_from', 'hello@x.test' );
		\update_option( 'diluxone_mail_from_name', 'X' );
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_api_key', \diluxone_mail_encrypt( $key ) );
	}

	/** What the provider answers. */
	private function responde( int $code, string $body = '{}' ): void {
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	public function test_only_some_providers_have_an_api(): void {
		$this->assertTrue( \diluxone_mail_provider_has_api( 'mailtrap_sending' ) );
		$this->assertFalse( \diluxone_mail_provider_has_api( 'ses' ) );
		$this->assertFalse( \diluxone_mail_provider_has_api( 'custom' ) );
		$this->assertSame( array(), \diluxone_mail_api_provider( 'ses' ) );
	}

	public function test_it_only_sends_over_the_api_when_everything_lines_up(): void {
		$this->assertSame( 'smtp', \diluxone_mail_transport_kind() );
		$this->assertFalse( \diluxone_mail_api_active() );

		$this->por_api();
		$this->assertSame( 'api', \diluxone_mail_transport_kind() );
		$this->assertTrue( \diluxone_mail_api_active() );

		// A provider with no API falls back rather than failing.
		\update_option( 'diluxone_mail_provider', 'ses' );
		$this->assertFalse( \diluxone_mail_api_active() );

		// And so does a missing key.
		\update_option( 'diluxone_mail_provider', 'mailtrap_sending' );
		\delete_option( 'diluxone_mail_api_key' );
		$this->assertFalse( \diluxone_mail_api_active() );
	}

	public function test_the_message_is_resolved_once_for_every_provider(): void {
		$this->por_api();

		$message = \diluxone_mail_api_message(
			array(
				'to'          => 'ana@x.test, beto@x.test',
				'subject'     => 'Hola',
				'message'     => '<p>Hola</p>',
				'headers'     => array(
					'Content-Type: text/html',
					'Cc: copia@x.test',
					'Bcc: oculta@x.test',
					'Reply-To: respuestas@x.test',
					'X-Origen: tienda',
				),
				'attachments' => array(),
			)
		);

		$this->assertSame( array( 'ana@x.test', 'beto@x.test' ), $message['to'] );
		$this->assertSame( array( 'copia@x.test' ), $message['cc'] );
		$this->assertSame( array( 'oculta@x.test' ), $message['bcc'] );
		$this->assertSame( 'respuestas@x.test', $message['reply_to'] );
		$this->assertTrue( $message['html'] );
		$this->assertSame( 'hello@x.test', $message['from'] );
		// A header the transport does not map itself still travels.
		$this->assertSame( array( 'X-Origen' => 'tienda' ), $message['headers'] );
	}

	public function test_mailtrap_gets_the_shape_mailtrap_asks_for(): void {
		$this->por_api();

		$body = \diluxone_mail_api_body_mailtrap(
			array(
				'from'      => 'hello@x.test',
				'from_name' => 'X',
				'to'        => array( 'ana@x.test' ),
				'cc'        => array( 'copia@x.test' ),
				'bcc'       => array(),
				'reply_to'  => 'respuestas@x.test',
				'subject'   => 'Hola',
				'body'      => 'texto',
				'html'      => false,
				'headers'   => array( 'X-Origen' => 'tienda' ),
				'files'     => array(
					array(
						'name'    => 'a.pdf',
						'type'    => 'application/pdf',
						'content' => 'YQ==',
					),
				),
			)
		);

		$this->assertSame( array( 'email' => 'hello@x.test', 'name' => 'X' ), $body['from'] );
		$this->assertSame( array( array( 'email' => 'ana@x.test' ) ), $body['to'] );
		$this->assertSame( array( array( 'email' => 'copia@x.test' ) ), $body['cc'] );
		// No recipients of a kind, no key: an empty list is not the same as
		// none and some providers reject it.
		$this->assertArrayNotHasKey( 'bcc', $body );
		$this->assertSame( 'texto', $body['text'] );
		$this->assertArrayNotHasKey( 'html', $body );
		$this->assertSame( 'respuestas@x.test', $body['headers']['Reply-To'] );
		$this->assertSame( 'tienda', $body['headers']['X-Origen'] );
		$this->assertSame( 'a.pdf', $body['attachments'][0]['filename'] );
	}

	public function test_the_request_carries_the_key_the_way_the_provider_wants(): void {
		$request = \diluxone_mail_api_request(
			'mailtrap_sending',
			array(
				'from'      => 'hello@x.test',
				'from_name' => '',
				'to'        => array( 'ana@x.test' ),
				'cc'        => array(),
				'bcc'       => array(),
				'reply_to'  => '',
				'subject'   => 'Hola',
				'body'      => 'texto',
				'html'      => false,
				'headers'   => array(),
				'files'     => array(),
			),
			'tok_123'
		);

		$this->assertSame( 'https://send.api.mailtrap.io/api/send', $request['url'] );
		$this->assertSame( 'Bearer tok_123', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertStringContainsString( '"subject":"Hola"', $request['args']['body'] );
	}

	public function test_a_send_that_the_provider_accepts_is_logged_as_sent(): void {
		$this->por_api();
		$this->responde( 200, '{"message_ids":["abc"]}' );

		$this->assertTrue( \wp_mail( 'ana@x.test', 'Hola', 'texto' ) );

		// It went over HTTPS, not over SMTP.
		$this->assertSame( 'POST', $GLOBALS['_test_http'][0]['method'] );
		$this->assertSame( 'https://send.api.mailtrap.io/api/send', $GLOBALS['_test_http'][0]['url'] );
		$this->assertSame( array(), $GLOBALS['_test_wp_mail_calls'] );

		// And the row is there, closed, with what the provider answered.
		$insert = $this->db->of( 'insert' );
		$this->assertCount( 1, $insert );
		$this->assertSame( 'ana@x.test', $insert[0]['args']['email'] );

		$updates = $this->db->of( 'update' );
		$this->assertSame( 'sent', end( $updates )['args']['data']['status'] );
		$this->assertStringContainsString( '200', (string) end( $updates )['args']['data']['response'] );
	}

	public function test_a_send_the_provider_refuses_carries_its_words_into_the_log(): void {
		$this->por_api();
		$this->responde( 401, '{"errors":["Incorrect API token"]}' );

		$this->assertFalse( \wp_mail( 'ana@x.test', 'Hola', 'texto' ) );

		$updates = $this->db->of( 'update' );
		$fila    = end( $updates )['args']['data'];

		$this->assertSame( 'failed', $fila['status'] );
		$this->assertStringContainsString( 'Incorrect API token', (string) $fila['error'] );
		$this->assertStringContainsString( '401', (string) $fila['error'] );
	}

	public function test_our_own_answer_is_not_read_as_another_plugin_intercepting(): void {
		$this->por_api();
		$this->responde( 200 );

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		foreach ( $this->db->of( 'update' ) as $update ) {
			$this->assertNotSame( 'intercepted', $update['args']['data']['status'] ?? '' );
		}

		// The mark is consumed, so the next send is judged on its own.
		$this->assertFalse( \diluxone_mail_api_answered() );
	}

	public function test_a_network_failure_is_a_failed_send_and_not_a_fatal(): void {
		$this->por_api();
		$GLOBALS['_test_wp_remote_post'] = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->assertFalse( \wp_mail( 'ana@x.test', 'Hola', 'texto' ) );

		$updates = $this->db->of( 'update' );
		$this->assertSame( 'failed', end( $updates )['args']['data']['status'] );
		$this->assertStringContainsString( 'cURL error 28', (string) end( $updates )['args']['data']['error'] );
	}

	public function test_without_a_sender_it_does_not_even_try(): void {
		$this->por_api();
		\delete_option( 'diluxone_mail_from' );

		$this->assertFalse( \wp_mail( 'ana@x.test', 'Hola', 'texto' ) );
		$this->assertSame( array(), $GLOBALS['_test_http'] );
	}

	public function test_the_key_is_checked_before_it_is_stored(): void {
		\update_option( 'diluxone_mail_provider', 'mailtrap_sending' );
		\update_option( 'diluxone_mail_transport', 'api' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 401 ),
			'body'     => '',
		);

		$_POST = array( 'scope' => 'site', 'tab' => 'server', 'diluxone_mail_api_key' => 'mala' );
		$url   = $this->redirect_of( 'diluxone_mail_api_key_action' );

		$this->assertStringContainsString( 'key-refused', $url );
		$this->assertFalse( \get_option( 'diluxone_mail_api_key' ) );
		$this->assertFalse( \diluxone_mail_connection_verified() );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '[]',
		);

		$_POST = array( 'scope' => 'site', 'tab' => 'server', 'diluxone_mail_api_key' => 'buena' );
		$url   = $this->redirect_of( 'diluxone_mail_api_key_action' );

		$this->assertStringContainsString( 'key-ok', $url );
		$this->assertStringContainsString( 'tab=sender', $url );
		$this->assertSame( 'buena', \diluxone_mail_api_key() );
		// Stored the way the password is: encrypted, never in the clear.
		$this->assertTrue( \diluxone_mail_is_encrypted( (string) \get_option( 'diluxone_mail_api_key' ) ) );
		$this->assertTrue( \diluxone_mail_connection_verified() );
	}

	public function test_a_provider_that_cannot_be_asked_is_not_a_refusal(): void {
		\update_option( 'diluxone_mail_provider', 'mailtrap_sending' );
		\update_option( 'diluxone_mail_transport', 'api' );

		// No network at all: the key is stored and the screen says it could
		// not be checked, rather than refusing over a question we could not
		// ask.
		$_POST = array( 'scope' => 'site', 'tab' => 'server', 'diluxone_mail_api_key' => 'quien-sabe' );

		$this->assertStringContainsString( 'key-unchecked', $this->redirect_of( 'diluxone_mail_api_key_action' ) );
		$this->assertSame( 'quien-sabe', \diluxone_mail_api_key() );
	}

	public function test_the_api_of_a_provider_that_has_none_is_refused_at_the_first_step(): void {
		$_POST = array( 'scope' => 'site', 'diluxone_mail_provider' => 'ses', 'diluxone_mail_transport' => 'api' );

		$this->assertStringContainsString( 'no-api', $this->redirect_of( 'diluxone_mail_apply_provider' ) );
		$this->assertFalse( \get_option( 'diluxone_mail_transport' ) );

		$_POST = array( 'scope' => 'site', 'diluxone_mail_provider' => 'ses', 'diluxone_mail_transport' => 'smtp' );
		$this->assertStringContainsString( 'profile-applied', $this->redirect_of( 'diluxone_mail_apply_provider' ) );
		$this->assertSame( 'smtp', \get_option( 'diluxone_mail_transport' ) );
	}

	public function test_changing_the_method_reopens_the_step_that_proved_the_old_one(): void {
		$this->por_api();
		\diluxone_mail_verified( 'connection' );

		$this->assertTrue( \diluxone_mail_connection_verified() );

		\update_option( 'diluxone_mail_transport', 'smtp' );
		$this->assertFalse( \diluxone_mail_connection_verified() );

		\update_option( 'diluxone_mail_transport', 'api' );
		$this->assertTrue( \diluxone_mail_connection_verified() );

		// And so does changing the key.
		\update_option( 'diluxone_mail_api_key', \diluxone_mail_encrypt( 'otra' ) );
		$this->assertFalse( \diluxone_mail_connection_verified() );
	}

	public function test_the_second_step_asks_for_a_key_instead_of_a_server(): void {
		$this->por_api();
		$_GET['tab'] = 'server';

		$html = $this->render( 'diluxone_mail_screen_settings' );

		$this->assertStringContainsString( 'API key', $html );
		$this->assertStringContainsString( 'diluxone_mail_api_key', $html );
		$this->assertStringContainsString( 'Check the key and save', $html );
		$this->assertStringNotContainsString( 'diluxone_mail_host', $html );
	}

	public function test_the_other_ways_a_provider_asks_for_its_key(): void {
		\add_filter(
			'diluxone_mail_api_providers',
			static function ( array $providers ): array {
				$providers['basico'] = array_merge( $providers['mailtrap_sending'], array( 'auth' => 'basic' ) );
				$providers['propia'] = array_merge( $providers['mailtrap_sending'], array( 'auth' => 'X-Postmark-Server-Token' ) );

				return $providers;
			}
		);

		$message = array(
			'from'      => 'hello@x.test',
			'from_name' => '',
			'to'        => array( 'ana@x.test' ),
			'cc'        => array(),
			'bcc'       => array(),
			'reply_to'  => '',
			'subject'   => 'Hola',
			'body'      => 'texto',
			'html'      => false,
			'headers'   => array(),
			'files'     => array(),
		);

		$basica = \diluxone_mail_api_request( 'basico', $message, 'clave:secreto' );
		$propia = \diluxone_mail_api_request( 'propia', $message, 'tok' );

		$this->assertSame( 'Basic ' . base64_encode( 'clave:secreto' ), $basica['args']['headers']['Authorization'] );
		$this->assertSame( 'tok', $propia['args']['headers']['X-Postmark-Server-Token'] );
		$this->assertArrayNotHasKey( 'Authorization', $propia['args']['headers'] );
	}

	public function test_attachments_travel_in_the_request_and_a_missing_one_does_not_stop_it(): void {
		$archivo = tempnam( sys_get_temp_dir(), 'dlx' ) . '.txt';
		file_put_contents( $archivo, 'contenido' );

		$files = \diluxone_mail_api_attachments( array( $archivo, '/no/existe.pdf', '' ) );

		$this->assertCount( 1, $files );
		$this->assertSame( basename( $archivo ), $files[0]['name'] );
		$this->assertSame( 'text/plain', $files[0]['type'] );
		$this->assertSame( 'contenido', base64_decode( $files[0]['content'], true ) );

		unlink( $archivo );

		// A type the site does not know still has to be something.
		$raro = tempnam( sys_get_temp_dir(), 'dlx' ) . '.qqq';
		file_put_contents( $raro, 'x' );
		$this->assertSame( 'application/octet-stream', \diluxone_mail_api_attachments( array( $raro ) )[0]['type'] );
		unlink( $raro );
	}

	public function test_whatever_shape_the_provider_puts_its_complaint_in(): void {
		$this->assertSame( 'uno dos', \diluxone_mail_api_error_mailtrap( array( 'errors' => array( 'uno', 'dos' ) ), 'crudo' ) );
		$this->assertSame( 'solo', \diluxone_mail_api_error_mailtrap( array( 'error' => 'solo' ), 'crudo' ) );
		// Nothing it recognises: the body itself, which is better than silence.
		$this->assertSame( 'crudo', \diluxone_mail_api_error_mailtrap( array(), 'crudo' ) );
	}

	public function test_checking_a_key_answers_three_different_things(): void {
		$GLOBALS['_test_wp_remote_get'] = array( 'response' => array( 'code' => 200 ), 'body' => '[]' );
		$bien                           = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertTrue( $bien['ok'] );
		$this->assertTrue( $bien['checked'] );

		$GLOBALS['_test_wp_remote_get'] = array( 'response' => array( 'code' => 403 ), 'body' => '' );
		$mal                            = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertFalse( $mal['ok'] );
		$this->assertTrue( $mal['checked'] );

		// The endpoint moved, or the provider is having a bad day: not a
		// refusal, and said so.
		$GLOBALS['_test_wp_remote_get'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );
		$quien_sabe                     = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertTrue( $quien_sabe['ok'] );
		$this->assertFalse( $quien_sabe['checked'] );

		$this->assertFalse( \diluxone_mail_api_verify( 'mailtrap_sending', '' )['ok'] );

		// A provider with nothing cheap to call cannot be checked at all.
		\add_filter(
			'diluxone_mail_api_providers',
			static function ( array $providers ): array {
				unset( $providers['mailtrap_sending']['verify_url'] );

				return $providers;
			}
		);

		$sin_chequeo = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertTrue( $sin_chequeo['ok'] );
		$this->assertFalse( $sin_chequeo['checked'] );
	}

	public function test_a_key_in_the_environment_is_read_and_never_written(): void {
		\update_option( 'diluxone_mail_api_key', \diluxone_mail_encrypt( 'de-la-base' ) );
		$this->assertSame( 'de-la-base', \diluxone_mail_api_key() );

		putenv( 'DILUXONE_MAIL_API_KEY=del-entorno' );

		// The environment wins over what is stored, and storing over it is a
		// no-op: the screen shows that field read-only for the same reason.
		$this->assertSame( 'del-entorno', \diluxone_mail_api_key() );
		$this->assertTrue( \diluxone_mail_store_api_key( 'otra', 'site' ) );
		$this->assertSame( 'de-la-base', \diluxone_mail_stored_password( (string) \get_option( 'diluxone_mail_api_key' ) ) );

		putenv( 'DILUXONE_MAIL_API_KEY' );
	}

	public function test_the_network_keeps_its_own_key(): void {
		$GLOBALS['_test_multisite'] = true;

		$this->assertTrue( \diluxone_mail_store_api_key( 'de-la-red', 'network' ) );
		$this->assertTrue( \diluxone_mail_is_encrypted( (string) \get_site_option( 'diluxone_mail_api_key' ) ) );
		$this->assertTrue( \diluxone_mail_store_api_key( '', 'network' ) );
	}

	public function test_the_first_step_offers_both_methods(): void {
		$html = $this->render( 'diluxone_mail_screen_settings' );

		$this->assertStringContainsString( 'diluxone_mail_transport_smtp', $html );
		$this->assertStringContainsString( 'diluxone_mail_transport_api', $html );
		// The ones with an API are marked so the list can be filtered.
		$this->assertStringContainsString( 'data-api="1"', $html );
		$this->assertStringContainsString( 'data-api="0"', $html );
	}
}
