<?php
/**
 * The SPF lookup count: the rule that breaks half the world's mail.
 */

namespace Tests\Unit\DiluxOneMail;

class SpfTest extends DnsTestCase {

	public function test_no_record(): void {
		$this->nothing( 'example.test', 'TXT' );

		$r = \diluxone_mail_spf_analyse( 'example.test' );

		$this->assertNull( $r['record'] );
		$this->assertSame( 0, $r['lookups'] );
	}

	public function test_ip4_ip6_and_all_do_not_count(): void {
		$this->dns( 'example.test', 'TXT', array( 'v=spf1 ip4:1.2.3.0/24 ip6:2001:db8::/32 -all' ) );

		$r = \diluxone_mail_spf_analyse( 'example.test' );

		$this->assertSame( 0, $r['lookups'] );
		$this->assertSame( '-all', $r['all'] );
		$this->assertFalse( $r['over_limit'] );
	}

	public function test_a_mx_ptr_exists_include_and_redirect_do_count(): void {
		$this->dns( 'example.test', 'TXT', array( 'v=spf1 a mx ptr exists:%{i}.x.test include:uno.test redirect=dos.test' ) );
		$this->dns( 'uno.test', 'TXT', array( 'v=spf1 ip4:1.1.1.1 ~all' ) );
		$this->dns( 'dos.test', 'TXT', array( 'v=spf1 ip4:2.2.2.2 ~all' ) );

		$r = \diluxone_mail_spf_analyse( 'example.test' );

		$this->assertSame( 6, $r['lookups'] );
	}

	/**
	 * The real conosur.tech case, with the includes expanded.
	 */
	public function test_includes_are_expanded_recursively(): void {
		$this->dns( 'conosur.test', 'TXT', array( 'v=spf1 a mx include:_spf.mailersend.test include:spf.protection.outlook.test include:spf.mailjet.test include:x.netcorecloud.test -all' ) );
		$this->dns( '_spf.mailersend.test', 'TXT', array( 'v=spf1 ip4:212.11.79.0/24 ~all' ) );
		$this->dns( 'spf.protection.outlook.test', 'TXT', array( 'v=spf1 ip4:40.92.0.0/15 -all' ) );
		$this->dns( 'spf.mailjet.test', 'TXT', array( 'v=spf1 ip4:87.253.232.0/21 ~all' ) );
		$this->dns( 'x.netcorecloud.test', 'TXT', array( 'v=spf1 include:y.netcorecloud.test ~all' ) );
		$this->dns( 'y.netcorecloud.test', 'TXT', array( 'v=spf1 ip4:3.3.3.3 ~all' ) );

		$r = \diluxone_mail_spf_analyse( 'conosur.test' );

		// a + mx + 4 includes = 6 of its own, plus the nested include = 7.
		$this->assertSame( 7, $r['lookups'] );
		$this->assertCount( 6, $r['tree'] );
		$this->assertSame( array( '_spf.mailersend.test', 'spf.protection.outlook.test', 'spf.mailjet.test', 'x.netcorecloud.test' ), $r['includes'] );
	}

	public function test_going_over_ten_invalidates_the_record(): void {
		$included = array();

		for ( $i = 1; $i <= 11; $i++ ) {
			$included[] = "include:p{$i}.test";
			$this->dns( "p{$i}.test", 'TXT', array( 'v=spf1 ip4:10.0.0.' . $i . ' ~all' ) );
		}

		$this->dns( 'example.test', 'TXT', array( 'v=spf1 ' . implode( ' ', $included ) . ' -all' ) );

		$r = \diluxone_mail_spf_analyse( 'example.test' );

		$this->assertSame( 11, $r['lookups'] );
		$this->assertTrue( $r['over_limit'] );
	}

	public function test_a_loop_does_not_hang_the_analysis(): void {
		$this->dns( 'a.test', 'TXT', array( 'v=spf1 include:b.test -all' ) );
		$this->dns( 'b.test', 'TXT', array( 'v=spf1 include:a.test -all' ) );

		$r = \diluxone_mail_spf_analyse( 'a.test' );

		$this->assertSame( 2, $r['lookups'] );
		$this->assertStringContainsString( 'loop', $r['tree'][2]['error'] );
	}

	public function test_two_spf_records_is_an_error(): void {
		$this->dns( 'example.test', 'TXT', array( 'v=spf1 -all', 'v=spf1 ip4:1.1.1.1 -all' ) );

		$r = \diluxone_mail_spf_analyse( 'example.test' );

		$this->assertNotSame( '', $r['error'] );
	}

	public function test_a_txt_that_is_not_spf_is_ignored(): void {
		$this->dns( 'example.test', 'TXT', array( 'google-site-verification=abc', 'v=spf1 include:_spf.google.test ~all' ) );
		$this->dns( '_spf.google.test', 'TXT', array( 'v=spf1 ip4:74.125.0.0/16 ~all' ) );

		$r = \diluxone_mail_spf_analyse( 'example.test' );

		$this->assertSame( 'v=spf1 include:_spf.google.test ~all', $r['record'] );
		$this->assertSame( 1, $r['lookups'] );
	}
}
