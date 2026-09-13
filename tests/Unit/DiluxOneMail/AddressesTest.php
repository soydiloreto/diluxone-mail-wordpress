<?php
/**
 * How the recipients of a wp_mail() are read: the key to the index.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/log-hooks.php';

class AddressesTest extends TestCase {

	public function test_a_comma_separated_string(): void {
		$this->assertSame( array( 'a@x.test', 'b@x.test' ), \diluxone_mail_parse_addresses( 'a@x.test, b@x.test' ) );
	}

	public function test_a_list(): void {
		$this->assertSame( array( 'a@x.test', 'b@x.test' ), \diluxone_mail_parse_addresses( array( 'a@x.test', 'b@x.test' ) ) );
	}

	public function test_a_name_and_an_address(): void {
		$this->assertSame( array( 'ana@x.test' ), \diluxone_mail_parse_addresses( 'Ana Pérez <ana@x.test>' ) );
	}

	public function test_addresses_are_lowercased_and_deduplicated(): void {
		$this->assertSame( array( 'ana@x.test' ), \diluxone_mail_parse_addresses( 'Ana@X.test, ana@x.test' ) );
	}

	public function test_anything_that_is_not_an_address_is_dropped(): void {
		$this->assertSame( array( 'ok@x.test' ), \diluxone_mail_parse_addresses( 'no-es-nada, ok@x.test, <>' ) );
	}

	public function test_cc_and_bcc_come_from_the_headers(): void {
		$recipients = \diluxone_mail_recipients(
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
			$recipients
		);
	}

	public function test_headers_may_arrive_as_a_list_or_as_text(): void {
		$this->assertSame( array( 'From: a@x.test', 'Cc: b@x.test' ), \diluxone_mail_header_lines( array( 'From: a@x.test', 'Cc: b@x.test' ) ) );
		$this->assertSame( array( 'From: a@x.test', 'Cc: b@x.test' ), \diluxone_mail_header_lines( "From: a@x.test\nCc: b@x.test\n" ) );
		$this->assertSame( array( 'From: a@x.test' ), \diluxone_mail_header_lines( array( 'From' => 'a@x.test' ) ) );
	}

	public function test_the_sender_and_the_type_come_from_the_headers(): void {
		$meta = \diluxone_mail_header_meta( array( 'headers' => "From: Site <hello@x.test>\r\nContent-Type: text/html; charset=UTF-8" ) );

		$this->assertSame( 'hello@x.test', $meta['from'] );
		$this->assertSame( 'text/html', $meta['type'] );
	}

	public function test_with_no_headers_the_type_is_plain_text(): void {
		$this->assertSame( 'text/plain', \diluxone_mail_header_meta( array() )['type'] );
	}
}
