<?php
/**
 * Base de los tests de pantallas y handlers: carga todo el plugin como lo
 * carga el archivo principal, y deja el estado limpio entre tests.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

foreach ( (array) glob( dirname( __DIR__, 3 ) . '/includes/*.php' ) as $diluxone_mail_test_archivo ) {
	require_once (string) $diluxone_mail_test_archivo;
}

abstract class AdminTestCase extends TestCase {

	protected \DiluxOne_Test_WPDB $db;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']         = array();
		$GLOBALS['_test_wp_site_options']    = array();
		$GLOBALS['_test_wp_site_transients'] = array();
		$GLOBALS['_test_wp_transients']      = array();
		$GLOBALS['_test_multisite']          = false;
		$GLOBALS['_test_wp_mail_calls']      = array();
		$GLOBALS['_test_wp_mail_fails']      = '';
		$GLOBALS['_test_nonce_fails']        = false;
		$GLOBALS['_test_can']                = true;
		$GLOBALS['_test_users']              = array();
		$GLOBALS['_test_sites']              = array();
		$GLOBALS['_test_screen']             = null;
		$GLOBALS['_test_referer']            = false;
		$GLOBALS['_test_cli_items']           = array();
		$GLOBALS['wp_filter']                = array();
		$_GET                                = array();
		$_POST                               = array();

		\WP_CLI::reset();

		$this->db = $GLOBALS['wpdb'];
		$this->db->reset();

		\diluxone_mail_other_mailers( true );
		\diluxone_mail_current( null, true );
		\diluxone_mail_debug_enabled( false );
		\update_option( 'diluxone_mail_dns_resolver', 'doh' );

		foreach ( \diluxone_mail_config_fields() as $sufijo ) {
			putenv( 'DILUXONE_MAIL_' . $sufijo );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['_test_multisite'] = false;
		$GLOBALS['_test_can']       = true;
		$GLOBALS['_test_blog_id']   = 1;

		parent::tearDown();
	}

	/** Una persona, con el WP_User de los stubs. */
	protected function user( int $id, string $email ): \WP_User {
		$u             = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();
		$u->ID         = $id;
		$u->user_email = $email;
		$u->user_login = 'u' . $id;

		return $u;
	}

	/** Corre un handler que termina redirigiendo y devuelve adónde. */
	protected function redirect_of( callable $handler ): string {
		try {
			$handler();
		} catch ( \DiluxOne_Test_Redirect $e ) {
			return $e->getMessage();
		}

		$this->fail( 'el handler no redirigió' );
	}

	/** Captura lo que imprime una pantalla. */
	protected function render( callable $screen ): string {
		ob_start();
		$screen();

		return (string) ob_get_clean();
	}

	/** Una fila del historial como la devolvería la base. */
	protected function row( array $extra = array() ): array {
		return array_merge(
			array(
				'id'          => 7,
				'site_id'     => 1,
				'sent_at'     => '2026-09-13 10:00:00',
				'email'       => 'ana@x.test',
				'kind'        => 'to',
				'from_email'  => 'hola@x.test',
				'subject'     => 'Hola',
				'status'      => 'sent',
				'error'       => '',
				'response'    => '250 OK',
				'provider'    => 'mailjet',
				'source'      => 'plugin:tienda',
				'headers'     => '["X-Prueba: 1"]',
				'attachments' => '["a.pdf"]',
				'message_id'  => 'uuid-7',
				// El $wpdb de mentira devuelve la misma fila para el detalle.
				'body'        => '',
				'body_type'   => 'text/plain',
				'transcript'  => '',
			),
			$extra
		);
	}
}
