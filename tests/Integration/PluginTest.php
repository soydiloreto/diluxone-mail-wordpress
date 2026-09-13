<?php
/**
 * Que el plugin arranque de verdad en un WordPress limpio.
 *
 * Los tests unitarios corren contra stubs y no ven esto: que el archivo
 * principal cargue todos sus includes sin chocar, y que las tablas del
 * historial existan después de activar. Es el piso de «funciona en una
 * instalación nueva».
 */

namespace Tests\Integration;

class PluginTest extends IntegrationTestCase {

	public function test_el_plugin_esta_cargado(): void {
		$this->assertTrue( defined( 'DILUXONE_MAIL_VERSION' ) );
		$this->assertTrue( function_exists( 'diluxone_mail_option' ) );
		$this->assertTrue( function_exists( 'diluxone_mail_config' ) );
	}

	public function test_las_opciones_tienen_valor_por_defecto(): void {
		// Sin nada guardado, cada ajuste tiene que contestar algo razonable:
		// un plugin recién instalado no puede depender de que alguien pase
		// por todas sus pantallas antes de que el sitio funcione.
		$this->assertSame( 'auto', diluxone_mail_option( 'diluxone_mail_mode' ) );
		$this->assertSame( 587, (int) diluxone_mail_option( 'diluxone_mail_port' ) );
		$this->assertSame( 1, (int) diluxone_mail_option( 'diluxone_mail_log_enabled' ) );
	}

	/**
	 * El cuerpo de los mensajes no se guarda salvo que alguien lo pida.
	 *
	 * Va como test y no como comentario porque es una promesa de privacidad:
	 * si un día alguien cambia el valor por defecto sin pensarlo, todos los
	 * sitios que actualicen empiezan a guardar el contenido del correo de su
	 * gente sin que nadie lo haya decidido.
	 */
	public function test_el_cuerpo_del_mensaje_no_se_guarda_por_defecto(): void {
		$this->assertSame( 0, (int) diluxone_mail_option( 'diluxone_mail_log_body' ) );
	}

	public function test_las_tablas_del_historial_existen(): void {
		global $wpdb;

		diluxone_mail_install();

		foreach ( array( diluxone_mail_log_table(), diluxone_mail_body_table() ) as $tabla ) {
			$existe = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla ) );

			$this->assertSame( $tabla, $existe, "falta la tabla {$tabla}" );
		}
	}

	/**
	 * dbDelta() es silenciosamente exigente con el formato del SQL: si no le
	 * gusta, no falla — recrea la tabla en cada carga de página. Correrlo dos
	 * veces y comprobar que la versión quedó firme es la forma barata de
	 * enterarse.
	 */
	public function test_instalar_dos_veces_no_rompe_nada(): void {
		diluxone_mail_install();
		diluxone_mail_install();

		$this->assertSame( DILUXONE_MAIL_DB_VERSION, (int) get_option( 'diluxone_mail_db_version' ) );
	}
}
