<?php
/**
 * Cómo se leen los destinatarios de un wp_mail(): la clave del índice.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/log-hooks.php';

class AddressesTest extends TestCase {

	public function test_una_cadena_con_comas(): void {
		$this->assertSame( array( 'a@x.test', 'b@x.test' ), \diluxone_mail_parse_addresses( 'a@x.test, b@x.test' ) );
	}

	public function test_una_lista(): void {
		$this->assertSame( array( 'a@x.test', 'b@x.test' ), \diluxone_mail_parse_addresses( array( 'a@x.test', 'b@x.test' ) ) );
	}

	public function test_nombre_y_direccion(): void {
		$this->assertSame( array( 'ana@x.test' ), \diluxone_mail_parse_addresses( 'Ana Pérez <ana@x.test>' ) );
	}

	public function test_se_normaliza_a_minusculas_y_sin_repetidas(): void {
		$this->assertSame( array( 'ana@x.test' ), \diluxone_mail_parse_addresses( 'Ana@X.test, ana@x.test' ) );
	}

	public function test_lo_que_no_es_una_direccion_se_descarta(): void {
		$this->assertSame( array( 'ok@x.test' ), \diluxone_mail_parse_addresses( 'no-es-nada, ok@x.test, <>' ) );
	}

	public function test_cc_y_bcc_salen_de_las_cabeceras(): void {
		$destinatarios = \diluxone_mail_recipients(
			array(
				'to'      => 'a@x.test',
				'headers' => "Cc: c@x.test\r\nBcc: Oculto <b@x.test>\r\nContent-Type: text/html",
			)
		);

		$this->assertSame(
			array(
				array( 'email' => 'a@x.test', 'kind' => 'to' ),
				array( 'email' => 'c@x.test', 'kind' => 'cc' ),
				array( 'email' => 'b@x.test', 'kind' => 'bcc' ),
			),
			$destinatarios
		);
	}

	public function test_las_cabeceras_pueden_venir_como_lista_o_como_texto(): void {
		$this->assertSame( array( 'From: a@x.test', 'Cc: b@x.test' ), \diluxone_mail_header_lines( array( 'From: a@x.test', 'Cc: b@x.test' ) ) );
		$this->assertSame( array( 'From: a@x.test', 'Cc: b@x.test' ), \diluxone_mail_header_lines( "From: a@x.test\nCc: b@x.test\n" ) );
		$this->assertSame( array( 'From: a@x.test' ), \diluxone_mail_header_lines( array( 'From' => 'a@x.test' ) ) );
	}

	public function test_el_remitente_y_el_tipo_salen_de_las_cabeceras(): void {
		$meta = \diluxone_mail_header_meta( array( 'headers' => "From: Sitio <hola@x.test>\r\nContent-Type: text/html; charset=UTF-8" ) );

		$this->assertSame( 'hola@x.test', $meta['from'] );
		$this->assertSame( 'text/html', $meta['type'] );
	}

	public function test_sin_cabeceras_el_tipo_es_texto_plano(): void {
		$this->assertSame( 'text/plain', \diluxone_mail_header_meta( array() )['type'] );
	}
}
