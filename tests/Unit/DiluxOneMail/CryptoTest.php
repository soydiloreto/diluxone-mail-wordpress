<?php
/**
 * The credential at rest: encrypted with the site's own salts, and what
 * happens when those change.
 */

namespace Tests\Unit\DiluxOneMail;

class CryptoTest extends AdminTestCase {

	public function test_a_credential_goes_in_and_comes_back(): void {
		$cipher = \diluxone_mail_encrypt( 'p@ss "rara" áé' );

		$this->assertTrue( \diluxone_mail_is_encrypted( $cipher ) );
		$this->assertStringNotContainsString( 'rara', $cipher );
		$this->assertSame( 'p@ss "rara" áé', \diluxone_mail_decrypt( $cipher ) );

		// Twice over the same value gives two payloads: the IV is random, so
		// two sites with the same password do not share a ciphertext.
		$this->assertNotSame( $cipher, \diluxone_mail_encrypt( 'p@ss "rara" áé' ) );
	}

	public function test_nothing_is_encrypted_twice_and_an_empty_one_stays_empty(): void {
		$cipher = \diluxone_mail_encrypt( 'una' );

		$this->assertSame( $cipher, \diluxone_mail_encrypt( $cipher ) );
		$this->assertSame( '', \diluxone_mail_encrypt( '' ) );
		$this->assertSame( '', \diluxone_mail_stored_password( '' ) );
	}

	public function test_a_tampered_or_truncated_payload_does_not_decrypt(): void {
		$cipher = \diluxone_mail_encrypt( 'una' );

		$this->assertNull( \diluxone_mail_decrypt( $cipher . 'x' ) );
		$this->assertNull( \diluxone_mail_decrypt( DILUXONE_MAIL_CRYPTO_PREFIX . 'corto' ) );
		$this->assertNull( \diluxone_mail_decrypt( DILUXONE_MAIL_CRYPTO_PREFIX . '!!!no es base64!!!' ) );
		// Without the tag it is not ours and is not guessed at.
		$this->assertNull( \diluxone_mail_decrypt( 'texto plano' ) );
	}

	public function test_rotating_the_salts_makes_it_unreadable_rather_than_wrong(): void {
		\update_option( 'diluxone_mail_pass', \diluxone_mail_encrypt( 'una' ) );

		$this->assertSame( 'una', \diluxone_mail_config_value( 'pass' )['value'] );

		$GLOBALS['_test_salt'] = 'otras-sales';

		$pass = \diluxone_mail_config_value( 'pass' );

		$this->assertSame( '', $pass['value'] );
		$this->assertSame( 'unreadable', $pass['source'] );
		$this->assertStringContainsString( 'decrypted', \diluxone_mail_source_label( 'unreadable', '' ) );

		// And the screen says so instead of pretending there is no password.
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		$this->assertStringContainsString( 'no longer be decrypted', $this->render( 'diluxone_mail_screen_provider' ) );
	}

	public function test_a_password_stored_before_this_existed_is_encrypted_in_place(): void {
		\update_option( 'diluxone_mail_pass', 'en texto plano' );
		\update_site_option( 'diluxone_mail_pass', 'de la red' );

		\diluxone_mail_encrypt_stored_password();

		$this->assertTrue( \diluxone_mail_is_encrypted( (string) \get_option( 'diluxone_mail_pass' ) ) );
		$this->assertTrue( \diluxone_mail_is_encrypted( (string) \get_site_option( 'diluxone_mail_pass' ) ) );
		$this->assertSame( 'en texto plano', \diluxone_mail_config_value( 'pass' )['value'] );

		// And it is not touched again on the next request.
		$once = (string) \get_option( 'diluxone_mail_pass' );
		\diluxone_mail_encrypt_stored_password();
		$this->assertSame( $once, \get_option( 'diluxone_mail_pass' ) );
	}

	public function test_the_environment_is_never_encrypted_and_never_stored(): void {
		putenv( 'DILUXONE_MAIL_PASS=del-entorno' );

		$pass = \diluxone_mail_config_value( 'pass' );

		$this->assertSame( 'del-entorno', $pass['value'] );
		$this->assertSame( 'env', $pass['source'] );

		\diluxone_mail_store_password( 'otra', 'site' );
		$this->assertFalse( \get_option( 'diluxone_mail_pass' ) );

		putenv( 'DILUXONE_MAIL_PASS' );
	}

	public function test_the_transcript_redaction_still_finds_the_password(): void {
		\update_option( 'diluxone_mail_pass', \diluxone_mail_encrypt( 'secreto' ) );

		// The dialogue carries it in base64, which is where it actually shows
		// up, and redaction works off the decrypted value.
		$this->assertStringNotContainsString( 'secreto', \diluxone_mail_redact( 'AUTH PLAIN secreto' ) );
		$this->assertStringNotContainsString( base64_encode( 'secreto' ), \diluxone_mail_redact( 'AUTH LOGIN ' . base64_encode( 'secreto' ) ) );
	}
}
