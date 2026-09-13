<?php
/**
 * Qué le configura el transporte a PHPMailer, con un PHPMailer de mentira.
 */

namespace Tests\Unit\DiluxOneMail;

use PHPMailer\PHPMailer\PHPMailer;

class PhpMailerTest extends AdminTestCase {

	public function test_un_proveedor_remoto_con_auth_y_starttls(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );
		\update_option( 'diluxone_mail_port', 587 );
		\update_option( 'diluxone_mail_encryption', 'tls' );
		\update_option( 'diluxone_mail_user', 'key' );
		\update_option( 'diluxone_mail_pass', 'secret' );
		\update_option( 'diluxone_mail_timeout', 12 );

		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );

		$this->assertSame( 'smtp', $m->Mailer );
		$this->assertSame( 'in-v3.mailjet.com', $m->Host );
		$this->assertSame( 587, $m->Port );
		$this->assertSame( 'tls', $m->SMTPSecure );
		$this->assertTrue( $m->SMTPAutoTLS );
		$this->assertTrue( $m->SMTPAuth );
		$this->assertSame( 'key', $m->Username );
		$this->assertSame( 'secret', $m->Password );
		$this->assertSame( 12, $m->Timeout );
		$this->assertSame( 0, $m->SMTPDebug );
	}

	public function test_un_perfil_local_apaga_auth_y_autotls(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailpit' );
		\update_option( 'diluxone_mail_host', 'mailpit' );
		\update_option( 'diluxone_mail_port', 0 );
		\update_option( 'diluxone_mail_encryption', 'none' );
		\update_option( 'diluxone_mail_user', 'ignorado' );

		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );

		$this->assertSame( '', $m->SMTPSecure );
		$this->assertFalse( $m->SMTPAutoTLS );
		$this->assertFalse( $m->SMTPAuth );
		$this->assertSame( 587, $m->Port );
	}

	public function test_en_observador_o_sin_host_no_se_toca_pero_el_debug_si(): void {
		\update_option( 'diluxone_mail_mode', 'observe' );
		\diluxone_mail_debug_enabled( true );

		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );

		$this->assertSame( 'mail', $m->Mailer );
		$this->assertSame( 2, $m->SMTPDebug );
		( $m->Debugoutput )( 'SERVER -> CLIENT: 220', 2 );
		$this->assertStringContainsString( '220', \diluxone_mail_debug_buffer() );

		\diluxone_mail_debug_enabled( false );
		\update_option( 'diluxone_mail_mode', 'transport' );
		$m = new PHPMailer();
		\diluxone_mail_phpmailer_init( $m );
		$this->assertSame( 'mail', $m->Mailer );
	}

	public function test_el_message_id_y_la_ultima_respuesta(): void {
		\diluxone_mail_current( array( 'uuid' => 'abc-123' ) );

		$m = new PHPMailer();
		\diluxone_mail_stamp_message_id( $m );
		$this->assertSame( '<abc-123@' . parse_url( \home_url(), PHP_URL_HOST ) . '>', $m->MessageID );

		$GLOBALS['phpmailer'] = $m;
		$this->assertSame( '250 OK queued as test-id', \diluxone_mail_last_smtp_reply() );
		unset( $GLOBALS['phpmailer'] );
		\diluxone_mail_current( null, true );
	}

	public function test_un_envio_completo_guarda_la_respuesta_del_servidor(): void {
		\update_option( 'diluxone_mail_mode', 'transport' );
		\update_option( 'diluxone_mail_provider', 'mailjet' );
		\update_option( 'diluxone_mail_host', 'in-v3.mailjet.com' );
		\add_filter( 'wp_mail', 'diluxone_mail_capture', PHP_INT_MAX );
		\add_action( 'wp_mail_succeeded', 'diluxone_mail_on_succeeded' );
		$GLOBALS['phpmailer'] = new PHPMailer();

		\wp_mail( 'a@x.test', 'Hola', 'Cuerpo' );

		$this->assertSame( '250 OK queued as test-id', $this->db->of( 'update' )[0]['args']['data']['response'] );
		unset( $GLOBALS['phpmailer'] );
	}
}
