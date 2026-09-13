<?php
/**
 * Base for the DNS diagnosis tests.
 *
 * A unit test does not query the DNS: it seeds it. The seam is the lookup
 * cache — every answer lives in a site transient under a known key — so a test
 * writes there what the "DNS" is meant to answer and then calls the real
 * analysis. What is tested is the parsing and the logic, which is what can be
 * wrong; the lookup itself is exercised by the E2E diagnosis against real
 * domains.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/options.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/providers.php';
require_once __DIR__ . '/../../../includes/dns.php';
require_once __DIR__ . '/../../../includes/dns-spf.php';
require_once __DIR__ . '/../../../includes/dns-dkim.php';
require_once __DIR__ . '/../../../includes/dns-dmarc.php';

abstract class DnsTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_options']         = array();
		$GLOBALS['_test_wp_site_options']    = array();
		$GLOBALS['_test_wp_site_transients'] = array();
		$GLOBALS['_test_multisite']          = false;
		$GLOBALS['wp_filter']                = array();

		// Anything not seeded must not reach the real DNS: the resolver is DoH
		// and the stubs' wp_remote_get() does not answer.
		\update_option( 'diluxone_mail_dns_resolver', 'doh' );
	}

	/** Rebuilds a domain's report without discarding the seeded answers. */
	protected function diagnose( string $domain ): array {
		\delete_site_transient( 'diluxone_mail_diagnosis_' . md5( $domain ) );

		return \diluxone_mail_diagnose( $domain );
	}

	/**
	 * Seeds the DNS answer for a name and a type.
	 *
	 * @param array<int, string> $records
	 */
	protected function dns( string $name, string $type, array $records ): void {
		\set_site_transient(
			'diluxone_mail_dns_' . md5( strtoupper( $type ) . '|' . strtolower( rtrim( $name, '.' ) ) ),
			array( 'records' => $records )
		);
	}

	/** A name with no records: NXDOMAIN. */
	protected function nothing( string $name, string $type ): void {
		$this->dns( $name, $type, array() );
	}
}
