<?php
/**
 * La consulta al DNS: el caché, la caída a DoH, y el dominio a diagnosticar.
 */

namespace Tests\Unit\DiluxOneMail;

class DnsTest extends DnsTestCase {

	/** Una respuesta de DoH en el formato JSON de Cloudflare/Google. */
	private function doh( int $status, array $answers ): void {
		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'Status' => $status, 'Answer' => $answers ) ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_remote_get'] );
		parent::tearDown();
	}

	public function test_lo_cacheado_se_devuelve_sin_consultar(): void {
		$this->dns( 'x.test', 'TXT', array( 'v=spf1 -all' ) );

		$r = \diluxone_mail_dns_lookup( 'X.TEST.', 'txt' );

		$this->assertSame( 'cache', $r['source'] );
		$this->assertSame( array( 'v=spf1 -all' ), $r['records'] );
	}

	public function test_doh_devuelve_txt_uniendo_las_partes_entrecomilladas(): void {
		$this->doh( 0, array( array( 'type' => 16, 'data' => '"v=spf1 ip4:1.1.1.1" " -all"' ) ) );

		$r = \diluxone_mail_dns_lookup( 'doh.test', 'TXT' );

		$this->assertSame( 'doh', $r['source'] );
		$this->assertSame( array( 'v=spf1 ip4:1.1.1.1 -all' ), $r['records'] );
		// Y quedó cacheado.
		$this->assertSame( 'cache', \diluxone_mail_dns_lookup( 'doh.test', 'TXT' )['source'] );
	}

	public function test_doh_cname_y_mx(): void {
		$this->doh( 0, array( array( 'type' => 5, 'data' => 'Target.Example.' ) ) );
		$this->assertSame( array( 'target.example' ), \diluxone_mail_dns_lookup( 'c.test', 'CNAME' )['records'] );

		$this->doh( 0, array( array( 'type' => 15, 'data' => '10 mx.example.' ), array( 'type' => 16, 'data' => '"ignorado"' ) ) );
		$this->assertSame( array( '10 mx.example' ), \diluxone_mail_dns_lookup( 'm.test', 'MX' )['records'] );
	}

	public function test_nxdomain_es_una_respuesta_vacia_y_valida(): void {
		$this->doh( 3, array() );

		$r = \diluxone_mail_dns_lookup( 'noexiste.test', 'TXT' );

		$this->assertSame( array(), $r['records'] );
		$this->assertSame( '', $r['error'] );
	}

	public function test_cuando_nadie_contesta_se_dice(): void {
		$r = \diluxone_mail_dns_lookup( 'nadie.test', 'TXT' );

		$this->assertSame( array(), $r['records'] );
		$this->assertNotSame( '', $r['error'] );
	}

	public function test_un_status_de_error_del_resolver_no_se_cachea(): void {
		$this->doh( 2, array() );

		$this->assertNotSame( '', \diluxone_mail_dns_lookup( 'servfail.test', 'TXT' )['error'] );
		$this->assertArrayNotHasKey( 'diluxone_mail_dns_' . md5( 'TXT|servfail.test' ), $GLOBALS['_test_wp_site_transients'] );
	}

	public function test_revalidar_vacia_todo_lo_cacheado(): void {
		$this->doh( 0, array( array( 'type' => 16, 'data' => '"v=spf1 -all"' ) ) );
		\diluxone_mail_dns_lookup( 'a.test', 'TXT' );
		\diluxone_mail_dns_lookup( 'b.test', 'TXT' );

		$this->assertCount( 2, \get_site_option( 'diluxone_mail_dns_cache_keys' ) );

		\diluxone_mail_dns_flush();

		$this->assertFalse( \get_site_option( 'diluxone_mail_dns_cache_keys' ) );
		$this->assertSame( array(), array_filter( $GLOBALS['_test_wp_site_transients'], static fn( $k ) => str_starts_with( $k, 'diluxone_mail_dns_' ), ARRAY_FILTER_USE_KEY ) );
	}

	public function test_el_dominio_sale_del_ajuste_del_remitente_o_del_sitio(): void {
		$this->assertSame( (string) parse_url( \home_url(), PHP_URL_HOST ), \diluxone_mail_dns_domain() );

		\update_option( 'diluxone_mail_from', 'hola@correo.test' );
		$this->assertSame( 'correo.test', \diluxone_mail_dns_domain() );

		\update_option( 'diluxone_mail_dns_domain', 'Fijado.TEST' );
		$this->assertSame( 'fijado.test', \diluxone_mail_dns_domain() );
	}

	public function test_con_resolver_system_no_se_cae_a_doh(): void {
		\update_option( 'diluxone_mail_dns_resolver', 'system' );
		$this->doh( 0, array( array( 'type' => 16, 'data' => '"v=spf1 -all"' ) ) );

		// dns_get_record() de verdad no resuelve .test; lo que importa es que
		// no se haya usado la respuesta de DoH que estaba lista.
		$r = \diluxone_mail_dns_lookup( 'solo-system.test', 'TXT' );

		$this->assertNotSame( 'doh', $r['source'] );
		$this->assertSame( array(), $r['records'] );
	}

	public function test_los_tipos_conocidos(): void {
		$this->assertSame( 16, \diluxone_mail_dns_types()['TXT'] );
		$this->assertIsBool( \diluxone_mail_dns_system_available() );
	}
}
