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
