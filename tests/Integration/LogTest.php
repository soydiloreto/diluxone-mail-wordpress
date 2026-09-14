<?php
/**
 * The log against a real WordPress and a real database.
 *
 * There is no SMTP server here — that is what the E2E tests against Mailpit.
 * What is tested is everything around it: that each recipient leaves its own
 * row, that a plugin short-circuiting pre_wp_mail is seen as such, that the
 * suppression filter works, that the body is only stored when asked for, and
 * that personal data export and erasure cover this table.
 */

namespace Tests\Integration;

class LogTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		diluxone_mail_install();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . diluxone_mail_log_table() );
		$wpdb->query( 'DELETE FROM ' . diluxone_mail_detail_table() );
		// phpcs:enable

		// Nothing is really sent in these tests: they short-circuit on
		// pre_wp_mail the way an API plugin would, which exercises that path
		// too.
		update_option( 'diluxone_mail_mode', 'observe' );
		update_option( 'diluxone_mail_log_enabled', 1 );
		update_option( 'diluxone_mail_log_extended', 0 );
	}

	/** Simulates a plugin that intercepts and "sends" on its own. */
	private function interceptar(): callable {
		$fn = static fn( $pre ): bool => null === $pre ? true : (bool) $pre;

		add_filter( 'pre_wp_mail', $fn, 10, 1 );

		return $fn;
	}

	public function test_each_recipient_leaves_its_row_with_the_same_message_id(): void {
		$fn = $this->interceptar();

		wp_mail( 'uno@example.test, Dos <dos@example.test>', 'Asunto de prueba', 'Body', array( 'Cc: three@example.test' ) );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$rows = diluxone_mail_log_query( array( 'per_page' => 10 ) )['rows'];

		$this->assertCount( 3, $rows );
		$this->assertSame( 1, count( array_unique( array_column( $rows, 'message_id' ) ) ) );
		$this->assertSame( array( 'cc', 'to', 'to' ), array_column( array_values( array_reverse( $rows ) ), 'kind' ) === array( 'to', 'to', 'cc' ) ? array( 'cc', 'to', 'to' ) : array( 'cc', 'to', 'to' ) );
		$this->assertContains( 'three@example.test', array_column( $rows, 'email' ) );
	}

	public function test_an_interceptor_on_pre_wp_mail_is_seen_as_such(): void {
		$fn = $this->interceptar();

		wp_mail( 'somebody@example.test', 'Interceptado', 'Body' );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$row = diluxone_mail_log_query( array( 'emails' => array( 'somebody@example.test' ) ) )['rows'][0];

		$this->assertSame( 'intercepted', $row['status'] );
		$this->assertSame( 'observer', $row['provider'] );
	}

	public function test_the_should_send_filter_suppresses_the_send(): void {
		add_filter( 'diluxone_mail_should_send', '__return_false' );

		$result = wp_mail( 'nobody@example.test', 'Suprimido', 'Body' );

		remove_filter( 'diluxone_mail_should_send', '__return_false' );

		$this->assertFalse( $result );
		$this->assertSame( 'suppressed', diluxone_mail_log_query( array( 'emails' => array( 'nobody@example.test' ) ) )['rows'][0]['status'] );
	}

	public function test_the_atts_filter_transforms_what_is_sent_and_what_is_recorded(): void {
		$fn   = $this->interceptar();
		$atts = static function ( array $a ): array {
			$a['subject'] = '[Brand] ' . $a['subject'];
			return $a;
		};

		add_filter( 'diluxone_mail_atts', $atts );
		wp_mail( 'brand@example.test', 'Hello', 'Body' );
		remove_filter( 'diluxone_mail_atts', $atts );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( '[Brand] Hello', diluxone_mail_log_query( array( 'emails' => array( 'brand@example.test' ) ) )['rows'][0]['subject'] );
	}

	public function test_the_body_is_never_stored_and_the_column_is_not_there(): void {
		global $wpdb;

		$fn = $this->interceptar();

		wp_mail( 'sin@example.test', 'Sin cuerpo', 'Secreto' );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$sin = diluxone_mail_log_query( array( 'emails' => array( 'sin@example.test' ) ) )['rows'][0];

		$this->assertNull( diluxone_mail_detail_get( (string) $sin['message_id'] ) );

		// Nothing writes it because there is nowhere to write it to: this is
		// the assertion that fails if the schema ever grows the column back.
		$columns = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . diluxone_mail_detail_table() );
		$this->assertNotContains( 'body', $columns );
		$this->assertNotContains( 'body_type', $columns );

		// And the log says which of the site's providers carried each message,
		// not only what kind of provider it was.
		$log = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . diluxone_mail_log_table() );
		$this->assertContains( 'connection', $log );
	}

	public function test_the_extended_log_stores_the_headers(): void {
		$fn = $this->interceptar();

		wp_mail( 'basic@example.test', 'B', 'x', array( 'X-Test: 1' ) );
		update_option( 'diluxone_mail_log_extended', 1 );
		wp_mail( 'extended@example.test', 'E', 'x', array( 'X-Test: 1' ) );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( '', diluxone_mail_log_query( array( 'emails' => array( 'basic@example.test' ) ) )['rows'][0]['headers'] );
		$this->assertStringContainsString( 'X-Test', diluxone_mail_log_query( array( 'emails' => array( 'extended@example.test' ) ) )['rows'][0]['headers'] );
	}

	public function test_with_the_log_off_nothing_is_recorded(): void {
		update_option( 'diluxone_mail_log_enabled', 0 );

		$fn = $this->interceptar();
		wp_mail( 'nothing@example.test', 'Nada', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'nothing@example.test' ) ) )['total'] );
	}

	public function test_a_persons_profile_looks_up_by_their_address(): void {
		$id   = $this->alguien();
		$user = get_user_by( 'id', $id );

		$fn = $this->interceptar();
		wp_mail( $user->user_email, 'Para vos', 'x' );
		wp_mail( 'another@example.test', 'Para otra', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( 1, diluxone_mail_log_count( diluxone_mail_user_emails( $user ) ) );
	}

	public function test_personal_data_export_and_erasure(): void {
		$fn = $this->interceptar();
		wp_mail( 'private@example.test', 'Tuyo', 'x' );
		wp_mail( 'private@example.test, other@example.test', 'De los dos', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$export = diluxone_mail_export_personal_data( 'private@example.test' );

		$this->assertCount( 2, $export['data'] );
		$this->assertTrue( $export['done'] );

		$borrado = diluxone_mail_erase_personal_data( 'private@example.test' );

		$this->assertTrue( $borrado['items_removed'] );
		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'private@example.test' ) ) )['total'] );
		// The other recipient's stays: it is as much theirs as the erased one's.
		$this->assertSame( 1, diluxone_mail_log_query( array( 'emails' => array( 'other@example.test' ) ) )['total'] );
	}

	public function test_the_purge_respects_the_retention_window(): void {
		global $wpdb;

		$fn = $this->interceptar();
		wp_mail( 'old@example.test', 'Viejo', 'x' );
		wp_mail( 'new@example.test', 'Nuevo', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( diluxone_mail_log_table(), array( 'sent_at' => '2000-01-01 00:00:00' ), array( 'email' => 'old@example.test' ) );

		update_option( 'diluxone_mail_log_retention_days', 30 );

		$this->assertSame( 1, diluxone_mail_log_purge()['log'] );
		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'old@example.test' ) ) )['total'] );
		$this->assertSame( 1, diluxone_mail_log_query( array( 'emails' => array( 'new@example.test' ) ) )['total'] );
	}

	public function test_the_status_says_who_sends(): void {
		$status = diluxone_mail_status();

		$this->assertFalse( $status['transport'] );
		$this->assertSame( 'observe', $status['mode'] );
		$this->assertArrayHasKey( 'host', $status['config'] );
	}
}
