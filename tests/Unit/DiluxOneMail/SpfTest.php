<?php
/**
 * El conteo de lookups de SPF: la regla que rompe el correo de medio mundo.
 */

namespace Tests\Unit\DiluxOneMail;

class SpfTest extends DnsTestCase {

	public function test_sin_registro(): void {
		$this->nothing( 'ejemplo.test', 'TXT' );

		$r = \diluxone_mail_spf_analyse( 'ejemplo.test' );

		$this->assertNull( $r['record'] );
		$this->assertSame( 0, $r['lookups'] );
	}

	public function test_ip4_ip6_y_all_no_cuentan(): void {
		$this->dns( 'ejemplo.test', 'TXT', array( 'v=spf1 ip4:1.2.3.0/24 ip6:2001:db8::/32 -all' ) );

		$r = \diluxone_mail_spf_analyse( 'ejemplo.test' );

		$this->assertSame( 0, $r['lookups'] );
		$this->assertSame( '-all', $r['all'] );
		$this->assertFalse( $r['over_limit'] );
	}

	public function test_a_mx_ptr_exists_include_y_redirect_cuentan(): void {
		$this->dns( 'ejemplo.test', 'TXT', array( 'v=spf1 a mx ptr exists:%{i}.x.test include:uno.test redirect=dos.test' ) );
		$this->dns( 'uno.test', 'TXT', array( 'v=spf1 ip4:1.1.1.1 ~all' ) );
		$this->dns( 'dos.test', 'TXT', array( 'v=spf1 ip4:2.2.2.2 ~all' ) );

		$r = \diluxone_mail_spf_analyse( 'ejemplo.test' );

		$this->assertSame( 6, $r['lookups'] );
	}

	/**
	 * El caso real de conosur.tech, con los includes expandidos.
	 */
	public function test_los_includes_se_expanden_recursivamente(): void {
		$this->dns( 'conosur.test', 'TXT', array( 'v=spf1 a mx include:_spf.mailersend.test include:spf.protection.outlook.test include:spf.mailjet.test include:x.netcorecloud.test -all' ) );
		$this->dns( '_spf.mailersend.test', 'TXT', array( 'v=spf1 ip4:212.11.79.0/24 ~all' ) );
		$this->dns( 'spf.protection.outlook.test', 'TXT', array( 'v=spf1 ip4:40.92.0.0/15 -all' ) );
		$this->dns( 'spf.mailjet.test', 'TXT', array( 'v=spf1 ip4:87.253.232.0/21 ~all' ) );
		$this->dns( 'x.netcorecloud.test', 'TXT', array( 'v=spf1 include:y.netcorecloud.test ~all' ) );
		$this->dns( 'y.netcorecloud.test', 'TXT', array( 'v=spf1 ip4:3.3.3.3 ~all' ) );

		$r = \diluxone_mail_spf_analyse( 'conosur.test' );

		// a + mx + 4 includes = 6 propios, más el include anidado = 7.
		$this->assertSame( 7, $r['lookups'] );
		$this->assertCount( 6, $r['tree'] );
		$this->assertSame( array( '_spf.mailersend.test', 'spf.protection.outlook.test', 'spf.mailjet.test', 'x.netcorecloud.test' ), $r['includes'] );
	}

	public function test_pasarse_de_diez_invalida_el_registro(): void {
		$incluidos = array();

		for ( $i = 1; $i <= 11; $i++ ) {
			$incluidos[] = "include:p{$i}.test";
			$this->dns( "p{$i}.test", 'TXT', array( 'v=spf1 ip4:10.0.0.' . $i . ' ~all' ) );
		}

		$this->dns( 'ejemplo.test', 'TXT', array( 'v=spf1 ' . implode( ' ', $incluidos ) . ' -all' ) );

		$r = \diluxone_mail_spf_analyse( 'ejemplo.test' );

		$this->assertSame( 11, $r['lookups'] );
		$this->assertTrue( $r['over_limit'] );
	}

	public function test_un_bucle_no_cuelga_el_analisis(): void {
		$this->dns( 'a.test', 'TXT', array( 'v=spf1 include:b.test -all' ) );
		$this->dns( 'b.test', 'TXT', array( 'v=spf1 include:a.test -all' ) );

		$r = \diluxone_mail_spf_analyse( 'a.test' );

		$this->assertSame( 2, $r['lookups'] );
		$this->assertStringContainsString( 'loop', $r['tree'][2]['error'] );
	}

	public function test_dos_registros_spf_es_un_error(): void {
		$this->dns( 'ejemplo.test', 'TXT', array( 'v=spf1 -all', 'v=spf1 ip4:1.1.1.1 -all' ) );

		$r = \diluxone_mail_spf_analyse( 'ejemplo.test' );

		$this->assertNotSame( '', $r['error'] );
	}

	public function test_un_txt_que_no_es_spf_se_ignora(): void {
		$this->dns( 'ejemplo.test', 'TXT', array( 'google-site-verification=abc', 'v=spf1 include:_spf.google.test ~all' ) );
		$this->dns( '_spf.google.test', 'TXT', array( 'v=spf1 ip4:74.125.0.0/16 ~all' ) );

		$r = \diluxone_mail_spf_analyse( 'ejemplo.test' );

		$this->assertSame( 'v=spf1 include:_spf.google.test ~all', $r['record'] );
		$this->assertSame( 1, $r['lookups'] );
	}
}
