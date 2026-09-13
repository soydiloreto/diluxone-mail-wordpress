<?php
/**
 * The report in prose: what it concludes from what the DNS says.
 *
 * Each test seeds a DNS and a transport, and looks at which findings come
 * out. What is tested is the judgement — when something is a problem, when it
 * is a warning — because that is what a user reads.
 */

namespace Tests\Unit\DiluxOneMail;

require_once __DIR__ . '/../../../includes/observer.php';
require_once __DIR__ . '/../../../includes/diagnostics.php';

class DiagnosticsTest extends DnsTestCase {

	protected function setUp(): void {
		parent::setUp();

		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );
	}

	/** The levels of a section's findings, to assert on. */
	private function levels( array $report, string $section ): array {
		$out = array();

		foreach ( $report['findings'] as $f ) {
			if ( $f['section'] === $section ) {
				$out[ $f['level'] ] = ( $out[ $f['level'] ] ?? 0 ) + 1;
			}
		}

		return $out;
	}

	private function has( array $report, string $level, string $needle ): bool {
		foreach ( $report['findings'] as $f ) {
			if ( $f['level'] === $level && false !== stripos( $f['title'], $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/** A healthy domain on Mailjet: SPF, DKIM and an aligned DMARC. */
	private function sano(): void {
		$this->dns( 'healthy.test', 'TXT', array( 'v=spf1 include:spf.mailjet.com -all' ) );
		$this->dns( 'spf.mailjet.com', 'TXT', array( 'v=spf1 ip4:87.253.232.0/21 ~all' ) );
		$this->dns( 'mailjet._domainkey.healthy.test', 'TXT', array( 'k=rsa; p=' . base64_encode( str_repeat( 'x', 294 ) ) ) );
		$this->nothing( 'mailjet._domainkey.healthy.test', 'CNAME' );
		$this->dns( '_dmarc.healthy.test', 'TXT', array( 'v=DMARC1; p=reject; rua=mailto:rua@healthy.test' ) );
		$this->dns( 'healthy.test', 'MX', array( '10 mx.healthy.test' ) );
	}

	public function test_a_healthy_domain_has_no_problems(): void {
		$this->sano();

		$r = \diluxone_mail_diagnose( 'healthy.test' );

		$this->assertSame( 'mailjet', $r['provider'] );
		$this->assertArrayNotHasKey( 'error', $this->levels( $r, 'spf' ) );
		$this->assertArrayNotHasKey( 'error', $this->levels( $r, 'dkim' ) );
		$this->assertTrue( $this->has( $r, 'ok', 'aligns through DKIM' ) );
		$this->assertTrue( $r['spf_senders'][0]['active'] );
	}

	public function test_the_report_is_cached_and_fresh_rebuilds_it(): void {
		$this->sano();

		$a = \diluxone_mail_diagnose( 'healthy.test' );
		$b = \diluxone_mail_diagnose( 'healthy.test' );
		$c = $this->diagnose( 'healthy.test' );

		$this->assertFalse( $a['cached'] );
		$this->assertTrue( $b['cached'] );
		$this->assertFalse( $c['cached'] );
	}

	public function test_no_spf_is_a_problem(): void {
		$this->nothing( 'empty.test', 'TXT' );
		$this->nothing( '_dmarc.empty.test', 'TXT' );

		$r = \diluxone_mail_diagnose( 'empty.test' );

		$this->assertTrue( $this->has( $r, 'error', 'no SPF record' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'no DMARC record' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'No DKIM selector' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'no MX record' ) );
	}

	public function test_going_over_ten_lookups_is_a_problem(): void {
		$incl = array();
		for ( $i = 1; $i <= 11; $i++ ) {
			$incl[] = "include:p{$i}.test";
			$this->dns( "p{$i}.test", 'TXT', array( "v=spf1 ip4:10.0.0.{$i} ~all" ) );
		}
		$this->dns( 'many.test', 'TXT', array( 'v=spf1 ' . implode( ' ', $incl ) . ' -all' ) );

		$r = \diluxone_mail_diagnose( 'many.test' );

		$this->assertTrue( $this->has( $r, 'error', 'the limit is 10' ) );
	}

	public function test_close_to_the_limit_is_a_warning(): void {
		$incl = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$incl[] = "include:p{$i}.test";
			$this->dns( "p{$i}.test", 'TXT', array( "v=spf1 ip4:10.0.0.{$i} ~all" ) );
		}
		$this->dns( 'ocho.test', 'TXT', array( 'v=spf1 ' . implode( ' ', $incl ) . ' -all' ) );

		$this->assertTrue( $this->has( \diluxone_mail_diagnose( 'ocho.test' ), 'warning', 'of the 10 allowed' ) );
	}

	public function test_plus_all_and_no_all(): void {
		$this->dns( 'abierto.test', 'TXT', array( 'v=spf1 ip4:1.1.1.1 +all' ) );
		$this->dns( 'sinall.test', 'TXT', array( 'v=spf1 ip4:1.1.1.1' ) );

		$this->assertTrue( $this->has( \diluxone_mail_diagnose( 'abierto.test' ), 'error', '+all' ) );
		$this->assertTrue( $this->has( \diluxone_mail_diagnose( 'sinall.test' ), 'warning', 'does not end with' ) );
	}

	public function test_the_active_provider_has_to_be_in_the_spf(): void {
		$this->dns( 'nomj.test', 'TXT', array( 'v=spf1 include:sendgrid.net -all' ) );
		$this->dns( 'sendgrid.net', 'TXT', array( 'v=spf1 ip4:1.1.1.1 ~all' ) );

		$r = \diluxone_mail_diagnose( 'nomj.test' );

		$this->assertTrue( $this->has( $r, 'error', 'Mailjet is not authorised' ) );
		// And SendGrid shows up as a candidate for deletion.
		$this->assertTrue( $this->has( $r, 'warning', 'authorises SendGrid' ) );
		$this->assertSame( 'sendgrid', $r['spf_senders'][0]['provider'] );
		$this->assertFalse( $r['spf_senders'][0]['active'] );
	}

	public function test_without_the_providers_dkim_a_strict_policy_does_not_align(): void {
		$this->dns( 'noalign.test', 'TXT', array( 'v=spf1 include:spf.mailjet.com -all' ) );
		$this->dns( 'spf.mailjet.com', 'TXT', array( 'v=spf1 ip4:87.253.232.0/21 ~all' ) );
		$this->dns( '_dmarc.noalign.test', 'TXT', array( 'v=DMARC1; p=quarantine; rua=mailto:rua@noalign.test' ) );

		$r = \diluxone_mail_diagnose( 'noalign.test' );

		$this->assertTrue( $this->has( $r, 'error', 'No DKIM record for Mailjet' ) );
		$this->assertTrue( $this->has( $r, 'error', 'nothing aligns' ) );
	}

	public function test_dmarc_none_is_only_a_note_and_so_is_a_low_pct(): void {
		$this->sano();
		$this->dns( '_dmarc.healthy.test', 'TXT', array( 'v=DMARC1; p=none; pct=50' ) );

		$r = $this->diagnose( 'healthy.test' );

		$this->assertTrue( $this->has( $r, 'info', 'monitoring only' ) );
		$this->assertTrue( $this->has( $r, 'info', '50%' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'no report address' ) );
	}

	public function test_reports_to_a_domain_that_never_authorised_them(): void {
		$this->sano();
		$this->dns( '_dmarc.healthy.test', 'TXT', array( 'v=DMARC1; p=reject; rua=mailto:x@other.test' ) );
		$this->nothing( 'healthy.test._report._dmarc.other.test', 'TXT' );

		$this->assertTrue( $this->has( $this->diagnose( 'healthy.test' ), 'error', 'being discarded' ) );
	}

	public function test_a_revoked_dkim_and_a_1024_bit_one(): void {
		$this->sano();
		$this->dns( 'k1._domainkey.healthy.test', 'TXT', array( 'v=DKIM1; p=' ) );
		$this->dns( 'mail._domainkey.healthy.test', 'TXT', array( 'k=rsa; p=' . base64_encode( str_repeat( 'x', 162 ) ) ) );

		$r = $this->diagnose( 'healthy.test' );

		$this->assertTrue( $this->has( $r, 'warning', 'is revoked' ) );
		$this->assertTrue( $this->has( $r, 'warning', '1024-bit' ) );
	}

	public function test_postmarks_return_path(): void {
		\update_option( 'diluxone_mail_provider', 'postmark' );
		$this->dns( 'pm.test', 'TXT', array( 'v=spf1 include:spf.mtasv.net -all' ) );
		$this->dns( 'spf.mtasv.net', 'TXT', array( 'v=spf1 ip4:1.1.1.1 ~all' ) );
		$this->nothing( 'pm-bounces.pm.test', 'CNAME' );

		$r = \diluxone_mail_diagnose( 'pm.test' );

		$this->assertSame( 'pm-bounces.pm.test', $r['return_path']['host'] );
		$this->assertTrue( $this->has( $r, 'warning', 'does not resolve' ) );

		$this->dns( 'pm-bounces.pm.test', 'CNAME', array( 'pm.mtasv.net' ) );

		$this->assertTrue( $this->has( $this->diagnose( 'pm.test' ), 'ok', 'points to' ) );
	}

	public function test_with_no_transport_the_provider_is_not_judged(): void {
		\update_option( 'diluxone_mail_mode', 'observe' );
		$this->dns( 'obs.test', 'TXT', array( 'v=spf1 include:sendgrid.net -all' ) );
		$this->dns( 'sendgrid.net', 'TXT', array( 'v=spf1 ip4:1.1.1.1 ~all' ) );
		$this->dns( '_dmarc.obs.test', 'TXT', array( 'v=DMARC1; p=reject; rua=mailto:r@obs.test' ) );

		$r = \diluxone_mail_diagnose( 'obs.test' );

		$this->assertSame( '', $r['provider'] );
		$this->assertFalse( $this->has( $r, 'error', 'not authorised' ) );
		$this->assertTrue( $this->has( $r, 'info', 'alignment is required' ) );
	}
}
