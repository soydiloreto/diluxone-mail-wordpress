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
		\add_filter( 'wp_mail', 'diluxone_mail_api_keep_hook', PHP_INT_MIN );
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

		$this->conexion(
			array(
				'diluxone_mail_provider'  => 'mailtrap_sending',
				'diluxone_mail_transport' => 'api',
				'diluxone_mail_from'      => 'hello@x.test',
				'diluxone_mail_from_name' => 'X',
				'diluxone_mail_api_key'   => \diluxone_mail_encrypt( $key ),
			)
		);

		\update_option( 'diluxone_mail_mode', 'transport' );
	}

	/** What the provider answers. */
	private function responde( int $code, string $body = '{}' ): void {
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	/** A message with one of everything, for the shape tests. */
	private function mensaje(): array {
		return array(
			'from'      => 'hello@x.test',
			'from_name' => 'X',
			'to'        => array( 'ana@x.test' ),
			'cc'        => array( 'copia@x.test' ),
			'bcc'       => array( 'oculta@x.test' ),
			'reply_to'  => 'respuestas@x.test',
			'subject'   => 'Hola',
			'body'      => '<p>hola</p>',
			'html'      => true,
			'headers'   => array( 'X-Origen' => 'tienda' ),
			'files'     => array(
				array(
					'name'    => 'a.pdf',
					'type'    => 'application/pdf',
					'content' => 'YQ==',
				),
			),
		);
	}

	public function test_every_provider_is_handed_the_shape_it_asks_for(): void {
		$m = $this->mensaje();

		$sendgrid = \diluxone_mail_api_body_sendgrid( $m );
		$this->assertSame( array( array( 'email' => 'ana@x.test' ) ), $sendgrid['personalizations'][0]['to'] );
		$this->assertSame( array( array( 'email' => 'oculta@x.test' ) ), $sendgrid['personalizations'][0]['bcc'] );
		$this->assertSame( 'text/html', $sendgrid['content'][0]['type'] );
		$this->assertSame( 'respuestas@x.test', $sendgrid['reply_to']['email'] );
		$this->assertSame( 'a.pdf', $sendgrid['attachments'][0]['filename'] );

		$postmark = \diluxone_mail_api_body_postmark( $m );
		$this->assertSame( 'X <hello@x.test>', $postmark['From'] );
		$this->assertSame( 'ana@x.test', $postmark['To'] );
		$this->assertSame( 'oculta@x.test', $postmark['Bcc'] );
		$this->assertSame( '<p>hola</p>', $postmark['HtmlBody'] );
		$this->assertArrayNotHasKey( 'TextBody', $postmark );
		// A server can have several streams and a send without one is refused.
		$this->assertSame( 'outbound', $postmark['MessageStream'] );
		$this->assertSame( array( 'Name' => 'X-Origen', 'Value' => 'tienda' ), $postmark['Headers'][0] );

		$brevo = \diluxone_mail_api_body_brevo( $m );
		$this->assertSame( array( 'email' => 'hello@x.test', 'name' => 'X' ), $brevo['sender'] );
		$this->assertSame( '<p>hola</p>', $brevo['htmlContent'] );
		$this->assertSame( 'respuestas@x.test', $brevo['replyTo']['email'] );
		$this->assertSame( 'a.pdf', $brevo['attachment'][0]['name'] );

		$resend = \diluxone_mail_api_body_resend( $m );
		$this->assertSame( 'X <hello@x.test>', $resend['from'] );
		$this->assertSame( array( 'ana@x.test' ), $resend['to'] );
		$this->assertSame( 'respuestas@x.test', $resend['reply_to'] );

		$mailjet = \diluxone_mail_api_body_mailjet( $m );
		$this->assertCount( 1, $mailjet['Messages'] );
		$this->assertSame( array( 'Email' => 'hello@x.test', 'Name' => 'X' ), $mailjet['Messages'][0]['From'] );
		$this->assertSame( '<p>hola</p>', $mailjet['Messages'][0]['HTMLPart'] );
		$this->assertSame( 'a.pdf', $mailjet['Messages'][0]['Attachments'][0]['Filename'] );
	}

	public function test_a_message_with_nothing_extra_carries_nothing_extra(): void {
		$m = array_merge(
			$this->mensaje(),
			array(
				'cc'        => array(),
				'bcc'       => array(),
				'reply_to'  => '',
				'headers'   => array(),
				'files'     => array(),
				'from_name' => '',
				'html'      => false,
			)
		);

		// An empty list is not the same as none, and several of these providers
		// refuse a key whose value is an empty array.
		foreach ( array( 'sendgrid', 'brevo', 'resend' ) as $proveedor ) {
			$body = call_user_func( 'diluxone_mail_api_body_' . $proveedor, $m );

			$this->assertArrayNotHasKey( 'cc', $body, $proveedor );
			$this->assertArrayNotHasKey( 'bcc', $body, $proveedor );
		}

		$this->assertArrayNotHasKey( 'Cc', \diluxone_mail_api_body_postmark( $m ) );
		$this->assertArrayNotHasKey( 'Cc', \diluxone_mail_api_body_mailjet( $m )['Messages'][0] );
		// And the sender is just the address when nobody named it.
		$this->assertSame( 'hello@x.test', \diluxone_mail_api_body_resend( $m )['from'] );
		$this->assertSame( 'hello@x.test', \diluxone_mail_api_body_postmark( $m )['From'] );
	}

	public function test_the_complaint_is_found_whichever_way_it_is_spelled(): void {
		// One shape per provider, all of them a sentence under a key whose name
		// is a matter of taste.
		$this->assertSame( 'Does not contain a valid address.', \diluxone_mail_api_error_common( array( 'errors' => array( array( 'message' => 'Does not contain a valid address.' ) ) ), 'crudo' ) );
		$this->assertStringContainsString( 'sender signature', \diluxone_mail_api_error_common( array( 'ErrorCode' => 300, 'Message' => 'No sender signature found.' ), 'crudo' ) );
		$this->assertSame( 'Key not found', \diluxone_mail_api_error_common( array( 'code' => 'unauthorized', 'message' => 'Key not found' ), 'crudo' ) );
		$this->assertSame( 'Domain is not verified.', \diluxone_mail_api_error_common( array( 'statusCode' => 403, 'name' => 'validation_error', 'message' => 'Domain is not verified.' ), 'crudo' ) );
		$this->assertSame( 'Invalid email', \diluxone_mail_api_error_common( array( 'Messages' => array( array( 'Errors' => array( array( 'ErrorMessage' => 'Invalid email' ) ) ) ) ), 'crudo' ) );

		// Nothing it recognises: the body, which is worse to read and better
		// than silence.
		$this->assertSame( 'crudo', \diluxone_mail_api_error_common( array( 'algo' => array( 'raro' => true ) ), 'crudo' ) );
	}

	public function test_a_provider_with_two_credentials_takes_them_as_one(): void {
		$request = \diluxone_mail_api_request( 'mailjet', $this->mensaje(), 'clave:secreto' );

		$this->assertSame( 'https://api.mailjet.com/v3.1/send', $request['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'clave:secreto' ), $request['args']['headers']['Authorization'] );
	}

	public function test_resend_says_which_domains_are_finished(): void {
		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'data' => array(
						array( 'name' => 'listo.test', 'status' => 'verified' ),
						array( 'name' => 'a-medias.test', 'status' => 'pending' ),
					),
				)
			),
		);

		$listed = \diluxone_mail_api_domains_resend( 'tok' );

		$this->assertTrue( $listed['ok'] );
		$this->assertTrue( $listed['domains'][0]['usable'] );
		$this->assertFalse( $listed['domains'][1]['usable'] );
		$this->assertStringContainsString( 'pending', $listed['domains'][1]['note'] );
	}

	public function test_only_some_providers_have_an_api(): void {
		foreach ( array( 'mailtrap_sending', 'sendgrid', 'postmark', 'brevo', 'resend', 'mailjet' ) as $con ) {
			$this->assertTrue( \diluxone_mail_provider_has_api( $con ), $con );
		}

		// The ones that sign every request or need a consent screen are a
		// different kind of work and stay on SMTP.
		foreach ( array( 'ses', 'azure_acs', 'm365', 'google', 'custom', 'mailpit' ) as $sin ) {
			$this->assertFalse( \diluxone_mail_provider_has_api( $sin ), $sin );
		}

		$this->assertSame( array(), \diluxone_mail_api_provider( 'ses' ) );
	}

	public function test_every_api_provider_is_described_completely(): void {
		foreach ( \diluxone_mail_api_providers() as $slug => $api ) {
			// A provider is only reachable if every part of the description is
			// there: half of one is a fatal at send time, on somebody's site.
			foreach ( array( 'send_url', 'auth', 'build', 'error', 'ok_status', 'key_label', 'key_hint', 'docs' ) as $parte ) {
				$this->assertArrayHasKey( $parte, $api, $slug . '/' . $parte );
			}

			$this->assertTrue( is_callable( $api['build'] ), $slug );
			$this->assertTrue( is_callable( $api['error'] ), $slug );
			$this->assertStringStartsWith( 'https://', (string) $api['send_url'], $slug );
			// And it is a provider the SMTP list knows, or the first step
			// offers something the second one cannot set up.
			$this->assertArrayHasKey( $slug, \diluxone_mail_providers(), $slug );
		}
	}

	public function test_every_api_provider_builds_a_request_that_carries_the_message(): void {
		foreach ( array_keys( \diluxone_mail_api_providers() ) as $slug ) {
			$request = \diluxone_mail_api_request( $slug, $this->mensaje(), 'clave:secreto' );

			$this->assertStringStartsWith( 'https://', $request['url'], $slug );
			$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'], $slug );
			// However it authenticates, the credential is in the request —
			// base64 for the one that speaks HTTP Basic, plain for the rest.
			$cabeceras = (string) wp_json_encode( $request['args']['headers'] );

			$this->assertTrue(
				false !== strpos( $cabeceras, 'clave' ) || false !== strpos( $cabeceras, base64_encode( 'clave:secreto' ) ),
				$slug
			);
			// And so is the message.
			$this->assertStringContainsString( 'ana@x.test', $request['args']['body'], $slug );
			$this->assertStringContainsString( 'Hola', $request['args']['body'], $slug );
		}
	}

	public function test_it_only_sends_over_the_api_when_everything_lines_up(): void {
		$this->assertSame( 'smtp', \diluxone_mail_transport_kind() );
		$this->assertFalse( \diluxone_mail_api_active() );

		$this->por_api();
		$this->assertSame( 'api', \diluxone_mail_transport_kind() );
		$this->assertTrue( \diluxone_mail_api_active() );

		$id = \diluxone_mail_default_id();

		// A provider with no API falls back rather than failing.
		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_provider' => 'ses' ) );
		$this->assertFalse( \diluxone_mail_api_active() );

		// And so does a missing key.
		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_provider' => 'mailtrap_sending', 'diluxone_mail_api_key' => '' ) );
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
		\diluxone_mail_connection_put( \diluxone_mail_default_id(), array( 'diluxone_mail_from' => '' ) );

		$this->assertFalse( \wp_mail( 'ana@x.test', 'Hola', 'texto' ) );
		$this->assertSame( array(), $GLOBALS['_test_http'] );
	}

	public function test_the_key_is_checked_before_it_is_stored(): void {
		\update_option( 'diluxone_mail_provider', 'mailtrap_sending' );
		\update_option( 'diluxone_mail_transport', 'api' );

		$this->responde( 401, '{"errors":["Unauthorized"]}' );

		$_POST = array( 'scope' => 'site', 'tab' => 'server', 'diluxone_mail_api_key' => 'mala' );
		$url   = $this->redirect_of( 'diluxone_mail_api_key_action' );

		$this->assertStringContainsString( 'key-refused', $url );
		$this->assertSame( '', \diluxone_mail_api_key() );
		$this->assertFalse( \diluxone_mail_connection_verified() );

		// An empty body is not a message, so the endpoint complains about the
		// payload — which it only does once the key got it through the door.
		$this->responde( 422, '{"errors":["\'from\' is required"]}' );

		$_POST = array( 'scope' => 'site', 'tab' => 'server', 'diluxone_mail_api_key' => 'buena' );
		$url   = $this->redirect_of( 'diluxone_mail_api_key_action' );

		$this->assertStringContainsString( 'key-ok', $url );
		$this->assertStringContainsString( 'tab=sender', $url );
		$this->assertSame( 'buena', \diluxone_mail_api_key() );
		// Stored the way the password is: encrypted, never in the clear.
		$this->assertTrue( \diluxone_mail_is_encrypted( (string) \diluxone_mail_connection( \diluxone_mail_default_id() )['diluxone_mail_api_key'] ) );
		$this->assertTrue( \diluxone_mail_connection_verified() );
	}

	public function test_a_provider_that_cannot_be_asked_is_not_a_refusal(): void {
		$this->conexion( array( 'diluxone_mail_provider' => 'mailtrap_sending', 'diluxone_mail_transport' => 'api' ) );

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
		$this->assertSame( array(), \diluxone_mail_connections() );

		$_POST = array( 'scope' => 'site', 'diluxone_mail_provider' => 'ses', 'diluxone_mail_transport' => 'smtp' );
		$this->assertStringContainsString( 'profile-applied', $this->redirect_of( 'diluxone_mail_apply_provider' ) );
		$this->assertSame( 'smtp', \diluxone_mail_option( 'diluxone_mail_transport' ) );
	}

	public function test_changing_the_method_reopens_the_step_that_proved_the_old_one(): void {
		$this->por_api();
		\diluxone_mail_verified( 'connection' );

		$this->assertTrue( \diluxone_mail_connection_verified() );

		$id = \diluxone_mail_default_id();

		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_transport' => 'smtp' ) );
		$this->assertFalse( \diluxone_mail_connection_verified() );

		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_transport' => 'api' ) );
		$this->assertTrue( \diluxone_mail_connection_verified() );

		// And so does changing the key.
		\diluxone_mail_connection_put( $id, array( 'diluxone_mail_api_key' => \diluxone_mail_encrypt( 'otra' ) ) );
		$this->assertFalse( \diluxone_mail_connection_verified() );
	}

	public function test_the_second_step_asks_for_a_key_instead_of_a_server(): void {
		$this->por_api();
		$_GET['tab'] = 'server';

		$this->panel();
		$html = $this->render( 'diluxone_mail_screen_provider' );

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
		// The endpoint complains about the empty payload, which means the key
		// itself was accepted.
		$this->responde( 422, '{"errors":["\'from\' is required"]}' );
		$bien = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertTrue( $bien['ok'] );
		$this->assertTrue( $bien['checked'] );

		$this->responde( 403, '' );
		$mal = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertFalse( $mal['ok'] );
		$this->assertTrue( $mal['checked'] );

		// The provider is having a bad day: not a refusal, and said so.
		$this->responde( 500, '' );
		$quien_sabe = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertTrue( $quien_sabe['ok'] );
		$this->assertFalse( $quien_sabe['checked'] );

		// No network either: same answer.
		$GLOBALS['_test_wp_remote_post'] = new \WP_Error( 'http_request_failed', 'cURL error 28' );
		$sin_red                         = \diluxone_mail_api_verify( 'mailtrap_sending', 'tok' );
		$this->assertTrue( $sin_red['ok'] );
		$this->assertFalse( $sin_red['checked'] );

		$this->assertFalse( \diluxone_mail_api_verify( 'mailtrap_sending', '' )['ok'] );
		// A provider with no API at all is not checked and not refused.
		$this->assertTrue( \diluxone_mail_api_verify( 'ses', 'tok' )['ok'] );
	}

	public function test_a_refused_key_is_told_which_credential_it_probably_is(): void {
		// Thirty-two hexadecimal characters is the shape of an SMTP password,
		// which providers show next to the API token and which is the thing
		// almost everybody pastes by mistake.
		$smtp = \diluxone_mail_api_key_advice( 'b9e706b69f6b348823d8f51c167d148a' );
		$this->assertStringContainsString( 'SMTP password', $smtp );
		$this->assertStringContainsString( 'username', $smtp );

		$otra = \diluxone_mail_api_key_advice( 'algo-que-no-tiene-esa-forma' );
		$this->assertStringNotContainsString( 'SMTP password', $otra );
		$this->assertStringContainsString( 'sandbox', $otra );
	}

	public function test_a_key_in_the_environment_is_read_and_never_written(): void {
		$this->conexion( array( 'diluxone_mail_api_key' => \diluxone_mail_encrypt( 'de-la-base' ) ) );
		$this->assertSame( 'de-la-base', \diluxone_mail_api_key() );

		putenv( 'DILUXONE_MAIL_API_KEY=del-entorno' );

		// The environment wins over what is stored, and storing over it is a
		// no-op: the screen shows that field read-only for the same reason.
		$this->assertSame( 'del-entorno', \diluxone_mail_api_key() );
		$this->assertTrue( \diluxone_mail_store_api_key( 'otra' ) );
		$this->assertSame( 'de-la-base', \diluxone_mail_stored_password( (string) \diluxone_mail_connection( \diluxone_mail_default_id() )['diluxone_mail_api_key'] ) );

		putenv( 'DILUXONE_MAIL_API_KEY' );
	}

	public function test_the_network_keeps_its_own_key(): void {
		$GLOBALS['_test_multisite'] = true;

		$this->assertTrue( \diluxone_mail_store_api_key( 'de-la-red' ) );
		$this->assertTrue( \diluxone_mail_is_encrypted( (string) \diluxone_mail_connection( \diluxone_mail_default_id() )['diluxone_mail_api_key'] ) );
		$this->assertTrue( \diluxone_mail_store_api_key( '' ) );
	}

	/**
	 * What Mailtrap answers: the accounts, and then that account's domains.
	 *
	 * @param array<int, array<string, mixed>> $domains
	 */
	private function con_dominios( array $domains = array() ): void {
		$GLOBALS['_test_wp_remote_get'] = array(
			'/api/accounts/7/sending_domains' => array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode( $domains ),
			),
			'/api/accounts'                   => array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode( array( array( 'id' => 7, 'name' => 'Cuenta' ) ) ),
			),
		);
	}

	public function test_the_sender_step_offers_the_domains_the_provider_will_accept(): void {
		$this->por_api();

		$this->con_dominios(
			array(
				array( 'domain_name' => 'conosur.tech', 'dns_verified' => true, 'compliance_status' => 'compliant', 'demo' => false ),
				array( 'domain_name' => 'demomailtrap.co', 'dns_verified' => true, 'compliance_status' => 'demo_exhausted', 'demo' => true ),
				array( 'domain_name' => 'a-medio-hacer.test', 'dns_verified' => false, 'compliance_status' => '', 'demo' => false ),
			)
		);

		$listed = \diluxone_mail_api_sender_domains( true );

		$this->assertTrue( $listed['ok'] );
		$por_nombre = array_column( $listed['domains'], null, 'name' );

		$this->assertTrue( $por_nombre['conosur.tech']['usable'] );
		$this->assertSame( '', $por_nombre['conosur.tech']['note'] );

		// Verified and still unable to send: the one that costs an afternoon.
		$this->assertFalse( $por_nombre['demomailtrap.co']['usable'] );
		$this->assertStringContainsString( 'allowance', $por_nombre['demomailtrap.co']['note'] );

		$this->assertFalse( $por_nombre['a-medio-hacer.test']['usable'] );
		$this->assertStringContainsString( 'DNS', $por_nombre['a-medio-hacer.test']['note'] );
	}

	public function test_the_list_is_cached_and_the_button_asks_again(): void {
		$this->por_api();
		$this->con_dominios();

		\diluxone_mail_api_sender_domains( true );
		$llamadas = count( $GLOBALS['_test_http'] );

		// A second look costs nothing.
		\diluxone_mail_api_sender_domains();
		$this->assertCount( $llamadas, $GLOBALS['_test_http'] );

		// Asking again does.
		\diluxone_mail_api_sender_domains( true );
		$this->assertGreaterThan( $llamadas, count( $GLOBALS['_test_http'] ) );
	}

	public function test_over_smtp_or_without_a_key_nothing_is_asked(): void {
		$this->con_dominios();

		$this->assertFalse( \diluxone_mail_api_sender_domains( true )['ok'] );
		$this->assertSame( array(), $GLOBALS['_test_http'] );

		$this->por_api();
		\diluxone_mail_connection_put( \diluxone_mail_default_id(), array( 'diluxone_mail_transport' => 'smtp' ) );

		$this->assertFalse( \diluxone_mail_api_sender_domains( true )['ok'] );
		$this->assertSame( array(), $GLOBALS['_test_http'] );
	}

	public function test_a_provider_that_will_not_answer_leaves_the_field_typed_by_hand(): void {
		$this->por_api();
		\diluxone_mail_verified( 'connection' );
		$GLOBALS['_test_wp_remote_get'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );

		$listed = \diluxone_mail_api_sender_domains( true );

		$this->assertFalse( $listed['ok'] );
		$this->assertStringContainsString( '500', $listed['error'] );

		// And the step still paints, with the plain field.
		$_GET['tab'] = 'sender';
		$this->panel();
		$html        = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'name="diluxone_mail_from"', $html );
		$this->assertStringNotContainsString( 'diluxone_mail_from_domain', $html );
		$this->assertStringContainsString( 'could not be asked which domains', $html );
	}

	public function test_the_address_is_put_back_together_out_of_its_two_halves(): void {
		$this->por_api();

		$_POST = array(
			'scope'                    => 'site',
			'tab'                      => 'sender',
			'diluxone_mail_from_local' => 'donotreply',
			'diluxone_mail_from_domain' => 'conosur.tech',
		);

		$this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertSame( 'donotreply@conosur.tech', \diluxone_mail_option( 'diluxone_mail_from' ) );

		// With no domain half it is one plain field, as it has always been.
		$_POST = array(
			'scope'              => 'site',
			'tab'                => 'sender',
			'diluxone_mail_from' => 'otra@x.test',
		);

		$this->redirect_of( 'diluxone_mail_save_settings' );
		$this->assertSame( 'otra@x.test', \diluxone_mail_option( 'diluxone_mail_from' ) );
	}

	public function test_the_transport_comes_back_when_something_sweeps_the_hook(): void {
		$this->por_api();
		$this->responde( 200 );

		// What a development plugin aimed at somebody else's interceptor does,
		// and which takes this plugin's transport with it.
		\remove_all_filters( 'pre_wp_mail' );
		$this->assertFalse( \has_filter( 'pre_wp_mail', 'diluxone_mail_api_send' ) );

		// The send still goes out over the API, and over nothing else.
		$this->assertTrue( \wp_mail( 'ana@x.test', 'Hola', 'texto' ) );
		$this->assertSame( 'https://send.api.mailtrap.io/api/send', $GLOBALS['_test_http'][0]['url'] );
		$this->assertSame( array(), $GLOBALS['_test_wp_mail_calls'] );
	}

	public function test_nothing_else_on_that_hook_is_put_back(): void {
		$this->por_api();
		$this->responde( 200 );

		$ajeno = static fn( $pre ) => $pre;
		\add_filter( 'pre_wp_mail', $ajeno, 30 );
		\remove_all_filters( 'pre_wp_mail' );

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		// Only our own callback is restored: whoever swept the hook swept it
		// for a reason, and that reason is not ours to overrule.
		$this->assertTrue( \has_filter( 'pre_wp_mail', 'diluxone_mail_api_send' ) );
		$this->assertFalse( \has_filter( 'pre_wp_mail', $ajeno ) );
	}

	public function test_over_smtp_the_hook_is_left_where_it_was_put(): void {
		$this->enganchar();
		\update_option( 'diluxone_mail_mode', 'transport' );
		\remove_all_filters( 'pre_wp_mail' );

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		// Nothing to restore when the API is not the way this site sends.
		$this->assertFalse( \has_filter( 'pre_wp_mail', 'diluxone_mail_api_send' ) );
	}

	public function test_the_first_step_offers_both_methods(): void {
		$this->panel();
		$html = $this->render( 'diluxone_mail_screen_provider' );

		$this->assertStringContainsString( 'diluxone_mail_transport_smtp', $html );
		$this->assertStringContainsString( 'diluxone_mail_transport_api', $html );
		// The ones with an API are marked so the list can be filtered.
		$this->assertStringContainsString( 'data-api="1"', $html );
		$this->assertStringContainsString( 'data-api="0"', $html );
	}

	public function test_a_new_provider_asks_both_questions_and_carries_both_answers(): void {
		$this->panel();
		$html = $this->render( 'diluxone_mail_screen_provider' );

		// Both versions of every piece that differs are in the page, with the
		// one that does not apply hidden. Nothing waits for a round-trip to
		// stop describing the method that was not chosen.
		$this->assertStringContainsString( 'diluxone-mail-when-smtp', $html );
		$this->assertStringContainsString( 'diluxone-mail-when-api', $html );
		$this->assertStringContainsString( 'Save and continue', $html );
		$this->assertStringContainsString( 'SMTP server', $html );
		$this->assertStringContainsString( 'API key', $html );

		// Nothing is done yet: a provider that does not exist has no ticks,
		// and certainly not the ticks of the one already in charge.
		$this->assertStringNotContainsString( 'diluxone-mail-tab-done', $html );

		// Defaulting to SMTP: the SMTP half is the one showing.
		$this->assertMatchesRegularExpression( '/class="diluxone-mail-when-api" hidden/', $html );
		$this->assertDoesNotMatchRegularExpression( '/class="diluxone-mail-when-smtp" hidden/', $html );
	}

	public function test_a_stored_provider_stops_asking_what_it_is(): void {
		$id = $this->conexion(
			array(
				'diluxone_mail_provider'  => 'mailtrap_sending',
				'diluxone_mail_transport' => 'api',
			)
		);

		$_GET = array( 'tab' => 'profile' );
		$this->panel( $id );

		$html = $this->render( 'diluxone_mail_screen_provider' );

		// The two questions of a new provider are settled, and shown as
		// settled: its credentials belong to the answers already given.
		$this->assertStringNotContainsString( 'diluxone_mail_transport_api', $html );
		$this->assertStringNotContainsString( 'name="diluxone_mail_provider"', $html );
		$this->assertStringContainsString( 'Save name', $html );
		$this->assertStringContainsString( 'Neither of these changes', $html );
	}

	public function test_adding_one_does_not_borrow_the_progress_of_another(): void {
		// One already set up and verified, and a second being added.
		$this->configured();

		$_GET     = array( 'new' => '1' );
		$_REQUEST = $_GET;

		// What the screen does when it opens on one.
		\diluxone_mail_focus_editing();

		$progress = \diluxone_mail_settings_progress();

		$this->assertFalse( $progress['profile'] );
		$this->assertFalse( $progress['server'] );
		$this->assertFalse( $progress['sender'] );
		$this->assertSame( 'profile', \diluxone_mail_current_tab( 'site', 'provider' ) );
	}
}
