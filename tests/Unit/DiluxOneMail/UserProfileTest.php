<?php
/**
 * La sección en la ficha de una persona.
 */

namespace Tests\Unit\DiluxOneMail;

class UserProfileTest extends AdminTestCase {

	public function test_la_ficha_muestra_lo_suyo_con_el_boton_de_reenviar(): void {
		$user                   = $this->user( 3, 'Ana@X.test' );
		$this->db->next_var     = 30;
		$this->db->next_results = array( $this->row(), $this->row( array( 'status' => 'failed', 'error' => 'boom' ) ) );
		\update_option( 'diluxone_mail_log_body', 1 );

		$html = $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) );

		$this->assertStringContainsString( 'Mail sent to this person', $html );
		$this->assertStringContainsString( 'ana@x.test', $html );
		$this->assertStringContainsString( '30 messages', $html );
		$this->assertStringContainsString( 'boom', $html );
		$this->assertStringContainsString( 'diluxone_mail_resend', $html );
		$this->assertStringContainsString( 'See all in the mail log', $html );
		$this->assertStringContainsString( "email IN ('ana@x.test')", $this->db->of( 'get_results' )[0]['sql'] );
	}

	public function test_sin_cuerpo_no_hay_boton_y_sin_nada_lo_dice(): void {
		$user = $this->user( 3, 'ana@x.test' );

		$html = $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) );

		$this->assertStringContainsString( 'Nothing yet', $html );
		$this->assertStringNotContainsString( 'diluxone_mail_resend', $html );
		$this->assertStringContainsString( 'cannot be resent', $html );
	}

	public function test_con_el_historial_apagado_o_sin_permiso(): void {
		$user = $this->user( 3, 'ana@x.test' );

		\update_option( 'diluxone_mail_log_enabled', 0 );
		$this->assertStringContainsString( 'The mail log is off', $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) ) );

		$GLOBALS['_test_can'] = false;
		$this->assertSame( '', $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) ) );
	}

	public function test_en_una_red_se_ve_el_sitio_de_cada_fila(): void {
		$GLOBALS['_test_multisite'] = true;
		$GLOBALS['_test_sites'][]   = new \WP_Site( 1, 'Principal' );
		$user                       = $this->user( 3, 'ana@x.test' );
		$this->db->next_results     = array( $this->row() );

		$this->assertStringContainsString( 'Principal', $this->render( static fn() => \diluxone_mail_user_profile_section( $user ) ) );
	}

	public function test_las_direcciones_anteriores_entran_por_el_filtro(): void {
		\add_filter( 'diluxone_mail_user_emails', static fn( array $e ) => array_merge( $e, array( 'Vieja@X.test', '' ) ), 10, 1 );

		$this->assertSame( array( 'ana@x.test', 'vieja@x.test' ), \diluxone_mail_user_emails( $this->user( 3, 'ana@x.test' ) ) );
	}
}
