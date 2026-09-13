<?php
/**
 * La precedencia entorno/base, que es la regla más importante del plugin.
 *
 * Si esto se rompe, una credencial de producción termina guardada en la base
 * de datos del sitio, que es exactamente lo que el diseño evita.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';

class ConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options'] = array();

		foreach ( \diluxone_mail_config_fields() as $sufijo ) {
			putenv( 'DILUXONE_MAIL_' . $sufijo );
		}
	}

	public function test_sin_nada_configurado_contesta_el_valor_por_defecto(): void {
		$host = \diluxone_mail_config_value( 'host' );

		$this->assertSame( '', $host['value'] );
		$this->assertSame( 'default', $host['source'] );
	}

	public function test_la_option_gana_cuando_no_hay_entorno(): void {
		\update_option( 'diluxone_mail_host', 'smtp.guardado.test' );

		$host = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.guardado.test', $host['value'] );
		$this->assertSame( 'site', $host['source'] );
		$this->assertSame( 'diluxone_mail_host', $host['origin'] );
	}

	public function test_la_variable_de_entorno_le_gana_a_la_option(): void {
		\update_option( 'diluxone_mail_host', 'smtp.guardado.test' );
		putenv( 'DILUXONE_MAIL_HOST=smtp.entorno.test' );

		$host = \diluxone_mail_config_value( 'host' );

		$this->assertSame( 'smtp.entorno.test', $host['value'] );
		$this->assertSame( 'env', $host['source'] );
		$this->assertSame( 'DILUXONE_MAIL_HOST', $host['origin'] );
	}

	/**
	 * Una variable exportada pero vacía es un renglón a medio escribir, no la
	 * decisión de dejar el host en blanco. Si contara como definida, un
	 * `export DILUXONE_MAIL_HOST=` en un script de despliegue dejaría el sitio
	 * sin correo y con la pantalla de ajustes en sólo lectura, sin forma de
	 * arreglarlo desde el admin.
	 */
	public function test_una_variable_vacia_es_como_no_tenerla(): void {
		\update_option( 'diluxone_mail_host', 'smtp.guardado.test' );
		putenv( 'DILUXONE_MAIL_HOST=' );

		$this->assertSame( 'site', \diluxone_mail_config_value( 'host' )['source'] );
	}

	/**
	 * La constante le gana a todo. Se prueba con from_name y no con host
	 * porque una constante no se puede borrar una vez definida, y los tests
	 * corren en orden aleatorio: definir DILUXONE_MAIL_HOST acá arruinaría
	 * los de arriba la mitad de las veces.
	 */
	public function test_la_constante_le_gana_a_la_variable_de_entorno(): void {
		define( 'DILUXONE_MAIL_FROM_NAME', 'Desde la constante' );

		\update_option( 'diluxone_mail_from_name', 'Desde la base' );
		putenv( 'DILUXONE_MAIL_FROM_NAME=Desde el entorno' );

		$nombre = \diluxone_mail_config_value( 'from_name' );

		$this->assertSame( 'Desde la constante', $nombre['value'] );
		$this->assertSame( 'constant', $nombre['source'] );
	}

	public function test_lo_que_manda_el_entorno_no_se_guarda_en_la_base(): void {
		putenv( 'DILUXONE_MAIL_HOST=smtp.entorno.test' );

		\diluxone_mail_save_options(
			array(
				'diluxone_mail_host' => 'smtp.intento.test',
				'diluxone_mail_port' => 2525,
			)
		);

		// El host no se tocó: lo manda el entorno.
		$this->assertArrayNotHasKey( 'diluxone_mail_host', $GLOBALS['_test_wp_options'] );
		// El puerto sí, que no lo manda nadie más.
		$this->assertSame( 2525, \get_option( 'diluxone_mail_port' ) );
	}

	public function test_no_se_guarda_una_clave_que_no_sea_un_ajuste(): void {
		\diluxone_mail_save_options( array( 'siteurl' => 'https://secuestrado.test' ) );

		$this->assertArrayNotHasKey( 'siteurl', $GLOBALS['_test_wp_options'] );
	}

	public function test_no_se_puede_escribir_una_option_interna_desde_el_formulario(): void {
		\diluxone_mail_save_options( array( 'diluxone_mail_db_version' => 99 ) );

		$this->assertArrayNotHasKey( 'diluxone_mail_db_version', $GLOBALS['_test_wp_options'] );
	}

	/**
	 * La contraseña viaja en base64 en el diálogo SMTP, no en claro. Tapar
	 * sólo el literal deja la credencial entera a la vista en el volcado del
	 * SMTPDebug, en una línea que cualquiera decodifica en un segundo.
	 */
	public function test_la_contrasena_se_tapa_tambien_en_base64(): void {
		\update_option( 'diluxone_mail_pass', 'secreto-de-prueba' );

		$dialogo = "AUTH LOGIN\r\n" . base64_encode( 'secreto-de-prueba' ) . "\r\n";
		$tapado  = \diluxone_mail_redact( $dialogo . 'y en claro: secreto-de-prueba' );

		$this->assertStringNotContainsString( 'secreto-de-prueba', $tapado );
		$this->assertStringNotContainsString( base64_encode( 'secreto-de-prueba' ), $tapado );
		$this->assertStringContainsString( '***', $tapado );
	}

	public function test_sin_contrasena_configurada_no_se_tapa_nada(): void {
		$this->assertSame( 'un texto cualquiera', \diluxone_mail_redact( 'un texto cualquiera' ) );
	}
}
