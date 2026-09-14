<?php
/**
 * The list as a chain: what happens when the first provider will not send.
 */

namespace Tests\Unit\DiluxOneMail;

class FailoverTest extends AdminTestCase {

	/** The plugin's hooks, which the base case clears between tests. */
	private function enganchar(): void {
		\add_filter( 'wp_mail', 'diluxone_mail_api_keep_hook', PHP_INT_MIN );
		\add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_maybe_suppress', 1, 2 );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_api_send', 20, 2 );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_watch_pre_wp_mail', PHP_INT_MAX, 2 );
		\add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );
		\add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );
		\add_action( 'wp_mail_failed', 'diluxone_mail_failover', 20 );
	}

	/** Two providers, in this order, both over SMTP. */
	private function dos(): array {
		$uno = $this->conexion(
			array(
				'diluxone_mail_provider' => 'mailjet',
				'diluxone_mail_host'     => 'smtp.uno.test',
				'diluxone_mail_from'     => 'hello@x.test',
			)
		);

		$dos = $this->conexion(
			array(
				'diluxone_mail_provider' => 'sendgrid',
				'diluxone_mail_host'     => 'smtp.dos.test',
				'diluxone_mail_from'     => 'hello@x.test',
			)
		);

		\update_option( 'diluxone_mail_mode', 'transport' );
		$this->enganchar();

		return array( $uno, $dos );
	}

	public function test_a_message_the_first_refuses_is_handed_to_the_next(): void {
		$this->dos();

		// The fake wp_mail() fails every send; the retry is what we are
		// watching, not whether it worked.
		$GLOBALS['_test_wp_mail_fails'] = 'no route to host';

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		// Two attempts, and the second one is a message of its own in the log.
		$this->assertCount( 2, $GLOBALS['_test_wp_mail_calls'] );
		$this->assertCount( 2, $this->db->of( 'insert' ) );

		foreach ( $this->db->of( 'update' ) as $update ) {
			$this->assertSame( 'failed', $update['args']['data']['status'] );
		}
	}

	public function test_the_second_provider_is_the_one_that_sends_the_retry(): void {
		list( $uno, $dos ) = $this->dos();

		$visto = array();

		\add_action(
			'diluxone_mail_failover',
			static function ( string $next, string $failed ) use ( &$visto ): void {
				$visto[] = array( $next, $failed );
			},
			10,
			3
		);

		$GLOBALS['_test_wp_mail_fails'] = 'nope';

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		$this->assertSame( array( array( $dos, $uno ) ), $visto );
		// And afterwards the site is back to sending through the first.
		$this->assertSame( $uno, \diluxone_mail_active_id() );
	}

	public function test_it_walks_the_list_once_and_not_once_per_level(): void {
		$this->dos();
		$this->conexion( array( 'diluxone_mail_host' => 'smtp.tres.test' ) );

		$GLOBALS['_test_wp_mail_fails'] = 'nope';

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		// The retry's own failure does not start another round: two attempts,
		// not one per provider squared.
		$this->assertCount( 2, $GLOBALS['_test_wp_mail_calls'] );
		$this->assertFalse( \diluxone_mail_failing_over() );
	}

	public function test_the_log_says_which_provider_each_attempt_went_through(): void {
		list( $uno, $dos ) = $this->dos();

		$GLOBALS['_test_wp_mail_fails'] = 'nope';

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		$filas = array_map(
			static fn( array $llamada ): array => $llamada['args'],
			$this->db->of( 'insert' )
		);

		// Two rows, and they are not the same provider: which one refused and
		// which one was tried next is the question a log with a failover in it
		// has to answer.
		$this->assertCount( 2, $filas );
		$this->assertSame( $uno, $filas[0]['connection'] );
		$this->assertSame( $dos, $filas[1]['connection'] );
	}

	public function test_a_row_is_named_after_the_provider_the_site_named(): void {
		$id = $this->conexion(
			array(
				'diluxone_mail_provider' => 'mailjet',
				'label'                  => 'El de facturación',
			)
		);

		$this->assertSame(
			'El de facturación',
			\diluxone_mail_log_carrier( array( 'provider' => 'mailjet', 'connection' => $id ) )
		);

		// A provider removed since: the row still says what kind it was, and
		// says that it is gone.
		$this->assertSame(
			'Mailjet (removed)',
			\diluxone_mail_log_carrier( array( 'provider' => 'mailjet', 'connection' => 'cn_borrada' ) )
		);

		// Rows written before any of this existed still read.
		$this->assertSame( 'Mailjet', \diluxone_mail_log_carrier( array( 'provider' => 'mailjet' ) ) );
		$this->assertSame( 'another plugin', \diluxone_mail_log_carrier( array( 'provider' => 'observer' ) ) );
		$this->assertSame( '—', \diluxone_mail_log_carrier( array() ) );
	}

	/**
	 * The environment configures the provider that sends, and only it.
	 *
	 * There is one DILUXONE_MAIL_PASS and a list of providers. Letting it
	 * answer for all of them would have the fallback authenticate with the
	 * credential of the provider that has just refused the message — the one
	 * configuration guaranteed not to work, applied at the exact moment the
	 * site is relying on it.
	 */
	public function test_a_constant_belongs_to_the_provider_at_the_top(): void {
		list( $uno, $dos ) = $this->dos();

		putenv( 'DILUXONE_MAIL_PASS=la-del-primero' );

		\diluxone_mail_focus( $uno );
		$this->assertSame( 'la-del-primero', \diluxone_mail_config_value( 'pass' )['value'] );
		$this->assertSame( 'env', \diluxone_mail_config_value( 'pass' )['source'] );

		// And the second reads its own, which here is nothing at all rather
		// than somebody else's.
		\diluxone_mail_focus( $dos );
		$this->assertSame( '', \diluxone_mail_config_value( 'pass' )['value'] );
		$this->assertNotSame( 'env', \diluxone_mail_config_value( 'pass' )['source'] );

		// Which also means the second provider's screen lets you type one.
		$this->assertFalse( \diluxone_mail_option_from_environment( 'diluxone_mail_pass' ) );

		\diluxone_mail_focus( $uno );
		$this->assertTrue( \diluxone_mail_option_from_environment( 'diluxone_mail_pass' ) );

		putenv( 'DILUXONE_MAIL_PASS' );
	}

	/**
	 * Settings that belong to the site, not to a provider, are unaffected:
	 * they were never in the list.
	 */
	public function test_the_host_of_a_site_with_no_list_still_comes_from_the_environment(): void {
		putenv( 'DILUXONE_MAIL_HOST=smtp.entorno.test' );

		$this->assertSame( 'smtp.entorno.test', \diluxone_mail_config_value( 'host' )['value'] );

		putenv( 'DILUXONE_MAIL_HOST' );
	}

	public function test_with_nobody_underneath_there_is_nothing_to_try(): void {
		$this->conexion(
			array(
				'diluxone_mail_provider' => 'mailjet',
				'diluxone_mail_host'     => 'smtp.solo.test',
				'diluxone_mail_from'     => 'hello@x.test',
			)
		);

		\update_option( 'diluxone_mail_mode', 'transport' );
		$this->enganchar();

		$GLOBALS['_test_wp_mail_fails'] = 'nope';

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		$this->assertCount( 1, $GLOBALS['_test_wp_mail_calls'] );
	}

	public function test_in_observer_mode_nothing_is_retried(): void {
		$this->dos();
		\update_option( 'diluxone_mail_mode', 'observe' );

		$GLOBALS['_test_wp_mail_fails'] = 'nope';

		\wp_mail( 'ana@x.test', 'Hola', 'texto' );

		// The message belongs to whoever else is delivering it; sending it
		// again through a provider of ours would be sending it twice.
		$this->assertCount( 1, $GLOBALS['_test_wp_mail_calls'] );
	}

	public function test_a_failure_that_is_not_a_message_is_left_alone(): void {
		$this->dos();

		// Somebody else's WP_Error on the same hook.
		\diluxone_mail_failover( new \WP_Error( 'otra_cosa', 'algo pasó' ) );

		$this->assertSame( array(), $GLOBALS['_test_wp_mail_calls'] );
		$this->assertNull( \diluxone_mail_failed_message( new \WP_Error( 'x', 'y' ) ) );

		$message = \diluxone_mail_failed_message(
			new \WP_Error( 'x', 'y', array( 'to' => 'ana@x.test', 'subject' => 'Hola' ) )
		);

		$this->assertSame( 'ana@x.test', $message['to'] );
		$this->assertSame( '', $message['message'] );
	}
}
