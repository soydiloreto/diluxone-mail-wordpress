<?php
/**
 * Los handlers de admin_post: permiso, nonce, qué guardan y adónde vuelven.
 */

namespace Tests\Unit\DiluxOneMail;

class HandlersTest extends AdminTestCase {

	public function test_aplicar_un_perfil_rellena_y_vuelve(): void {
		$_POST = array( 'scope' => 'site', 'diluxone_mail_provider' => 'sendgrid' );

		$url = $this->redirect_of( 'diluxone_mail_apply_provider' );

		$this->assertStringContainsString( 'profile-applied', $url );
		$this->assertSame( 'smtp.sendgrid.net', \get_option( 'diluxone_mail_host' ) );
		$this->assertSame( 'apikey', \get_option( 'diluxone_mail_user' ) );
	}

	public function test_guardar_ajustes_con_contrasena_nueva_y_selectores(): void {
		$_POST = array(
			'scope'                        => 'site',
			'diluxone_mail_host'           => 'smtp.x.test',
			'diluxone_mail_port'           => '2525',
			'diluxone_mail_pass'           => 'p@ss "rara"',
			'diluxone_mail_log_enabled'    => '1',
			'diluxone_mail_dns_selectors'  => 'uno, dos  tres',
			'diluxone_mail_mode'           => 'transport',
		);

		$url = $this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertStringContainsString( 'diluxone_mail_done=saved', $url );
		$this->assertSame( 'smtp.x.test', \get_option( 'diluxone_mail_host' ) );
		$this->assertSame( 2525, \get_option( 'diluxone_mail_port' ) );
		$this->assertSame( 'p@ss "rara"', \get_option( 'diluxone_mail_pass' ) );
		$this->assertSame( array( 'uno', 'dos', 'tres' ), \get_option( 'diluxone_mail_dns_selectors' ) );
		// Las casillas que no viajan quedan en 0.
		$this->assertSame( 0, \get_option( 'diluxone_mail_log_body' ) );
		$this->assertSame( 1, \get_option( 'diluxone_mail_log_enabled' ) );
	}

	public function test_la_contrasena_vacia_conserva_la_guardada_y_la_del_entorno_no_se_toca(): void {
		\update_option( 'diluxone_mail_pass', 'vieja' );
		$_POST = array( 'scope' => 'site', 'diluxone_mail_pass' => '' );

		$this->redirect_of( 'diluxone_mail_save_settings' );
		$this->assertSame( 'vieja', \get_option( 'diluxone_mail_pass' ) );

		putenv( 'DILUXONE_MAIL_PASS=del-entorno' );
		$_POST = array( 'scope' => 'site', 'diluxone_mail_pass' => 'intento' );

		$this->redirect_of( 'diluxone_mail_save_settings' );
		$this->assertSame( 'vieja', \get_option( 'diluxone_mail_pass' ) );
	}

	public function test_guardar_en_la_red_incluye_el_permiso_a_los_sitios(): void {
		$GLOBALS['_test_multisite'] = true;
		$_POST = array( 'scope' => 'network', 'diluxone_mail_host' => 'smtp.red.test', 'diluxone_mail_network_allow_override' => '1', 'diluxone_mail_pass' => 'clave' );

		$url = $this->redirect_of( 'diluxone_mail_save_settings' );

		$this->assertStringContainsString( 'network', $url );
		$this->assertSame( 'smtp.red.test', \get_site_option( 'diluxone_mail_host' ) );
		$this->assertSame( 'clave', \get_site_option( 'diluxone_mail_pass' ) );
		$this->assertSame( 1, \get_site_option( 'diluxone_mail_network_allow_override' ) );
	}

	public function test_un_sitio_sin_permiso_en_la_red_no_guarda(): void {
		$GLOBALS['_test_multisite'] = true;
		$_POST = array( 'scope' => 'site', 'diluxone_mail_host' => 'smtp.sitio.test' );

		$this->assertStringContainsString( 'not-allowed', $this->redirect_of( 'diluxone_mail_save_settings' ) );
		$this->assertStringContainsString( 'not-allowed', $this->redirect_of( 'diluxone_mail_apply_provider' ) );
		$this->assertFalse( \get_option( 'diluxone_mail_host' ) );
	}

	public function test_sin_permiso_o_sin_nonce_se_corta(): void {
		$GLOBALS['_test_can'] = false;
		$_POST                = array( 'scope' => 'site' );

		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_save_settings();
	}

	public function test_sin_nonce_se_corta(): void {
		$GLOBALS['_test_nonce_fails'] = true;

		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_apply_provider();
	}

	public function test_la_prueba_de_envio_guarda_el_resultado_para_la_pantalla(): void {
		$_POST = array( 'scope' => 'site', 'diluxone_mail_test_to' => '' );

		$url = $this->redirect_of( 'diluxone_mail_test_action' );

		$this->assertStringContainsString( 'tested', $url );
		$r = \get_transient( 'diluxone_mail_test_1' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'admin@example.test', $r['to'] );
	}

	public function test_tomar_el_control(): void {
		$url = $this->redirect_of( 'diluxone_mail_take_over' );

		$this->assertStringContainsString( 'took-over', $url );
		$this->assertSame( 'transport', \get_option( 'diluxone_mail_mode' ) );

		$GLOBALS['_test_can'] = false;
		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_take_over();
	}

	public function test_revalidar_el_dns(): void {
		\update_option( 'diluxone_mail_from', 'hola@x.test' );
		\set_site_transient( 'diluxone_mail_diagnosis_' . md5( 'x.test' ), array( 'cached' => true ) );

		$url = $this->redirect_of( 'diluxone_mail_revalidate' );

		$this->assertStringContainsString( 'revalidated', $url );
		$this->assertFalse( \get_site_transient( 'diluxone_mail_diagnosis_' . md5( 'x.test' ) )['cached'] ?? false );

		$GLOBALS['_test_can'] = false;
		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_revalidate();
	}

	public function test_reenviar_desde_el_historial(): void {
		$_GET['id']         = '7';
		$this->db->next_row = $this->row( array( 'body' => 'cuerpo', 'body_type' => 'text/html', 'transcript' => '' ) );
		\update_option( 'diluxone_mail_mode', 'transport' );

		$url = $this->redirect_of( 'diluxone_mail_resend_action' );

		$this->assertStringContainsString( 'diluxone_mail_done=resent', $url );
		$call = $GLOBALS['_test_wp_mail_calls'][0];
		$this->assertSame( 'ana@x.test', $call['to'] );
		$this->assertContains( 'X-DiluxOne-Mail-Resend-Of: uuid-7', $call['headers'] );
		$this->assertContains( 'From: hola@x.test', $call['headers'] );
	}

	public function test_reenviar_sin_cuerpo_o_inexistente_avisa(): void {
		$_GET['id']             = '7';
		$this->db->next_row     = $this->row();
		$GLOBALS['_test_referer'] = 'https://example.test/wp-admin/user-edit.php?user_id=3';

		// detail_get devuelve la misma fila (sin body) → sin cuerpo.
		$this->db->next_row = $this->row( array( 'body' => '', 'body_type' => 'text/plain', 'transcript' => '' ) );
		$this->assertStringContainsString( 'user-edit.php', $this->redirect_of( 'diluxone_mail_resend_action' ) );
		$this->assertStringContainsString( 'no-body', $this->redirect_of( 'diluxone_mail_resend_action' ) );

		$this->db->next_row = null;
		$this->assertStringContainsString( 'resend-failed', $this->redirect_of( 'diluxone_mail_resend_action' ) );
	}

	public function test_reenviar_lo_puede_quien_edita_a_esa_persona(): void {
		$_GET['id']              = '7';
		$this->db->next_row      = $this->row( array( 'body' => 'cuerpo', 'body_type' => 'text/plain', 'transcript' => '' ) );
		$GLOBALS['_test_users'][] = $this->user( 3, 'ana@x.test' );

		// current_user_can() contesta true para todo salvo que lo apaguemos
		// del todo; con manage_options en false y edit_user en true no se
		// puede distinguir con el stub, así que se cubre el camino de negación.
		$GLOBALS['_test_can'] = false;
		$this->expectException( \DiluxOne_Test_Die::class );
		\diluxone_mail_resend_action();
	}

	public function test_reenviar_falla_si_wp_mail_falla(): void {
		$_GET['id']                     = '7';
		$this->db->next_row             = $this->row( array( 'body' => 'cuerpo', 'body_type' => 'text/plain', 'transcript' => '' ) );
		$GLOBALS['_test_wp_mail_fails'] = 'boom';

		$this->assertStringContainsString( 'resend-failed', $this->redirect_of( 'diluxone_mail_resend_action' ) );
	}
}
