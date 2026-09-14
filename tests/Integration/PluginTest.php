<?php
/**
 * That the plugin really boots on a clean WordPress.
 *
 * The unit tests run against stubs and do not see this: that the main file
 * loads every one of its includes without clashing, and that the log tables
 * exist after activation. It is the floor of "it works on a fresh install".
 */

namespace Tests\Integration;

class PluginTest extends IntegrationTestCase {

	public function test_the_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'DILUXONE_MAIL_VERSION' ) );
		$this->assertTrue( function_exists( 'diluxone_mail_option' ) );
		$this->assertTrue( function_exists( 'diluxone_mail_config' ) );
	}

	public function test_the_options_have_defaults(): void {
		// With nothing stored, every setting has to answer something sensible:
		// a freshly installed plugin cannot depend on somebody walking through
		// all of its screens before the site works.
		$this->assertSame( 'auto', diluxone_mail_option( 'diluxone_mail_mode' ) );
		$this->assertSame( 587, (int) diluxone_mail_option( 'diluxone_mail_port' ) );
		$this->assertSame( 1, (int) diluxone_mail_option( 'diluxone_mail_log_enabled' ) );
	}

	/**
	 * There is nowhere to put a message body.
	 *
	 * It used to be a setting that shipped off, and this test checked the
	 * default. A default is a promise somebody can change in an afternoon, so
	 * the setting went and the column with it: what is asserted now is that
	 * the schema itself has no room for a body, which is the only version of
	 * this promise that cannot be undone by editing one line.
	 *
	 * A log that kept bodies would be keeping every password-reset link the
	 * site has ever sent.
	 */
	public function test_the_schema_has_nowhere_to_put_a_message_body(): void {
		global $wpdb;

		diluxone_mail_install();

		foreach ( array( diluxone_mail_log_table(), diluxone_mail_detail_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$columnas = array_column( (array) $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ), 'Field' );

			$this->assertNotContains( 'body', $columnas, "{$table} still has room for a message body" );
			$this->assertNotContains( 'body_type', $columnas, "{$table} still has room for a message body" );
		}
	}

	public function test_the_log_tables_exist(): void {
		global $wpdb;

		diluxone_mail_install();

		foreach ( array( diluxone_mail_log_table(), diluxone_mail_detail_table() ) as $table ) {
			$existe = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			$this->assertSame( $table, $existe, "falta la tabla {$table}" );
		}
	}

	/**
	 * dbDelta() is silently fussy about the SQL's formatting: if it does not
	 * like it, it does not fail — it recreates the table on every page load.
	 * Running it twice and checking that the version held is the cheap way to
	 * find out.
	 */
	public function test_installing_twice_breaks_nothing(): void {
		diluxone_mail_install();
		diluxone_mail_install();

		$this->assertSame( DILUXONE_MAIL_DB_VERSION, (int) get_option( 'diluxone_mail_db_version' ) );
	}
}
