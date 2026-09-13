<?php
/**
 * El plugin en una red de verdad.
 *
 * Corre contra el sitio de pruebas de wp-env convertido a multisitio con
 * `wp core multisite-convert` y con un segundo sitio creado. Lo que se
 * prueba es lo que no se puede probar con stubs: que las tablas sean una
 * sola para la red, que un envío desde el sitio 2 quede con su site_id, que
 * la ficha de una persona vea lo de todos los sitios, y que la precedencia
 * red/sitio funcione con las options de WordPress de verdad.
 */

namespace Tests\Multisite;

use Tests\Integration\IntegrationTestCase;

class NetworkTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Este test necesita una red: wp core multisite-convert.' );
		}

		global $wpdb;

		diluxone_mail_install();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . diluxone_mail_log_table() );
		// phpcs:enable

		delete_site_option( 'diluxone_mail_network_allow_override' );
		delete_site_option( 'diluxone_mail_host' );
		delete_option( 'diluxone_mail_host' );
		update_site_option( 'diluxone_mail_mode', 'observe' );
		update_site_option( 'diluxone_mail_log_enabled', 1 );
	}

	/** El segundo sitio de la red, creado por el script de la suite. */
	private function segundo_sitio(): int {
		$sitios = get_sites( array( 'number' => 2, 'orderby' => 'id', 'order' => 'ASC' ) );

		$this->assertGreaterThanOrEqual( 2, count( $sitios ), 'la red necesita un segundo sitio' );

		return (int) $sitios[1]->blog_id;
	}

	private function interceptar(): callable {
		$fn = static fn( $pre ): bool => null === $pre ? true : (bool) $pre;
		add_filter( 'pre_wp_mail', $fn, 10, 1 );
		return $fn;
	}

	public function test_las_tablas_son_de_la_red(): void {
		global $wpdb;

		$this->assertStringStartsWith( $wpdb->base_prefix, diluxone_mail_log_table() );
		$this->assertSame( $wpdb->base_prefix . 'diluxone_mail_log', diluxone_mail_log_table() );
	}

	public function test_un_envio_desde_otro_sitio_queda_con_su_site_id(): void {
		$sitio2 = $this->segundo_sitio();

		switch_to_blog( $sitio2 );

		$fn = $this->interceptar();
		wp_mail( 'desde-dos@ejemplo.test', 'Desde el sitio 2', 'x' );
		remove_filter( 'pre_wp_mail', $fn, 10 );

		restore_current_blog();

		$fila = diluxone_mail_log_query( array( 'emails' => array( 'desde-dos@ejemplo.test' ), 'site_id' => null ) )['rows'][0];

		$this->assertSame( $sitio2, (int) $fila['site_id'] );
		// Desde el sitio principal, filtrando por sitio, no se ve.
		$this->assertSame( 0, diluxone_mail_log_query( array( 'emails' => array( 'desde-dos@ejemplo.test' ) ) )['total'] );
	}

	public function test_la_ficha_de_una_persona_ve_todos_los_sitios(): void {
		$id   = $this->alguien();
		$user = get_user_by( 'id', $id );

		$fn = $this->interceptar();
		wp_mail( $user->user_email, 'Sitio 1', 'x' );

		switch_to_blog( $this->segundo_sitio() );
		wp_mail( $user->user_email, 'Sitio 2', 'x' );
		restore_current_blog();

		remove_filter( 'pre_wp_mail', $fn, 10 );

		$this->assertSame( 2, diluxone_mail_log_count( diluxone_mail_user_emails( $user ) ) );
	}

	public function test_la_red_manda_y_el_sitio_solo_pisa_con_permiso(): void {
		update_site_option( 'diluxone_mail_host', 'smtp.red.test' );
		update_option( 'diluxone_mail_host', 'smtp.sitio.test' );

		$this->assertSame( 'network', diluxone_mail_config_value( 'host' )['source'] );
		$this->assertSame( 'smtp.red.test', diluxone_mail_config_value( 'host' )['value'] );

		update_site_option( 'diluxone_mail_network_allow_override', 1 );

		$this->assertSame( 'site', diluxone_mail_config_value( 'host' )['source'] );
		$this->assertSame( 'smtp.sitio.test', diluxone_mail_config_value( 'host' )['value'] );
	}

	public function test_guardar_en_un_sitio_sin_permiso_no_hace_nada(): void {
		diluxone_mail_save_options( array( 'diluxone_mail_host' => 'smtp.intento.test' ), 'site' );

		$this->assertFalse( get_option( 'diluxone_mail_host' ) );
	}

	public function test_la_version_del_esquema_vive_en_la_red(): void {
		$this->assertSame( DILUXONE_MAIL_DB_VERSION, (int) get_site_option( 'diluxone_mail_db_version' ) );
	}
}
