<?php
/**
 * Los flujos del admin contra un WordPress de verdad: nonces reales,
 * permisos reales, redirecciones reales, y las pantallas pintando con los
 * datos de la base.
 *
 * wp_safe_redirect() termina en exit(); el filtro wp_redirect corre antes y
 * acá lanza una excepción con la URL, que es lo que el test quiere ver.
 */

namespace Tests\Integration;

class RedirectedException extends \Exception {}

class AdminFlowTest extends IntegrationTestCase {

	private int $admin;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		diluxone_mail_install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . diluxone_mail_log_table() );

		$this->admin = $this->alguien( 'administrator' );
		wp_set_current_user( $this->admin );

		add_filter( 'wp_redirect', array( $this, 'catch_redirect' ) );

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'catch_redirect' ) );
		parent::tearDown();
	}

	/** @param string $url */
	public function catch_redirect( $url ): string {
		throw new RedirectedException( (string) $url );
	}

	private function redirect_of( callable $handler ): string {
		try {
			$handler();
		} catch ( RedirectedException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'no redirigió' );
	}

	private function nonce( string $action ): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
	}

	private function interceptar(): callable {
		$fn = static fn( $pre ): bool => null === $pre ? true : (bool) $pre;
		add_filter( 'pre_wp_mail', $fn, 10, 1 );
		return $fn;
	}

	public function test_aplicar_un_perfil_y_guardar_los_ajustes_con_nonce_real(): void {
		$this->nonce( 'diluxone_mail_settings' );
		$_POST['scope']                  = 'site';
		$_POST['diluxone_mail_provider'] = 'mailjet';

		$this->assertStringContainsString( 'profile-applied', $this->redirect_of( 'diluxone_mail_apply_provider' ) );
		$this->assertSame( 'in-v3.mailjet.com', get_option( 'diluxone_mail_host' ) );

		$_POST = array(
			'scope'                  => 'site',
			'_wpnonce'               => $_REQUEST['_wpnonce'],
			'diluxone_mail_host'     => 'smtp.propio.test',
			'diluxone_mail_pass'     => 'clave nueva',
			'diluxone_mail_from'     => 'hola@propio.test',
			'diluxone_mail_log_body' => '1',
		);

		$this->assertStringContainsString( 'saved', $this->redirect_of( 'diluxone_mail_save_settings' ) );
		$this->assertSame( 'smtp.propio.test', get_option( 'diluxone_mail_host' ) );
		$this->assertSame( 'clave nueva', get_option( 'diluxone_mail_pass' ) );
		$this->assertSame( 1, (int) get_option( 'diluxone_mail_log_body' ) );
		$this->assertSame( 0, (int) get_option( 'diluxone_mail_log_extended' ) );
	}

	public function test_sin_nonce_valido_no_se_guarda_nada(): void {
		$_POST['scope']              = 'site';
		$_POST['diluxone_mail_host'] = 'smtp.ataque.test';
		$_REQUEST['_wpnonce']        = 'inventado';

		try {
			diluxone_mail_save_settings();
			$this->fail( 'tendría que haber muerto' );
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->assertFalse( get_option( 'diluxone_mail_host' ) );
		}
	}

	public function test_sin_permiso_no_se_guarda_nada(): void {
		wp_set_current_user( $this->alguien( 'subscriber' ) );
		$this->nonce( 'diluxone_mail_settings' );
		$_POST['scope'] = 'site';

		$this->expectException( \WPAjaxDieContinueException::class );
		diluxone_mail_save_settings();
	}

	public function test_tomar_el_control_y_revalidar(): void {
		$this->nonce( 'diluxone_mail_take_over' );
		$this->assertStringContainsString( 'took-over', $this->redirect_of( 'diluxone_mail_take_over' ) );
		$this->assertSame( 'transport', get_option( 'diluxone_mail_mode' ) );

		update_option( 'diluxone_mail_from', 'hola@example.org' );
		update_option( 'diluxone_mail_dns_resolver', 'doh' );
		$this->nonce( 'diluxone_mail_revalidate' );
		$this->assertStringContainsString( 'revalidated', $this->redirect_of( 'diluxone_mail_revalidate' ) );
		$this->assertIsArray( get_site_transient( 'diluxone_mail_diagnosis_' . md5( 'example.org' ) ) );
	}

	public function test_el_reenvio_de_verdad_con_el_cuerpo_guardado(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		update_option( 'diluxone_mail_log_body', 1 );

		$fn = $this->interceptar();
		wp_mail( 'ana@ejemplo.test', 'Original', '<p>Cuerpo</p>', array( 'Content-Type: text/html' ) );

		$fila = diluxone_mail_log_query( array( 'emails' => array( 'ana@ejemplo.test' ) ) )['rows'][0];

		$_GET['id'] = (string) $fila['id'];
		$this->nonce( 'diluxone_mail_resend_' . (int) $fila['id'] );

		$url = $this->redirect_of( 'diluxone_mail_resend_action' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertStringContainsString( 'diluxone_mail_done=resent', $url );
		$this->assertSame( 2, diluxone_mail_log_query( array( 'emails' => array( 'ana@ejemplo.test' ) ) )['total'] );

		$reenvio = diluxone_mail_log_query( array( 'emails' => array( 'ana@ejemplo.test' ) ) )['rows'][0];
		$this->assertSame( 'Original', $reenvio['subject'] );
		$this->assertSame( '<p>Cuerpo</p>', diluxone_mail_detail_get( (string) $reenvio['message_id'] )['body'] );
	}

	public function test_el_reenvio_sin_cuerpo_avisa(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		$fn = $this->interceptar();
		wp_mail( 'sin@ejemplo.test', 'Sin cuerpo', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		$fila       = diluxone_mail_log_query( array( 'emails' => array( 'sin@ejemplo.test' ) ) )['rows'][0];
		$_GET['id'] = (string) $fila['id'];
		$this->nonce( 'diluxone_mail_resend_' . (int) $fila['id'] );

		$this->assertStringContainsString( 'no-body', $this->redirect_of( 'diluxone_mail_resend_action' ) );
	}

	public function test_la_ficha_de_una_persona_pinta_lo_suyo(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		$id   = $this->alguien();
		$user = get_user_by( 'id', $id );

		$fn = $this->interceptar();
		wp_mail( $user->user_email, 'Para vos', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		ob_start();
		diluxone_mail_user_profile_section( $user );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Para vos', $html );
		$this->assertStringContainsString( 'Handed to another plugin', $html );

		wp_set_current_user( $this->alguien( 'subscriber' ) );
		ob_start();
		diluxone_mail_user_profile_section( $user );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_las_pantallas_pintan_con_datos_reales(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		update_option( 'diluxone_mail_from', 'hola@example.org' );
		update_option( 'diluxone_mail_dns_resolver', 'doh' );

		$fn = $this->interceptar();
		wp_mail( 'lista@ejemplo.test', 'En la lista', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		set_current_screen( 'toplevel_page_diluxone-mail' );

		ob_start();
		diluxone_mail_screen_settings();
		$ajustes = (string) ob_get_clean();
		$this->assertStringContainsString( 'diluxone_mail_save_settings', $ajustes );

		ob_start();
		diluxone_mail_screen_log();
		$log = (string) ob_get_clean();
		$this->assertStringContainsString( 'En la lista', $log );

		$fila        = diluxone_mail_log_query( array( 'emails' => array( 'lista@ejemplo.test' ) ) )['rows'][0];
		$_GET['view'] = (string) $fila['id'];
		ob_start();
		diluxone_mail_screen_log();
		$detalle = (string) ob_get_clean();
		$this->assertStringContainsString( (string) $fila['message_id'], $detalle );

		ob_start();
		diluxone_mail_screen_status();
		$estado = (string) ob_get_clean();
		$this->assertStringContainsString( 'Observer mode', $estado );

		ob_start();
		diluxone_mail_screen_dns();
		$dns = (string) ob_get_clean();
		$this->assertStringContainsString( 'example.org', $dns );
	}

	/**
	 * DoH de verdad, contra un resolver público, desde el contenedor.
	 *
	 * Es la única consulta al DNS real de la suite y se hace a propósito:
	 * dns_get_record() se prueba en el E2E, DoH acá.
	 */
	public function test_doh_contra_un_resolver_publico(): void {
		update_option( 'diluxone_mail_dns_resolver', 'doh' );

		$r = diluxone_mail_dns_lookup( 'pablodiloreto.com', 'TXT' );

		if ( '' !== $r['error'] ) {
			$this->markTestSkipped( 'sin red: ' . $r['error'] );
		}

		$this->assertSame( 'doh', $r['source'] );
		$this->assertNotEmpty( array_filter( $r['records'], static fn( string $t ): bool => str_starts_with( strtolower( $t ), 'v=spf1' ) ) );
		$this->assertSame( 'cache', diluxone_mail_dns_lookup( 'pablodiloreto.com', 'TXT' )['source'] );
	}

	public function test_la_purga_esta_programada_y_la_privacidad_registrada(): void {
		diluxone_mail_schedule_purge();
		$this->assertNotFalse( wp_next_scheduled( 'diluxone_mail_purge' ) );

		$this->assertArrayHasKey( 'diluxone-mail', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'diluxone-mail', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		diluxone_mail_deactivate();
		$this->assertFalse( wp_next_scheduled( 'diluxone_mail_purge' ) );
	}

	public function test_la_tabla_del_historial_pagina_de_verdad(): void {
		update_option( 'diluxone_mail_mode', 'observe' );
		$fn = $this->interceptar();
		for ( $i = 0; $i < 35; $i++ ) {
			wp_mail( "p{$i}@ejemplo.test", "Mensaje {$i}", 'x' );
		}
		remove_filter( 'pre_wp_mail', $fn, 10 );

		require_once DILUXONE_MAIL_DIR . 'includes/log-list-table.php';

		$_REQUEST['paged'] = '2';
		$tabla         = new \DiluxOne_Mail_Log_Table();
		$tabla->prepare_items();

		$this->assertCount( 5, $tabla->items );
		$this->assertSame( 35, $tabla->get_pagination_arg( 'total_items' ) );
	}
}
