<?php
/**
 * El envío entero, de wp_mail() al historial, con el wp_mail() de mentira
 * que reproduce la secuencia de hooks de WordPress.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/providers.php';
require_once __DIR__ . '/../../../includes/observer.php';
require_once __DIR__ . '/../../../includes/pre-wp-mail.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../includes/log-hooks.php';

class LogHooksTest extends TestCase {

	private \DiluxOne_Test_WPDB $db;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = false;
		$GLOBALS['_test_wp_mail_calls']   = array();
		$GLOBALS['_test_wp_mail_fails']   = '';
		$GLOBALS['wp_filter']             = array();

		$this->db = $GLOBALS['wpdb'];
		$this->db->reset();

		// Los hooks del plugin, como los registra al cargar.
		\add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_maybe_suppress', 1, 2 );
		\add_filter( 'pre_wp_mail', 'diluxone_mail_watch_pre_wp_mail', PHP_INT_MAX, 2 );
		\add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );
		\add_action( 'wp_mail_failed', 'diluxone_mail_on_failed' );

		\diluxone_mail_other_mailers( true );
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_from', 'hola@ejemplo.test' );
		\diluxone_mail_current( null, true );
	}

	public function test_un_envio_exitoso_queda_como_sent(): void {
		$ok = \wp_mail( 'a@x.test', 'Hola', 'Cuerpo', array( 'Cc: b@x.test' ) );

		$this->assertTrue( $ok );
		$this->assertCount( 2, $this->db->of( 'insert' ) );
		$this->assertSame( 'mailjet', $this->db->of( 'insert' )[0]['args']['provider'] );
		$this->assertSame( 'hola@ejemplo.test', $this->db->of( 'insert' )[0]['args']['from_email'] );
		$this->assertSame( 'sent', $this->db->of( 'update' )[0]['args']['data']['status'] );
		$this->assertNull( \diluxone_mail_current() );
		$this->assertSame( 1, \get_option( 'diluxone_mail_last_result' )['ok'] );
	}

	public function test_un_envio_fallido_guarda_el_error(): void {
		$GLOBALS['_test_wp_mail_fails'] = 'SMTP Error: Could not connect';

		$this->assertFalse( \wp_mail( 'a@x.test', 'Hola', 'Cuerpo' ) );
		$this->assertSame( 'failed', $this->db->of( 'update' )[0]['args']['data']['status'] );
		$this->assertStringContainsString( 'Could not connect', $this->db->of( 'update' )[0]['args']['data']['error'] );
		$this->assertSame( 0, \get_option( 'diluxone_mail_last_result' )['ok'] );
	}

	public function test_should_send_suprime_y_lo_anota(): void {
		\add_filter( 'diluxone_mail_should_send', static fn(): bool => false );

		$this->assertFalse( \wp_mail( 'a@x.test', 'Hola', 'Cuerpo' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_mail_calls'] );
		$this->assertSame( 'suppressed', $this->db->of( 'update' )[0]['args']['data']['status'] );
	}

	public function test_otro_plugin_que_corta_en_pre_wp_mail_queda_como_intercepted(): void {
		\add_filter( 'pre_wp_mail', static fn( $pre ) => true, 10 );

		$this->assertTrue( \wp_mail( 'a@x.test', 'Hola', 'Cuerpo' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_mail_calls'] );
		$this->assertSame( 'intercepted', $this->db->of( 'update' )[0]['args']['data']['status'] );
	}

	public function test_el_filtro_atts_transforma_el_mensaje(): void {
		\add_filter( 'diluxone_mail_atts', static function ( array $a ): array { $a['subject'] = '[Marca] ' . $a['subject']; return $a; } );

		\wp_mail( 'a@x.test', 'Hola', 'Cuerpo' );

		$this->assertSame( '[Marca] Hola', $GLOBALS['_test_wp_mail_calls'][0]['subject'] );
		$this->assertSame( '[Marca] Hola', $this->db->of( 'insert' )[0]['args']['subject'] );
	}

	public function test_el_historial_apagado_no_escribe_pero_el_filtro_sigue(): void {
		\update_option( 'diluxone_mail_log_enabled', 0 );

		\wp_mail( 'a@x.test', 'Hola', 'Cuerpo' );

		$this->assertSame( array(), $this->db->of( 'insert' ) );
		$this->assertCount( 1, $GLOBALS['_test_wp_mail_calls'] );
	}

	public function test_extendido_guarda_cabeceras_y_dialogo_y_cuerpo_si_se_pide(): void {
		\update_option( 'diluxone_mail_log_extended', 1 );
		\update_option( 'diluxone_mail_log_body', 1 );

		\wp_mail( 'a@x.test', 'Hola', 'Cuerpo', array( 'X-Prueba: 1' ), array( '/tmp/adjunto.pdf' ) );

		$i = $this->db->of( 'insert' )[0]['args'];
		$this->assertStringContainsString( 'X-Prueba', $i['headers'] );
		$this->assertStringContainsString( 'adjunto.pdf', $i['attachments'] );

		$replaces = $this->db->of( 'replace' );
		$this->assertSame( 'Cuerpo', $replaces[0]['args']['body'] );
		$this->assertCount( 2, $replaces );
	}

	public function test_sin_destinatarios_validos_no_se_anota_nada(): void {
		\wp_mail( 'no-es-nada', 'Hola', 'Cuerpo' );

		$this->assertSame( array(), $this->db->of( 'insert' ) );
	}

	public function test_en_modo_observador_el_proveedor_es_observer(): void {
		\update_option( 'diluxone_mail_mode', 'observe' );

		\wp_mail( 'a@x.test', 'Hola', 'Cuerpo' );

		$this->assertSame( 'observer', $this->db->of( 'insert' )[0]['args']['provider'] );
	}

	public function test_quien_llamo(): void {
		$this->assertSame( 'core', \diluxone_mail_caller() );

		$plugin = WP_PLUGIN_DIR . '/tienda/tienda.php';
		@mkdir( dirname( $plugin ), 0777, true );
		file_put_contents( $plugin, "<?php\nfunction tienda_test_caller() { return diluxone_mail_caller(); }\n" );
		require_once $plugin;

		$this->assertSame( 'plugin:tienda', \tienda_test_caller() );
	}

	public function test_el_slot_del_envio_en_curso(): void {
		\diluxone_mail_current( array( 'uuid' => 'x' ) );
		$this->assertSame( 'x', \diluxone_mail_current()['uuid'] );

		\diluxone_mail_current( null, true );
		$this->assertNull( \diluxone_mail_current() );
	}

	public function test_sin_phpmailer_no_hay_ultima_respuesta_ni_message_id(): void {
		$this->assertSame( '', \diluxone_mail_last_smtp_reply() );

		$obj = new \stdClass();
		\diluxone_mail_stamp_message_id( $obj );
		$this->assertSame( array(), get_object_vars( $obj ) );
	}
}
