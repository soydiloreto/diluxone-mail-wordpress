<?php
/**
 * The log against a $wpdb that records what it is asked for.
 *
 * What is tested here is which SQL is built and what is written: the
 * placeholders, the filters, that table names go through %i, that the password
 * is redacted before an error is stored. That the database answers correctly
 * is what the integration suite tests.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../includes/log-hooks.php';
require_once __DIR__ . '/../../../includes/observer.php';
require_once __DIR__ . '/../../../includes/providers.php';

class LogTest extends TestCase {

	private \DiluxOne_Test_WPDB $db;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = false;
		$GLOBALS['wp_filter']             = array();
		$GLOBALS['_test_dbdelta']         = array();

		$this->db = $GLOBALS['wpdb'];
		$this->db->reset();
	}

	public function test_the_tables_use_the_base_prefix(): void {
		$this->assertSame( 'wp_diluxone_mail_log', \diluxone_mail_log_table() );
		$this->assertSame( 'wp_diluxone_mail_detail', \diluxone_mail_detail_table() );
	}

	public function test_installing_creates_both_tables_and_records_the_version(): void {
		\diluxone_mail_install();

		$this->assertCount( 2, $GLOBALS['_test_dbdelta'] );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $GLOBALS['_test_dbdelta'][0] );
		$this->assertStringContainsString( 'KEY email_sent (email(191), sent_at)', $GLOBALS['_test_dbdelta'][0] );
		$this->assertSame( DILUXONE_MAIL_DB_VERSION, \get_site_option( 'diluxone_mail_db_version' ) );

		// With the version up to date, it does not install again.
		$GLOBALS['_test_dbdelta'] = array();
		\diluxone_mail_maybe_install();
		$this->assertSame( array(), $GLOBALS['_test_dbdelta'] );
	}

	public function test_installing_takes_the_old_bodies_with_it(): void {
		// A site coming from schema 2, where the columns existed.
		$this->db->next_col = array( 'body', 'body_type' );
		\update_option( 'diluxone_mail_log_body', 1 );

		\diluxone_mail_install();

		$queries = array_column( $this->db->of( 'query' ), 'sql' );

		$this->assertStringContainsString( 'DROP COLUMN body, DROP COLUMN body_type', $queries[0] );
		$this->assertStringContainsString( 'WHERE transcript =', $queries[1] );
		// The setting that used to turn it on goes as well: nothing reads it
		// any more and leaving it behind suggests it still does something.
		$this->assertFalse( \get_option( 'diluxone_mail_log_body' ) );

		// A site that never had the columns is not asked to drop them.
		$this->db->reset();
		$this->db->next_col = array();
		\diluxone_mail_install();
		$queries = array_column( $this->db->of( 'query' ), 'sql' );
		$this->assertStringNotContainsString( 'DROP COLUMN', implode( ' ', $queries ) );
	}

	public function test_one_row_per_recipient_with_its_defaults(): void {
		\diluxone_mail_log_insert(
			array(
				array( 'email' => 'a@x.test', 'subject' => 'Hello', 'message_id' => 'uuid-1' ),
				array( 'email' => 'b@x.test', 'kind' => 'cc', 'message_id' => 'uuid-1' ),
			)
		);

		$inserts = $this->db->of( 'insert' );

		$this->assertCount( 2, $inserts );
		$this->assertSame( 'to', $inserts[0]['args']['kind'] );
		$this->assertSame( 'pending', $inserts[0]['args']['status'] );
		$this->assertSame( 'cc', $inserts[1]['args']['kind'] );
		$this->assertSame( 1, $inserts[1]['args']['site_id'] );
	}

	public function test_the_error_is_redacted_before_it_is_stored(): void {
		\update_option( 'diluxone_mail_pass', 'clave-secreta' );

		\diluxone_mail_log_set_status( 'uuid-1', 'failed', '535 bad password clave-secreta', '535 ' . base64_encode( 'clave-secreta' ) );

		$u = $this->db->of( 'update' )[0];

		$this->assertSame( 'failed', $u['args']['data']['status'] );
		$this->assertStringNotContainsString( 'clave-secreta', $u['args']['data']['error'] );
		$this->assertStringNotContainsString( base64_encode( 'clave-secreta' ), $u['args']['data']['response'] );
		$this->assertSame( array( 'message_id' => 'uuid-1' ), $u['args']['where'] );
	}

	public function test_without_a_message_id_nothing_is_updated(): void {
		\diluxone_mail_log_set_status( '', 'sent' );

		$this->assertSame( array(), $this->db->calls );
	}

	public function test_the_query_builds_the_filters_with_placeholders(): void {
		$this->db->next_var     = 7;
		$this->db->next_results = array( array( 'id' => 1 ) );

		$r = \diluxone_mail_log_query(
			array(
				'emails'   => array( 'A@x.test', 'b@x.test' ),
				'status'   => 'sent',
				'search'   => 'fact%ura',
				'page'     => 3,
				'per_page' => 10,
			)
		);

		$this->assertSame( 7, $r['total'] );
		$this->assertCount( 1, $r['rows'] );

		$sql = $this->db->of( 'get_results' )[0]['sql'];

		$this->assertStringContainsString( 'FROM `wp_diluxone_mail_log`', $sql );
		$this->assertStringContainsString( "email IN ('a@x.test','b@x.test')", $sql );
		$this->assertStringContainsString( "status = 'sent'", $sql );
		$this->assertStringContainsString( "LIKE '%fact", $sql );
		$this->assertStringContainsString( 'site_id = 1', $sql );
		$this->assertStringContainsString( 'LIMIT 10 OFFSET 20', $sql );
	}

	public function test_a_null_site_id_means_the_whole_network(): void {
		\diluxone_mail_log_query( array( 'site_id' => null ) );

		$this->assertStringNotContainsString( 'site_id', $this->db->of( 'get_var' )[0]['sql'] );
	}

	public function test_one_row_and_a_messages_recipients(): void {
		$this->db->next_row     = array( 'id' => 5 );
		$this->db->next_results = array( array( 'id' => 5 ), array( 'id' => 6 ) );

		$this->assertSame( array( 'id' => 5 ), \diluxone_mail_log_get( 5 ) );
		$this->assertStringContainsString( 'WHERE id = 5', $this->db->of( 'get_row' )[0]['sql'] );

		$this->assertCount( 2, \diluxone_mail_log_recipients_of( 'uuid-1' ) );
		$this->assertStringContainsString( "message_id = 'uuid-1'", $this->db->of( 'get_results' )[0]['sql'] );

		$this->db->next_row = null;
		$this->assertNull( \diluxone_mail_log_get( 99 ) );
	}

	public function test_the_totals_per_status(): void {
		$this->db->next_results = array( array( 'status' => 'sent', 'n' => '3' ), array( 'status' => 'failed', 'n' => '1' ) );

		$this->assertSame( array( 'sent' => 3, 'failed' => 1 ), \diluxone_mail_log_totals( 1 ) );
		$this->assertStringContainsString( 'WHERE site_id = 1', $this->db->of( 'get_results' )[0]['sql'] );

		\diluxone_mail_log_totals( null );
		$this->assertStringNotContainsString( 'WHERE', $this->db->of( 'get_results' )[1]['sql'] );
	}

	public function test_the_detail_keeps_the_dialogue_with_the_password_out_of_it(): void {
		\update_option( 'diluxone_mail_pass', 'secreto' );
		$this->db->next_row = array( 'transcript' => '' );

		\diluxone_mail_detail_save( 'uuid-1', array( 'transcript' => 'AUTH secreto' ) );

		$r = $this->db->of( 'replace' )[0]['args'];

		$this->assertSame( 'AUTH ***', $r['transcript'] );
		// The content of the message has nowhere to go: there is no column.
		$this->assertArrayNotHasKey( 'body', $r );

		$this->db->next_row = null;
		$this->assertNull( \diluxone_mail_detail_get( 'nothing' ) );
		\diluxone_mail_detail_save( '', array( 'transcript' => 'x' ) );
		$this->assertCount( 1, $this->db->of( 'replace' ) );
	}

	public function test_the_purge_deletes_by_date_and_empties_the_detail_when_it_is_not_stored(): void {
		\update_option( 'diluxone_mail_log_retention_days', 30 );
		$this->db->rows_affected = 4;

		$r = \diluxone_mail_log_purge();

		$q = $this->db->of( 'query' );

		$this->assertSame( 'DELETE FROM `wp_diluxone_mail_detail`', $q[0]['sql'] );
		$this->assertStringContainsString( 'DELETE FROM `wp_diluxone_mail_log` WHERE sent_at <', $q[1]['sql'] );
		$this->assertSame( array( 'log' => 4, 'details' => 4 ), $r );

		$this->db->reset();
		\update_option( 'diluxone_mail_log_extended', 1 );
		\diluxone_mail_log_purge();
		$this->assertStringContainsString( 'WHERE created_at <', $this->db->of( 'query' )[0]['sql'] );
	}

	public function test_deleting_an_address_leaves_the_shared_detail(): void {
		$this->db->rows_affected = 2;

		$this->assertSame( 2, \diluxone_mail_log_delete_by_email( ' Alguien@X.test ' ) );

		$q = $this->db->of( 'query' );

		$this->assertStringContainsString( 'NOT EXISTS', $q[0]['sql'] );
		$this->assertStringContainsString( "l2.email <> 'alguien@x.test'", $q[0]['sql'] );
		$this->assertSame( "DELETE FROM `wp_diluxone_mail_log` WHERE email = 'alguien@x.test'", $q[1]['sql'] );

		$this->assertSame( 0, \diluxone_mail_log_delete_by_email( '' ) );
	}

	public function test_counting_by_address_looks_across_the_whole_network(): void {
		$this->db->next_var = 3;

		$this->assertSame( 3, \diluxone_mail_log_count( array( 'a@x.test' ) ) );
		$this->assertStringNotContainsString( 'site_id', $this->db->of( 'get_var' )[0]['sql'] );
	}

	public function test_the_statuses_have_names(): void {
		$this->assertArrayHasKey( 'intercepted', \diluxone_mail_log_statuses() );
		$this->assertArrayHasKey( 'bounced', \diluxone_mail_log_statuses() );
	}
}
