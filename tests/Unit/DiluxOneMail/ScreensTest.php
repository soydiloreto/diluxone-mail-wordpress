<?php
/**
 * Las pantallas pintan sin romperse y dicen lo que tienen que decir.
 */

namespace Tests\Unit\DiluxOneMail;

class ScreensTest extends AdminTestCase {

	public static function setUpBeforeClass(): void {
		$fluent = WP_PLUGIN_DIR . '/fluent-smtp/fluent-smtp.php';
		$azure  = WP_PLUGIN_DIR . '/azure-app-service-email/plugin.php';
		@mkdir( dirname( $fluent ), 0777, true );
		@mkdir( dirname( $azure ), 0777, true );
		file_put_contents( $fluent, "<?php\nfunction fluent_test_mailer( \$m ) {}\n" );
		file_put_contents( $azure, "<?php\nreturn static function ( \$pre ) { return false; };\n" );
		require_once $fluent;
	}

	public function test_el_menu_y_los_estilos(): void {
		$GLOBALS['_test_menu']    = array();
		$GLOBALS['_test_submenu'] = array();
		$GLOBALS['_test_styles']  = array();

		\diluxone_mail_menu();
		\diluxone_mail_network_menu();

		$this->assertCount( 1, $GLOBALS['_test_menu'] );
		$this->assertCount( 5, $GLOBALS['_test_submenu'] );

		\diluxone_mail_admin_styles( 'toplevel_page_diluxone-mail' );
		\diluxone_mail_admin_styles( 'edit.php' );
		$this->assertCount( 1, $GLOBALS['_test_styles'] );
	}

	public function test_la_pantalla_de_ajustes_muestra_los_perfiles_y_la_prueba(): void {
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\set_transient( 'diluxone_mail_test_1', array( 'ok' => false, 'error' => '535 nope', 'transcript' => 'AUTH LOGIN', 'seconds' => 0.1, 'to' => 'a@x.test' ), 60 );

		$html = $this->render( 'diluxone_mail_screen_settings' );

		$this->assertStringContainsString( 'Mailjet', $html );
		$this->assertStringContainsString( '535 nope', $html );
		$this->assertStringContainsString( 'AUTH LOGIN', $html );
		$this->assertStringContainsString( 'diluxone_mail_save_settings', $html );
		$this->assertStringNotContainsString( 'name="diluxone_mail_network_allow_override"', $html );
	}

	public function test_la_pantalla_de_ajustes_con_el_entorno_y_la_prueba_exitosa(): void {
		putenv( 'DILUXONE_MAIL_PASS=secreta' );
		putenv( 'DILUXONE_MAIL_PROVIDER=ses' );
		\set_transient( 'diluxone_mail_test_1', array( 'ok' => true, 'error' => '', 'transcript' => '', 'seconds' => 0.2, 'to' => 'a@x.test' ), 60 );

		$html = $this->render( 'diluxone_mail_screen_settings' );

		$this->assertStringContainsString( 'defined by the environment', $html );
		$this->assertStringContainsString( 'Replace the region', $html );
		$this->assertStringContainsString( 'handed to the server', $html );
		$this->assertStringNotContainsString( 'secreta', $html );
	}

	public function test_la_pantalla_de_la_red(): void {
		$GLOBALS['_test_multisite'] = true;

		$html = $this->render( 'diluxone_mail_screen_network' );

		$this->assertStringContainsString( 'Network settings', $html );
		$this->assertStringContainsString( 'name="diluxone_mail_network_allow_override"', $html );

		$sitio = $this->render( 'diluxone_mail_screen_settings' );
		$this->assertStringContainsString( 'fixed by the network', $sitio );
	}

	public function test_la_pantalla_de_estado(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_last_result', array( 'ok' => 0, 'time' => time() - 60, 'error' => 'boom', 'provider' => 'mailjet' ) );
		$GLOBALS['_test_cron']['diluxone_mail_purge'] = time() + 3600;
		$this->db->next_results                        = array( array( 'status' => 'failed', 'n' => 1 ) );

		$html = $this->render( 'diluxone_mail_screen_status' );

		$this->assertStringContainsString( 'boom', $html );
		$this->assertStringContainsString( 'Failed: 1', $html );
		$this->assertStringContainsString( 'Next purge', $html );

		\update_option( 'diluxone_mail_mode', 'observe' );
		\update_option( 'diluxone_mail_log_enabled', 0 );
		$this->assertStringContainsString( 'Observer mode', $this->render( 'diluxone_mail_screen_status' ) );
	}

	public function test_la_pantalla_de_estado_en_una_red_y_con_interceptor(): void {
		$GLOBALS['_test_multisite'] = true;
		\update_site_option( 'diluxone_mail_unhook_pre_wp_mail', 1 );
		\add_action( 'phpmailer_init', 'fluent_test_mailer' );
		$plugin = WP_PLUGIN_DIR . '/azure-app-service-email/plugin.php';
		\add_filter( 'pre_wp_mail', require $plugin, 10, 2 );
		\diluxone_mail_other_mailers( true );
		\update_option( 'diluxone_mail_last_result', array( 'ok' => 1, 'time' => time() - 60, 'error' => '', 'provider' => 'mailjet' ) );

		$html = $this->render( 'diluxone_mail_screen_status' );

		$this->assertStringContainsString( 'Being detached', $html );
		$this->assertStringContainsString( 'fixed by the network', $html );
		$this->assertStringContainsString( 'Delivered to the server', $html );
	}

	public function test_la_pantalla_del_historial(): void {
		$this->db->next_var     = 2;
		$this->db->next_results = array( $this->row(), $this->row( array( 'id' => 8, 'kind' => 'cc', 'status' => 'failed', 'error' => 'no', 'provider' => 'observer', 'subject' => '' ) ) );

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'ana@x.test', $html );
		$this->assertStringContainsString( '(CC)', $html );
		$this->assertStringContainsString( 'Failed — no', $html );
		$this->assertStringContainsString( 'another plugin', $html );
		$this->assertStringContainsString( '(no subject)', $html );
		$this->assertStringContainsString( 'search-box', $html );

		\update_option( 'diluxone_mail_log_enabled', 0 );
		$this->assertStringContainsString( 'The mail log is off', $this->render( 'diluxone_mail_screen_log' ) );
	}

	public function test_la_pantalla_del_historial_vacia_y_con_filtros(): void {
		$_GET['status'] = 'failed';
		$_GET['s']      = 'hola';
		$_GET['all']    = '1';
		$GLOBALS['_test_multisite'] = true;

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'No messages logged yet', $html );
		$this->assertStringContainsString( 'All sites', $html );
		$this->assertStringContainsString( "status = 'failed'", $this->db->of( 'get_var' )[0]['sql'] );
		$this->assertStringNotContainsString( 'site_id', $this->db->of( 'get_var' )[0]['sql'] );
	}

	public function test_el_detalle_de_un_mensaje(): void {
		$_GET['view']           = '7';
		$this->db->next_row     = $this->row();
		$this->db->next_results = array( $this->row(), $this->row( array( 'email' => 'b@x.test', 'kind' => 'cc' ) ) );

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'uuid-7', $html );
		$this->assertStringContainsString( 'X-Prueba', $html );
		$this->assertStringContainsString( 'a.pdf', $html );
		$this->assertStringContainsString( 'b@x.test', $html );
		$this->assertStringContainsString( 'body storage is off', $html );
	}

	public function test_el_detalle_con_cuerpo_html_y_dialogo(): void {
		$_GET['view']       = '7';
		$this->db->next_row = $this->row( array( 'error' => 'x', 'body' => '<p>hola</p>', 'body_type' => 'text/html', 'transcript' => 'EHLO' ) );

		$html = $this->render( 'diluxone_mail_screen_log' );

		$this->assertStringContainsString( 'srcdoc', $html );
		$this->assertStringContainsString( 'EHLO', $html );
		$this->assertStringContainsString( 'Resend to this recipient', $html );
	}

	public function test_el_detalle_de_un_mensaje_que_no_existe_o_de_otro_sitio(): void {
		$_GET['view'] = '99';
		$this->assertStringContainsString( 'no longer in the log', $this->render( 'diluxone_mail_screen_log' ) );

		$GLOBALS['_test_multisite'] = true;
		$GLOBALS['_test_blog_id']   = 2;
		$this->db->next_row         = $this->row();

		// El superadministrador lo ve; el stub dice que somos superadmin.
		$this->assertStringContainsString( 'uuid-7', $this->render( 'diluxone_mail_screen_log' ) );
		$GLOBALS['_test_blog_id'] = 1;
	}

	public function test_la_pantalla_de_entregabilidad(): void {
		\update_option( 'diluxone_mail_from', 'hola@sano.test' );
		$this->assertStringContainsString( 'sano.test', $this->render( 'diluxone_mail_screen_dns' ) );

		$html = $this->render( 'diluxone_mail_screen_dns' );
		$this->assertStringContainsString( 'Revalidate', $html );
		$this->assertStringContainsString( 'No record', $html );
	}

	public function test_la_pantalla_de_entregabilidad_sin_dominio(): void {
		\update_option( 'diluxone_mail_dns_domain', '' );

		// Sin remitente ni dominio configurados sale el del sitio; forzamos
		// vacío por el ajuste que la vista mira.
		\add_filter( 'diluxone_mail_option', static fn( $v, $k ) => 'diluxone_mail_dns_domain' === $k ? '' : $v, 10, 2 );

		$this->assertIsString( $this->render( 'diluxone_mail_screen_dns' ) );
	}

	public function test_el_titulo_de_la_pestana_en_una_pantalla_del_plugin(): void {
		$GLOBALS['_test_screen'] = new \WP_Screen( 'toplevel_page_diluxone-mail' );

		$this->assertSame( 'DiluxOne Mail | Settings — Sitio', \diluxone_mail_admin_title( 'Settings — Sitio', 'Settings' ) );

		$GLOBALS['_test_screen'] = new \WP_Screen( 'profile' );
		$_GET['diluxone_mail_done'] = 'resent';
		$this->assertStringContainsString( 'Message resent', $this->render( 'diluxone_mail_profile_notices' ) );

		$GLOBALS['_test_screen'] = new \WP_Screen( 'edit-post' );
		$this->assertSame( '', $this->render( 'diluxone_mail_profile_notices' ) );
	}
}
