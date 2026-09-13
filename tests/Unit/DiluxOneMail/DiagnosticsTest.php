<?php
/**
 * El informe en prosa: qué concluye a partir de lo que dice el DNS.
 *
 * Cada test siembra un DNS y un transporte, y mira qué hallazgos salen. Lo
 * que se prueba es el criterio —cuándo es un problema, cuándo un aviso—,
 * que es lo que un usuario lee.
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

	/** Los títulos de los hallazgos de una sección, para afirmar sobre ellos. */
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

	/** Un dominio sano con Mailjet: SPF, DKIM y DMARC alineado. */
	private function sano(): void {
		$this->dns( 'sano.test', 'TXT', array( 'v=spf1 include:spf.mailjet.com -all' ) );
		$this->dns( 'spf.mailjet.com', 'TXT', array( 'v=spf1 ip4:87.253.232.0/21 ~all' ) );
		$this->dns( 'mailjet._domainkey.sano.test', 'TXT', array( 'k=rsa; p=' . base64_encode( str_repeat( 'x', 294 ) ) ) );
		$this->nothing( 'mailjet._domainkey.sano.test', 'CNAME' );
		$this->dns( '_dmarc.sano.test', 'TXT', array( 'v=DMARC1; p=reject; rua=mailto:rua@sano.test' ) );
		$this->dns( 'sano.test', 'MX', array( '10 mx.sano.test' ) );
	}

	public function test_un_dominio_sano_no_tiene_problemas(): void {
		$this->sano();

		$r = \diluxone_mail_diagnose( 'sano.test' );

		$this->assertSame( 'mailjet', $r['provider'] );
		$this->assertArrayNotHasKey( 'error', $this->levels( $r, 'spf' ) );
		$this->assertArrayNotHasKey( 'error', $this->levels( $r, 'dkim' ) );
		$this->assertTrue( $this->has( $r, 'ok', 'aligns through DKIM' ) );
		$this->assertTrue( $r['spf_senders'][0]['active'] );
	}

	public function test_el_informe_se_cachea_y_fresh_lo_rehace(): void {
		$this->sano();

		$a = \diluxone_mail_diagnose( 'sano.test' );
		$b = \diluxone_mail_diagnose( 'sano.test' );
		$c = $this->diagnose( 'sano.test' );

		$this->assertFalse( $a['cached'] );
		$this->assertTrue( $b['cached'] );
		$this->assertFalse( $c['cached'] );
	}

	public function test_sin_spf_es_un_problema(): void {
		$this->nothing( 'vacio.test', 'TXT' );
		$this->nothing( '_dmarc.vacio.test', 'TXT' );

		$r = \diluxone_mail_diagnose( 'vacio.test' );

		$this->assertTrue( $this->has( $r, 'error', 'no SPF record' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'no DMARC record' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'No DKIM selector' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'no MX record' ) );
	}

	public function test_pasarse_de_diez_lookups_es_un_problema(): void {
		$incl = array();
		for ( $i = 1; $i <= 11; $i++ ) {
			$incl[] = "include:p{$i}.test";
			$this->dns( "p{$i}.test", 'TXT', array( "v=spf1 ip4:10.0.0.{$i} ~all" ) );
		}
		$this->dns( 'muchos.test', 'TXT', array( 'v=spf1 ' . implode( ' ', $incl ) . ' -all' ) );

		$r = \diluxone_mail_diagnose( 'muchos.test' );

		$this->assertTrue( $this->has( $r, 'error', 'the limit is 10' ) );
	}

	public function test_cerca_del_limite_es_un_aviso(): void {
		$incl = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$incl[] = "include:p{$i}.test";
			$this->dns( "p{$i}.test", 'TXT', array( "v=spf1 ip4:10.0.0.{$i} ~all" ) );
		}
		$this->dns( 'ocho.test', 'TXT', array( 'v=spf1 ' . implode( ' ', $incl ) . ' -all' ) );

		$this->assertTrue( $this->has( \diluxone_mail_diagnose( 'ocho.test' ), 'warning', 'of the 10 allowed' ) );
	}

	public function test_mas_all_y_sin_all(): void {
		$this->dns( 'abierto.test', 'TXT', array( 'v=spf1 ip4:1.1.1.1 +all' ) );
		$this->dns( 'sinall.test', 'TXT', array( 'v=spf1 ip4:1.1.1.1' ) );

		$this->assertTrue( $this->has( \diluxone_mail_diagnose( 'abierto.test' ), 'error', '+all' ) );
		$this->assertTrue( $this->has( \diluxone_mail_diagnose( 'sinall.test' ), 'warning', 'does not end with' ) );
	}

	public function test_el_proveedor_activo_tiene_que_estar_en_el_spf(): void {
		$this->dns( 'sinmj.test', 'TXT', array( 'v=spf1 include:sendgrid.net -all' ) );
		$this->dns( 'sendgrid.net', 'TXT', array( 'v=spf1 ip4:1.1.1.1 ~all' ) );

		$r = \diluxone_mail_diagnose( 'sinmj.test' );

		$this->assertTrue( $this->has( $r, 'error', 'Mailjet is not authorised' ) );
		// Y SendGrid aparece como candidato a borrar.
		$this->assertTrue( $this->has( $r, 'warning', 'authorises SendGrid' ) );
		$this->assertSame( 'sendgrid', $r['spf_senders'][0]['provider'] );
		$this->assertFalse( $r['spf_senders'][0]['active'] );
	}

	public function test_sin_dkim_del_proveedor_con_politica_estricta_no_alinea(): void {
		$this->dns( 'noalinea.test', 'TXT', array( 'v=spf1 include:spf.mailjet.com -all' ) );
		$this->dns( 'spf.mailjet.com', 'TXT', array( 'v=spf1 ip4:87.253.232.0/21 ~all' ) );
		$this->dns( '_dmarc.noalinea.test', 'TXT', array( 'v=DMARC1; p=quarantine; rua=mailto:rua@noalinea.test' ) );

		$r = \diluxone_mail_diagnose( 'noalinea.test' );

		$this->assertTrue( $this->has( $r, 'error', 'No DKIM record for Mailjet' ) );
		$this->assertTrue( $this->has( $r, 'error', 'nothing aligns' ) );
	}

	public function test_dmarc_none_es_solo_una_nota_y_pct_bajo_tambien(): void {
		$this->sano();
		$this->dns( '_dmarc.sano.test', 'TXT', array( 'v=DMARC1; p=none; pct=50' ) );

		$r = $this->diagnose( 'sano.test' );

		$this->assertTrue( $this->has( $r, 'info', 'monitoring only' ) );
		$this->assertTrue( $this->has( $r, 'info', '50%' ) );
		$this->assertTrue( $this->has( $r, 'warning', 'no report address' ) );
	}

	public function test_reportes_a_un_dominio_que_no_autorizo(): void {
		$this->sano();
		$this->dns( '_dmarc.sano.test', 'TXT', array( 'v=DMARC1; p=reject; rua=mailto:x@otro.test' ) );
		$this->nothing( 'sano.test._report._dmarc.otro.test', 'TXT' );

		$this->assertTrue( $this->has( $this->diagnose( 'sano.test' ), 'error', 'being discarded' ) );
	}

	public function test_dkim_revocado_y_de_1024(): void {
		$this->sano();
		$this->dns( 'k1._domainkey.sano.test', 'TXT', array( 'v=DKIM1; p=' ) );
		$this->dns( 'mail._domainkey.sano.test', 'TXT', array( 'k=rsa; p=' . base64_encode( str_repeat( 'x', 162 ) ) ) );

		$r = $this->diagnose( 'sano.test' );

		$this->assertTrue( $this->has( $r, 'warning', 'is revoked' ) );
		$this->assertTrue( $this->has( $r, 'warning', '1024-bit' ) );
	}

	public function test_return_path_de_postmark(): void {
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

	public function test_sin_transporte_no_se_juzga_al_proveedor(): void {
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
