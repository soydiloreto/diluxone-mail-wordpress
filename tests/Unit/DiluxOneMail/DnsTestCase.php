<?php
/**
 * Base de los tests del diagnóstico de DNS.
 *
 * El DNS no se consulta en un test unitario: se siembra. La costura es el
 * caché de consultas —cada respuesta vive en un site transient con una clave
 * conocida— así que un test escribe ahí lo que el «DNS» tiene que contestar
 * y después llama al análisis de verdad. Lo que se prueba es el parseo y la
 * lógica, que es lo que puede estar mal; la consulta en sí la prueba el
 * diagnóstico contra dominios reales en el E2E.
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
	}

	/**
	 * Siembra la respuesta del DNS para un nombre y un tipo.
	 *
	 * @param array<int, string> $records
	 */
	protected function dns( string $name, string $type, array $records ): void {
		\set_site_transient(
			'diluxone_mail_dns_' . md5( strtoupper( $type ) . '|' . strtolower( rtrim( $name, '.' ) ) ),
			array( 'records' => $records )
		);
	}

	/** Un nombre sin registros: NXDOMAIN. */
	protected function nothing( string $name, string $type ): void {
		$this->dns( $name, $type, array() );
	}
}
