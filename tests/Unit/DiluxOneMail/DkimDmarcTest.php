<?php
/**
 * DKIM y DMARC: lo que se parsea y lo que se concluye.
 */

namespace Tests\Unit\DiluxOneMail;

class DkimDmarcTest extends DnsTestCase {

	/** Una clave RSA de 2048 bits en DER ocupa ~294 bytes. */
	private function clave( int $bytes ): string {
		return base64_encode( str_repeat( 'x', $bytes ) );
	}

	public function test_los_selectores_del_proveedor_se_suman_a_los_conocidos(): void {
		$selectores = \diluxone_mail_dkim_selectors( 'm365' );

		$this->assertContains( 'selector1', $selectores );
		$this->assertContains( 'google', $selectores );
		$this->assertSame( $selectores, array_unique( $selectores ) );
	}

	public function test_los_selectores_del_usuario_tambien(): void {
		\update_option( 'diluxone_mail_dns_selectors', array( 'miselector' ) );

		$this->assertContains( 'miselector', \diluxone_mail_dkim_selectors( 'custom' ) );
	}

	public function test_un_selector_publicado_se_encuentra_con_su_tamano(): void {
		$this->dns( 'mailjet._domainkey.ejemplo.test', 'TXT', array( 'k=rsa; p=' . $this->clave( 294 ) ) );
		$this->nothing( 'mailjet._domainkey.ejemplo.test', 'CNAME' );
		$this->nothing( 'nada._domainkey.ejemplo.test', 'TXT' );
		$this->nothing( 'nada._domainkey.ejemplo.test', 'CNAME' );

		$r = \diluxone_mail_dkim_probe( 'ejemplo.test', array( 'mailjet', 'nada' ) );

		$this->assertTrue( $r[0]['found'] );
		$this->assertSame( 2048, $r[0]['bits'] );
		$this->assertSame( 'txt', $r[0]['via'] );
		$this->assertFalse( $r[1]['found'] );
	}

	public function test_un_selector_delegado_por_cname(): void {
		$this->dns( 's1._domainkey.ejemplo.test', 'CNAME', array( 's1.domainkey.u123.wl.sendgrid.test' ) );
		$this->dns( 's1._domainkey.ejemplo.test', 'TXT', array( 'k=rsa; t=s; p=' . $this->clave( 162 ) ) );

		$r = \diluxone_mail_dkim_probe( 'ejemplo.test', array( 's1' ) );

		$this->assertSame( 'cname', $r[0]['via'] );
		$this->assertSame( 1024, $r[0]['bits'] );
	}

	public function test_una_clave_vacia_es_una_clave_revocada(): void {
		$this->dns( 'viejo._domainkey.ejemplo.test', 'TXT', array( 'v=DKIM1; k=rsa; p=' ) );
		$this->nothing( 'viejo._domainkey.ejemplo.test', 'CNAME' );

		$r = \diluxone_mail_dkim_probe( 'ejemplo.test', array( 'viejo' ) );

		$this->assertTrue( $r[0]['found'] );
		$this->assertTrue( $r[0]['revoked'] );
	}

	public function test_dmarc_sin_registro(): void {
		$this->nothing( '_dmarc.ejemplo.test', 'TXT' );

		$this->assertNull( \diluxone_mail_dmarc_analyse( 'ejemplo.test' )['record'] );
	}

	public function test_dmarc_se_parsea_entero(): void {
		$this->dns( '_dmarc.ejemplo.test', 'TXT', array( 'v=DMARC1; p=quarantine; sp=none; pct=50; adkim=s; rua=mailto:rua@ejemplo.test,mailto:dmarc@otro.test!10m; ruf=mailto:ruf@ejemplo.test' ) );
		$this->nothing( 'ejemplo.test._report._dmarc.otro.test', 'TXT' );

		$r = \diluxone_mail_dmarc_analyse( 'ejemplo.test' );

		$this->assertSame( 'quarantine', $r['policy'] );
		$this->assertSame( 'none', $r['subdomain_policy'] );
		$this->assertSame( 50, $r['pct'] );
		$this->assertSame( 's', $r['adkim'] );
		$this->assertSame( 'r', $r['aspf'] );
		$this->assertSame( array( 'rua@ejemplo.test', 'dmarc@otro.test' ), $r['rua'] );
		$this->assertSame( array( 'ruf@ejemplo.test' ), $r['ruf'] );
	}

	/**
	 * El caso real de conosur.tech: los reportes van a otro dominio que no
	 * publicó la autorización, y se descartan.
	 */
	public function test_los_reportes_a_otro_dominio_necesitan_autorizacion(): void {
		$this->dns( '_dmarc.ejemplo.test', 'TXT', array( 'v=DMARC1; p=none; rua=mailto:a@autorizado.test,mailto:b@noautorizado.test' ) );
		$this->dns( 'ejemplo.test._report._dmarc.autorizado.test', 'TXT', array( 'v=DMARC1' ) );
		$this->nothing( 'ejemplo.test._report._dmarc.noautorizado.test', 'TXT' );

		$r = \diluxone_mail_dmarc_analyse( 'ejemplo.test' );

		$this->assertCount( 2, $r['external'] );
		$this->assertTrue( $r['external'][0]['authorized'] );
		$this->assertFalse( $r['external'][1]['authorized'] );
	}

	public function test_los_reportes_al_mismo_dominio_no_necesitan_nada(): void {
		$this->dns( '_dmarc.ejemplo.test', 'TXT', array( 'v=DMARC1; p=reject; rua=mailto:rua@ejemplo.test,mailto:rua@sub.ejemplo.test' ) );

		$this->assertSame( array(), \diluxone_mail_dmarc_analyse( 'ejemplo.test' )['external'] );
	}

	public function test_dmarc_sin_politica_es_invalido(): void {
		$this->dns( '_dmarc.ejemplo.test', 'TXT', array( 'v=DMARC1; rua=mailto:x@ejemplo.test' ) );

		$this->assertNotSame( array(), \diluxone_mail_dmarc_analyse( 'ejemplo.test' )['errors'] );
	}
}
