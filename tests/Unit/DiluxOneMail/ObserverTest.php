<?php
/**
 * ¿Hay otro plugin gestionando el correo? Y la trampa de pre_wp_mail.
 *
 * Los «otros plugins» son archivos de verdad en un WP_PLUGIN_DIR temporal:
 * la detección se basa en el archivo de cada callback, y con un archivo real
 * se prueba también el mapeo de la carpeta al nombre bonito.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/observer.php';
require_once __DIR__ . '/../../../includes/pre-wp-mail.php';

class ObserverTest extends TestCase {

	private static string $fluent;
	private static string $azure;

	public static function setUpBeforeClass(): void {
		// Dos plugins de mentira: uno conocido por su carpeta, y uno que se
		// engancha con una closure —el caso de Azure App Service—.
		self::$fluent = WP_PLUGIN_DIR . '/fluent-smtp/fluent-smtp.php';
		self::$azure  = WP_PLUGIN_DIR . '/azure-app-service-email/plugin.php';

		@mkdir( dirname( self::$fluent ), 0777, true );
		@mkdir( dirname( self::$azure ), 0777, true );

		file_put_contents( self::$fluent, "<?php\nfunction fluent_test_mailer( \$m ) {}\n" );
		file_put_contents( self::$azure, "<?php\nreturn static function ( \$pre ) { return false; };\n" );

		require_once self::$fluent;
	}

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_site_options'] = array();
		$GLOBALS['_test_multisite']       = false;
		$GLOBALS['wp_filter']             = array();
	}

	public function test_sin_nadie_mas_el_transporte_es_nuestro(): void {
		$this->assertSame( array(), \diluxone_mail_other_mailers( true ) );
		$this->assertTrue( \diluxone_mail_transport_active() );
	}

	public function test_un_plugin_conocido_en_phpmailer_init_se_nombra(): void {
		\add_action( 'phpmailer_init', 'fluent_test_mailer' );

		$otros = \diluxone_mail_other_mailers( true );

		$this->assertCount( 1, $otros );
		$this->assertSame( 'FluentSMTP', $otros[0]['name'] );
		$this->assertSame( 'fluent-smtp', $otros[0]['plugin'] );
		$this->assertSame( 'phpmailer_init', $otros[0]['how'] );
		$this->assertFalse( \diluxone_mail_transport_active() );
	}

	public function test_una_closure_en_pre_wp_mail_se_rastrea_a_su_archivo(): void {
		$closure = require self::$azure;

		\add_filter( 'pre_wp_mail', $closure, 10, 2 );

		$interceptores = \diluxone_mail_pre_wp_mail_interceptors();

		$this->assertCount( 1, $interceptores );
		$this->assertSame( 'azure-app-service-email', $interceptores[0]['plugin'] );
		$this->assertSame( 'azure-app-service-email', \diluxone_mail_pre_wp_mail_culprit() );
		$this->assertSame( 'pre_wp_mail', \diluxone_mail_other_mailers( true )[0]['how'] );
	}

	public function test_nuestros_propios_hooks_no_cuentan(): void {
		\add_action( 'phpmailer_init', 'diluxone_mail_transport_active' );

		$this->assertSame( array(), \diluxone_mail_hook_origins( 'phpmailer_init' ) );
	}

	public function test_el_modo_manda_sobre_la_deteccion(): void {
		\add_action( 'phpmailer_init', 'fluent_test_mailer' );
		\diluxone_mail_other_mailers( true );

		\update_option( 'diluxone_mail_mode', 'transport' );
		$this->assertTrue( \diluxone_mail_transport_active() );

		\update_option( 'diluxone_mail_mode', 'observe' );
		$this->assertFalse( \diluxone_mail_transport_active() );
	}

	public function test_desenganchar_saca_solo_al_interceptor(): void {
		$closure = require self::$azure;
		$propio  = static fn( $pre ) => $pre;

		\add_filter( 'pre_wp_mail', $closure, 10, 2 );
		\add_filter( 'pre_wp_mail', $propio, 20, 2 );

		\update_option( 'diluxone_mail_unhook_pre_wp_mail', 1 );
		\diluxone_mail_pre_wp_mail_unhook( array() );

		$this->assertSame( array(), \diluxone_mail_pre_wp_mail_interceptors() );
		// El otro callback —de este archivo, que no es un plugin— sigue ahí.
		$this->assertArrayHasKey( 20, $GLOBALS['wp_filter']['pre_wp_mail']->callbacks );
	}

	public function test_sin_la_casilla_no_se_desengancha_nada(): void {
		$closure = require self::$azure;
		\add_filter( 'pre_wp_mail', $closure, 10, 2 );

		\diluxone_mail_pre_wp_mail_unhook( array() );

		$this->assertCount( 1, \diluxone_mail_pre_wp_mail_interceptors() );
	}

	public function test_el_origen_de_un_metodo_y_de_un_invocable(): void {
		$obj = new class() {
			public function m(): void {}
			public function __invoke(): void {}
		};

		$this->assertStringEndsWith( 'ObserverTest.php', \diluxone_mail_callback_origin( array( $obj, 'm' ) )['file'] );
		$this->assertStringEndsWith( 'ObserverTest.php', \diluxone_mail_callback_origin( $obj )['file'] );
		$this->assertSame( '', \diluxone_mail_callback_origin( 'no_existe_esta_funcion' )['file'] );
	}

	public function test_el_aviso_del_observador_ofrece_tomar_el_control(): void {
		\add_action( 'phpmailer_init', 'fluent_test_mailer' );
		\diluxone_mail_other_mailers( true );

		ob_start();
		\diluxone_mail_observer_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'FluentSMTP', $html );
		$this->assertStringContainsString( 'diluxone_mail_take_over', $html );

		\update_option( 'diluxone_mail_mode', 'observe' );
		ob_start();
		\diluxone_mail_observer_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_el_aviso_de_pre_wp_mail(): void {
		ob_start();
		\diluxone_mail_pre_wp_mail_notice();
		$this->assertSame( '', ob_get_clean() );

		\add_filter( 'pre_wp_mail', require self::$azure, 10, 2 );

		ob_start();
		\diluxone_mail_pre_wp_mail_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'azure-app-service-email', $html );
		$this->assertStringContainsString( 'notice-info', $html );

		\update_option( 'diluxone_mail_unhook_pre_wp_mail', 1 );
		ob_start();
		\diluxone_mail_pre_wp_mail_notice();
		$this->assertStringContainsString( 'notice-warning', ob_get_clean() );
	}
}
