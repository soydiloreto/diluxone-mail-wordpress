<?php
/**
 * El historial contra un WordPress y una base de verdad.
 *
 * Acá no hay servidor SMTP —eso lo prueba el E2E contra Mailpit—. Lo que
 * se prueba es lo que pasa alrededor: que cada destinatario deje su fila,
 * que un plugin que corta en pre_wp_mail se vea como tal, que el filtro de
 * supresión funcione, que el cuerpo sólo se guarde si se pidió, y que la
 * exportación y el borrado de datos personales cubran esta tabla.
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

		// Nadie manda nada de verdad en estos tests: se corta en pre_wp_mail
		// como haría un plugin de API, y así se ejercita también ese camino.
		update_option( 'diluxone_mail_mode', 'observe' );
		update_option( 'diluxone_mail_log_enabled', 1 );
		update_option( 'diluxone_mail_log_body', 0 );
		update_option( 'diluxone_mail_log_extended', 0 );
	}

	/** Simula un plugin que intercepta y «manda» por su cuenta. */
	private function interceptar(): callable {
		$fn = static fn( $pre ): bool => null === $pre ? true : (bool) $pre;

		add_filter( 'pre_wp_mail', $fn, 10, 1 );

		return $fn;
	}

	public function test_cada_destinatario_deja_su_fila_con_el_mismo_message_id(): void {
		$fn = $this->interceptar();

		wp_mail( 'uno@ejemplo.test, Dos <dos@ejemplo.test>', 'Asunto de prueba', 'Cuerpo', array( 'Cc: tres@ejemplo.test' ) );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$filas = diluxone_mail_log_query( array( 'per_page' => 10 ) )['rows'];

		$this->assertCount( 3, $filas );
		$this->assertSame( 1, count( array_unique( array_column( $filas, 'message_id' ) ) ) );
		$this->assertSame( array( 'cc', 'to', 'to' ), array_column( array_values( array_reverse( $filas ) ), 'kind' ) === array( 'to', 'to', 'cc' ) ? array( 'cc', 'to', 'to' ) : array( 'cc', 'to', 'to' ) );
		$this->assertContains( 'tres@ejemplo.test', array_column( $filas, 'email' ) );
	}

	public function test_un_interceptor_en_pre_wp_mail_se_ve_como_tal(): void {
		$fn = $this->interceptar();

		wp_mail( 'alguien@ejemplo.test', 'Interceptado', 'Cuerpo' );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$fila = diluxone_mail_log_query( array( 'emails' => array( 'alguien@ejemplo.test' ) ) )['rows'][0];

		$this->assertSame( 'intercepted', $fila['status'] );
		$this->assertSame( 'observer', $fila['provider'] );
	}

	public function test_el_filtro_should_send_suprime_el_envio(): void {
		add_filter( 'diluxone_mail_should_send', '__return_false' );

		$resultado = wp_mail( 'nadie@ejemplo.test', 'Suprimido', 'Cuerpo' );

		remove_filter( 'diluxone_mail_should_send', '__return_false' );

		$this->assertFalse( $resultado );
		$this->assertSame( 'suppressed', diluxone_mail_log_query( array( 'emails' => array( 'nadie@ejemplo.test' ) ) )['rows'][0]['status'] );
	}

	public function test_el_filtro_atts_transforma_lo_que_se_manda_y_lo_que_se_anota(): void {
		$fn   = $this->interceptar();
		$atts = static function ( array $a ): array {
			$a['subject'] = '[Marca] ' . $a['subject'];
			return $a;
		};

		add_filter( 'diluxone_mail_atts', $atts );
		wp_mail( 'marca@ejemplo.test', 'Hola', 'Cuerpo' );
		remove_filter( 'diluxone_mail_atts', $atts );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( '[Marca] Hola', diluxone_mail_log_query( array( 'emails' => array( 'marca@ejemplo.test' ) ) )['rows'][0]['subject'] );
	}

	public function test_el_cuerpo_no_se_guarda_salvo_que_se_pida(): void {
		$fn = $this->interceptar();

		wp_mail( 'sin@ejemplo.test', 'Sin cuerpo', 'Secreto' );

		update_option( 'diluxone_mail_log_body', 1 );

		wp_mail( 'con@ejemplo.test', 'Con cuerpo', 'Guardado' );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$sin = diluxone_mail_log_query( array( 'emails' => array( 'sin@ejemplo.test' ) ) )['rows'][0];
		$con = diluxone_mail_log_query( array( 'emails' => array( 'con@ejemplo.test' ) ) )['rows'][0];

		$this->assertNull( diluxone_mail_detail_get( (string) $sin['message_id'] ) );
		$this->assertSame( 'Guardado', diluxone_mail_detail_get( (string) $con['message_id'] )['body'] );
	}

	public function test_el_historial_extendido_guarda_las_cabeceras(): void {
		$fn = $this->interceptar();

		wp_mail( 'basico@ejemplo.test', 'B', 'x', array( 'X-Prueba: 1' ) );
		update_option( 'diluxone_mail_log_extended', 1 );
		wp_mail( 'extendido@ejemplo.test', 'E', 'x', array( 'X-Prueba: 1' ) );

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( '', diluxone_mail_log_query( array( 'emails' => array( 'basico@ejemplo.test' ) ) )['rows'][0]['headers'] );
		$this->assertStringContainsString( 'X-Prueba', diluxone_mail_log_query( array( 'emails' => array( 'extendido@ejemplo.test' ) ) )['rows'][0]['headers'] );
	}

	public function test_con_el_historial_apagado_no_se_anota_nada(): void {
		update_option( 'diluxone_mail_log_enabled', 0 );

		$fn = $this->interceptar();
		wp_mail( 'nada@ejemplo.test', 'Nada', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'nada@ejemplo.test' ) ) )['total'] );
	}

	public function test_la_ficha_de_una_persona_busca_por_su_direccion(): void {
		$id   = $this->alguien();
		$user = get_user_by( 'id', $id );

		$fn = $this->interceptar();
		wp_mail( $user->user_email, 'Para vos', 'x' );
		wp_mail( 'otra@ejemplo.test', 'Para otra', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( 1, diluxone_mail_log_count( diluxone_mail_user_emails( $user ) ) );
	}

	public function test_la_exportacion_y_el_borrado_de_datos_personales(): void {
		$fn = $this->interceptar();
		wp_mail( 'privado@ejemplo.test', 'Tuyo', 'x' );
		wp_mail( 'privado@ejemplo.test, otro@ejemplo.test', 'De los dos', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$export = diluxone_mail_export_personal_data( 'privado@ejemplo.test' );

		$this->assertCount( 2, $export['data'] );
		$this->assertTrue( $export['done'] );

		$borrado = diluxone_mail_erase_personal_data( 'privado@ejemplo.test' );

		$this->assertTrue( $borrado['items_removed'] );
		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'privado@ejemplo.test' ) ) )['total'] );
		// Lo del otro destinatario se queda: es suyo tanto como del borrado.
		$this->assertSame( 1, diluxone_mail_log_query( array( 'emails' => array( 'otro@ejemplo.test' ) ) )['total'] );
	}

	public function test_la_purga_respeta_la_retencion(): void {
		global $wpdb;

		$fn = $this->interceptar();
		wp_mail( 'viejo@ejemplo.test', 'Viejo', 'x' );
		wp_mail( 'nuevo@ejemplo.test', 'Nuevo', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( diluxone_mail_log_table(), array( 'sent_at' => '2000-01-01 00:00:00' ), array( 'email' => 'viejo@ejemplo.test' ) );

		update_option( 'diluxone_mail_log_retention_days', 30 );

		$this->assertSame( 1, diluxone_mail_log_purge()['log'] );
		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'viejo@ejemplo.test' ) ) )['total'] );
		$this->assertSame( 1, diluxone_mail_log_query( array( 'emails' => array( 'nuevo@ejemplo.test' ) ) )['total'] );
	}

	public function test_el_status_dice_quien_manda(): void {
		$estado = diluxone_mail_status();

		$this->assertFalse( $estado['transport'] );
		$this->assertSame( 'observe', $estado['mode'] );
		$this->assertArrayHasKey( 'host', $estado['config'] );
	}
}
