<?php
/**
 * The DNS lookup: the cache, the fallback to DoH, and the domain to diagnose.
 */

namespace Tests\Unit\DiluxOneMail;

class DnsTest extends DnsTestCase {

	/** A DoH answer in Cloudflare/Google's JSON format. */
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

	public function test_a_cached_answer_is_returned_without_a_lookup(): void {
		$this->dns( 'x.test', 'TXT', array( 'v=spf1 -all' ) );

		$r = \diluxone_mail_dns_lookup( 'X.TEST.', 'txt' );

		$this->assertSame( 'cache', $r['source'] );
		$this->assertSame( array( 'v=spf1 -all' ), $r['records'] );
	}

	public function test_doh_returns_txt_joining_the_quoted_chunks(): void {
		$this->doh( 0, array( array( 'type' => 16, 'data' => '"v=spf1 ip4:1.1.1.1" " -all"' ) ) );

		$r = \diluxone_mail_dns_lookup( 'doh.test', 'TXT' );

		$this->assertSame( 'doh', $r['source'] );
		$this->assertSame( array( 'v=spf1 ip4:1.1.1.1 -all' ), $r['records'] );
		// And it was cached.
		$this->assertSame( 'cache', \diluxone_mail_dns_lookup( 'doh.test', 'TXT' )['source'] );
	}

	public function test_doh_cname_and_mx(): void {
		$this->doh( 0, array( array( 'type' => 5, 'data' => 'Target.Example.' ) ) );
		$this->assertSame( array( 'target.example' ), \diluxone_mail_dns_lookup( 'c.test', 'CNAME' )['records'] );

		$this->doh( 0, array( array( 'type' => 15, 'data' => '10 mx.example.' ), array( 'type' => 16, 'data' => '"ignorado"' ) ) );
		$this->assertSame( array( '10 mx.example' ), \diluxone_mail_dns_lookup( 'm.test', 'MX' )['records'] );
	}

	public function test_nxdomain_is_an_empty_valid_answer(): void {
		$this->doh( 3, array() );

		$r = \diluxone_mail_dns_lookup( 'nonexistent.test', 'TXT' );

		$this->assertSame( array(), $r['records'] );
		$this->assertSame( '', $r['error'] );
	}

	public function test_when_nobody_answers_it_says_so(): void {
		$r = \diluxone_mail_dns_lookup( 'nobody.test', 'TXT' );

		$this->assertSame( array(), $r['records'] );
		$this->assertNotSame( '', $r['error'] );
	}

	public function test_an_error_status_from_the_resolver_is_not_cached(): void {
		$this->doh( 2, array() );

		$this->assertNotSame( '', \diluxone_mail_dns_lookup( 'servfail.test', 'TXT' )['error'] );
		$this->assertArrayNotHasKey( 'diluxone_mail_dns_' . md5( 'TXT|servfail.test' ), $GLOBALS['_test_wp_site_transients'] );
	}

	public function test_revalidating_empties_everything_cached(): void {
		$this->doh( 0, array( array( 'type' => 16, 'data' => '"v=spf1 -all"' ) ) );
		\diluxone_mail_dns_lookup( 'a.test', 'TXT' );
		\diluxone_mail_dns_lookup( 'b.test', 'TXT' );

		$this->assertCount( 2, \get_site_option( 'diluxone_mail_dns_cache_keys' ) );

		\diluxone_mail_dns_flush();

		$this->assertFalse( \get_site_option( 'diluxone_mail_dns_cache_keys' ) );
		$this->assertSame( array(), array_filter( $GLOBALS['_test_wp_site_transients'], static fn( $k ) => str_starts_with( $k, 'diluxone_mail_dns_' ), ARRAY_FILTER_USE_KEY ) );
	}

	public function test_the_domain_comes_from_the_setting_the_sender_or_the_site(): void {
		$this->assertSame( (string) parse_url( \home_url(), PHP_URL_HOST ), \diluxone_mail_dns_domain() );

		\update_option( 'diluxone_mail_from', 'hello@mail.test' );
		$this->assertSame( 'mail.test', \diluxone_mail_dns_domain() );

		\update_option( 'diluxone_mail_dns_domain', 'Fijado.TEST' );
		$this->assertSame( 'fijado.test', \diluxone_mail_dns_domain() );
	}

	public function test_with_the_system_resolver_it_does_not_fall_back_to_doh(): void {
		\update_option( 'diluxone_mail_dns_resolver', 'system' );
		$this->doh( 0, array( array( 'type' => 16, 'data' => '"v=spf1 -all"' ) ) );

		// A real dns_get_record() does not resolve .test; what matters is that
		// the DoH answer sitting ready was not used.
		$r = \diluxone_mail_dns_lookup( 'system-only.test', 'TXT' );

		$this->assertNotSame( 'doh', $r['source'] );
		$this->assertSame( array(), $r['records'] );
	}

	public function test_the_known_types(): void {
		$this->assertSame( 16, \diluxone_mail_dns_types()['TXT'] );
		$this->assertIsBool( \diluxone_mail_dns_system_available() );
	}
}
