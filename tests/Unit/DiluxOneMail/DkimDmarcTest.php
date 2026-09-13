<?php
/**
 * DKIM and DMARC: what is parsed and what is concluded.
 */

namespace Tests\Unit\DiluxOneMail;

class DkimDmarcTest extends DnsTestCase {

	/** A 2048-bit RSA key in DER takes about 294 bytes. */
	private function key( int $bytes ): string {
		return base64_encode( str_repeat( 'x', $bytes ) );
	}

	public function test_the_providers_selectors_are_added_to_the_known_ones(): void {
		$selectors = \diluxone_mail_dkim_selectors( 'm365' );

		$this->assertContains( 'selector1', $selectors );
		$this->assertContains( 'google', $selectors );
		$this->assertSame( $selectors, array_unique( $selectors ) );
	}

	public function test_the_users_selectors_too(): void {
		\update_option( 'diluxone_mail_dns_selectors', array( 'miselector' ) );

		$this->assertContains( 'miselector', \diluxone_mail_dkim_selectors( 'custom' ) );
	}

	public function test_a_published_selector_is_found_with_its_key_size(): void {
		$this->dns( 'mailjet._domainkey.example.test', 'TXT', array( 'k=rsa; p=' . $this->key( 294 ) ) );
		$this->nothing( 'mailjet._domainkey.example.test', 'CNAME' );
		$this->nothing( 'nada._domainkey.example.test', 'TXT' );
		$this->nothing( 'nada._domainkey.example.test', 'CNAME' );

		$r = \diluxone_mail_dkim_probe( 'example.test', array( 'mailjet', 'nothing' ) );

		$this->assertTrue( $r[0]['found'] );
		$this->assertSame( 2048, $r[0]['bits'] );
		$this->assertSame( 'txt', $r[0]['via'] );
		$this->assertFalse( $r[1]['found'] );
	}

	public function test_a_selector_delegated_by_cname(): void {
		$this->dns( 's1._domainkey.example.test', 'CNAME', array( 's1.domainkey.u123.wl.sendgrid.test' ) );
		$this->dns( 's1._domainkey.example.test', 'TXT', array( 'k=rsa; t=s; p=' . $this->key( 162 ) ) );

		$r = \diluxone_mail_dkim_probe( 'example.test', array( 's1' ) );

		$this->assertSame( 'cname', $r[0]['via'] );
		$this->assertSame( 1024, $r[0]['bits'] );
	}

	public function test_an_empty_key_is_a_revoked_key(): void {
		$this->dns( 'old._domainkey.example.test', 'TXT', array( 'v=DKIM1; k=rsa; p=' ) );
		$this->nothing( 'old._domainkey.example.test', 'CNAME' );

		$r = \diluxone_mail_dkim_probe( 'example.test', array( 'old' ) );

		$this->assertTrue( $r[0]['found'] );
		$this->assertTrue( $r[0]['revoked'] );
	}

	public function test_dmarc_with_no_record(): void {
		$this->nothing( '_dmarc.example.test', 'TXT' );

		$this->assertNull( \diluxone_mail_dmarc_analyse( 'example.test' )['record'] );
	}

	public function test_dmarc_is_parsed_in_full(): void {
		$this->dns( '_dmarc.example.test', 'TXT', array( 'v=DMARC1; p=quarantine; sp=none; pct=50; adkim=s; rua=mailto:rua@example.test,mailto:dmarc@other.test!10m; ruf=mailto:ruf@example.test' ) );
		$this->nothing( 'example.test._report._dmarc.other.test', 'TXT' );

		$r = \diluxone_mail_dmarc_analyse( 'example.test' );

		$this->assertSame( 'quarantine', $r['policy'] );
		$this->assertSame( 'none', $r['subdomain_policy'] );
		$this->assertSame( 50, $r['pct'] );
		$this->assertSame( 's', $r['adkim'] );
		$this->assertSame( 'r', $r['aspf'] );
		$this->assertSame( array( 'rua@example.test', 'dmarc@other.test' ), $r['rua'] );
		$this->assertSame( array( 'ruf@example.test' ), $r['ruf'] );
	}

	/**
	 * The real conosur.tech case: the reports go to another domain that never
	 * published the authorisation, and are thrown away.
	 */
	public function test_reports_to_another_domain_need_authorisation(): void {
		$this->dns( '_dmarc.example.test', 'TXT', array( 'v=DMARC1; p=none; rua=mailto:a@autorizado.test,mailto:b@noautorizado.test' ) );
		$this->dns( 'example.test._report._dmarc.autorizado.test', 'TXT', array( 'v=DMARC1' ) );
		$this->nothing( 'example.test._report._dmarc.noautorizado.test', 'TXT' );

		$r = \diluxone_mail_dmarc_analyse( 'example.test' );

		$this->assertCount( 2, $r['external'] );
		$this->assertTrue( $r['external'][0]['authorized'] );
		$this->assertFalse( $r['external'][1]['authorized'] );
	}

	public function test_reports_to_the_same_domain_need_nothing(): void {
		$this->dns( '_dmarc.example.test', 'TXT', array( 'v=DMARC1; p=reject; rua=mailto:rua@example.test,mailto:rua@sub.example.test' ) );

		$this->assertSame( array(), \diluxone_mail_dmarc_analyse( 'example.test' )['external'] );
	}

	public function test_dmarc_without_a_policy_is_invalid(): void {
		$this->dns( '_dmarc.example.test', 'TXT', array( 'v=DMARC1; rua=mailto:x@example.test' ) );

		$this->assertNotSame( array(), \diluxone_mail_dmarc_analyse( 'example.test' )['errors'] );
	}
}
