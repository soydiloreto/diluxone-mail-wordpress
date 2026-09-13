<?php
/**
 * El historial contra un $wpdb que anota lo que se le pide.
 *
 * Lo que se prueba acá es qué SQL se arma y qué se escribe: los marcadores,
 * los filtros, que los nombres de tabla vayan por %i, que la contraseña se
 * tape antes de guardar un error. Que la base conteste bien lo prueba la
 * integración.
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

	public function test_las_tablas_llevan_el_prefijo_base(): void {
		$this->assertSame( 'wp_diluxone_mail_log', \diluxone_mail_log_table() );
		$this->assertSame( 'wp_diluxone_mail_detail', \diluxone_mail_detail_table() );
	}

	public function test_instalar_crea_las_dos_tablas_y_anota_la_version(): void {
		\diluxone_mail_install();

		$this->assertCount( 2, $GLOBALS['_test_dbdelta'] );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $GLOBALS['_test_dbdelta'][0] );
		$this->assertStringContainsString( 'KEY email_sent (email(191), sent_at)', $GLOBALS['_test_dbdelta'][0] );
		$this->assertSame( DILUXONE_MAIL_DB_VERSION, \get_site_option( 'diluxone_mail_db_version' ) );

		// Con la versión al día, no se vuelve a instalar.
		$GLOBALS['_test_dbdelta'] = array();
		\diluxone_mail_maybe_install();
		$this->assertSame( array(), $GLOBALS['_test_dbdelta'] );
	}

	public function test_una_fila_por_destinatario_con_sus_valores_por_defecto(): void {
		\diluxone_mail_log_insert(
			array(
				array( 'email' => 'a@x.test', 'subject' => 'Hola', 'message_id' => 'uuid-1' ),
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

	public function test_el_error_se_tapa_antes_de_guardarlo(): void {
		\update_option( 'diluxone_mail_pass', 'clave-secreta' );

		\diluxone_mail_log_set_status( 'uuid-1', 'failed', '535 bad password clave-secreta', '535 ' . base64_encode( 'clave-secreta' ) );

		$u = $this->db->of( 'update' )[0];

		$this->assertSame( 'failed', $u['args']['data']['status'] );
		$this->assertStringNotContainsString( 'clave-secreta', $u['args']['data']['error'] );
		$this->assertStringNotContainsString( base64_encode( 'clave-secreta' ), $u['args']['data']['response'] );
		$this->assertSame( array( 'message_id' => 'uuid-1' ), $u['args']['where'] );
	}

	public function test_sin_message_id_no_se_actualiza_nada(): void {
		\diluxone_mail_log_set_status( '', 'sent' );

		$this->assertSame( array(), $this->db->calls );
	}

	public function test_la_consulta_arma_los_filtros_con_marcadores(): void {
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

	public function test_site_id_null_es_toda_la_red(): void {
		\diluxone_mail_log_query( array( 'site_id' => null ) );

		$this->assertStringNotContainsString( 'site_id', $this->db->of( 'get_var' )[0]['sql'] );
	}

	public function test_una_fila_y_los_destinatarios_de_un_mensaje(): void {
		$this->db->next_row     = array( 'id' => 5 );
		$this->db->next_results = array( array( 'id' => 5 ), array( 'id' => 6 ) );

		$this->assertSame( array( 'id' => 5 ), \diluxone_mail_log_get( 5 ) );
		$this->assertStringContainsString( 'WHERE id = 5', $this->db->of( 'get_row' )[0]['sql'] );

		$this->assertCount( 2, \diluxone_mail_log_recipients_of( 'uuid-1' ) );
		$this->assertStringContainsString( "message_id = 'uuid-1'", $this->db->of( 'get_results' )[0]['sql'] );

		$this->db->next_row = null;
		$this->assertNull( \diluxone_mail_log_get( 99 ) );
	}

	public function test_los_totales_por_estado(): void {
		$this->db->next_results = array( array( 'status' => 'sent', 'n' => '3' ), array( 'status' => 'failed', 'n' => '1' ) );

		$this->assertSame( array( 'sent' => 3, 'failed' => 1 ), \diluxone_mail_log_totals( 1 ) );
		$this->assertStringContainsString( 'WHERE site_id = 1', $this->db->of( 'get_results' )[0]['sql'] );

		\diluxone_mail_log_totals( null );
		$this->assertStringNotContainsString( 'WHERE', $this->db->of( 'get_results' )[1]['sql'] );
	}

	public function test_el_detalle_se_funde_con_lo_que_habia(): void {
		\update_option( 'diluxone_mail_pass', 'secreto' );
		$this->db->next_row = array( 'body' => 'cuerpo', 'body_type' => 'text/html', 'transcript' => '' );

		\diluxone_mail_detail_save( 'uuid-1', array( 'transcript' => 'AUTH secreto' ) );

		$r = $this->db->of( 'replace' )[0]['args'];

		$this->assertSame( 'cuerpo', $r['body'] );
		$this->assertSame( 'text/html', $r['body_type'] );
		$this->assertSame( 'AUTH ***', $r['transcript'] );

		$this->db->next_row = null;
		$this->assertNull( \diluxone_mail_detail_get( 'nada' ) );
		\diluxone_mail_detail_save( '', array( 'body' => 'x' ) );
		$this->assertCount( 1, $this->db->of( 'replace' ) );
	}

	public function test_la_purga_borra_por_fecha_y_vacia_el_detalle_si_no_se_guarda(): void {
		\update_option( 'diluxone_mail_log_retention_days', 30 );
		$this->db->rows_affected = 4;

		$r = \diluxone_mail_log_purge();

		$q = $this->db->of( 'query' );

		$this->assertSame( 'DELETE FROM `wp_diluxone_mail_detail`', $q[0]['sql'] );
		$this->assertStringContainsString( 'DELETE FROM `wp_diluxone_mail_log` WHERE sent_at <', $q[1]['sql'] );
		$this->assertSame( array( 'log' => 4, 'details' => 4 ), $r );

		$this->db->reset();
		\update_option( 'diluxone_mail_log_body', 1 );
		\diluxone_mail_log_purge();
		$this->assertStringContainsString( 'WHERE created_at <', $this->db->of( 'query' )[0]['sql'] );
	}

	public function test_borrar_una_direccion_deja_el_detalle_compartido(): void {
		$this->db->rows_affected = 2;

		$this->assertSame( 2, \diluxone_mail_log_delete_by_email( ' Alguien@X.test ' ) );

		$q = $this->db->of( 'query' );

		$this->assertStringContainsString( 'NOT EXISTS', $q[0]['sql'] );
		$this->assertStringContainsString( "l2.email <> 'alguien@x.test'", $q[0]['sql'] );
		$this->assertSame( "DELETE FROM `wp_diluxone_mail_log` WHERE email = 'alguien@x.test'", $q[1]['sql'] );

		$this->assertSame( 0, \diluxone_mail_log_delete_by_email( '' ) );
	}

	public function test_contar_por_direccion_mira_toda_la_red(): void {
		$this->db->next_var = 3;

		$this->assertSame( 3, \diluxone_mail_log_count( array( 'a@x.test' ) ) );
		$this->assertStringNotContainsString( 'site_id', $this->db->of( 'get_var' )[0]['sql'] );
	}

	public function test_los_estados_tienen_nombre(): void {
		$this->assertArrayHasKey( 'intercepted', \diluxone_mail_log_statuses() );
		$this->assertArrayHasKey( 'bounced', \diluxone_mail_log_statuses() );
	}
}
