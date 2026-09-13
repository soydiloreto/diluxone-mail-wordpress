<?php
/**
 * Base de los tests que necesitan un WordPress de verdad cargado.
 *
 * Entre test y test se borran todas las options del plugin —las del sitio y
 * las de la red— para que cada uno arranque como un plugin recién instalado.
 * La lista sale de los valores por defecto y no de una lista escrita acá:
 * una option nueva queda cubierta sola, y no hay forma de que un test vea lo
 * que dejó el anterior y pase —o falle— por el motivo equivocado.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class IntegrationTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		foreach ( array_keys( diluxone_mail_option_defaults() ) as $option ) {
			delete_option( $option );
			delete_site_option( $option );
		}

		delete_option( 'diluxone_mail_last_result' );
	}

	/** Una persona nueva, con el rol que se le pase. */
	protected function alguien( string $rol = 'subscriber' ): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 'diluxone_mail_' . wp_generate_password( 8, false ),
				'user_email' => wp_generate_password( 8, false ) . '@ejemplo.test',
				'user_pass'  => wp_generate_password( 16 ),
				'role'       => $rol,
			)
		);
	}
}
