<?php
/**
 * That the three lists this plugin draws itself from are registries.
 *
 * The plugin is one piece today and nothing adds a screen, a tab or a
 * provider from outside it. These tests are not about that: they are about
 * the screens being built out of what is registered rather than out of a list
 * written inside the file that draws them. The day a part of this is its own
 * plugin, that is the difference between registering a screen and editing
 * admin.php.
 *
 * The other half of a registry is what it refuses. Anything reached through a
 * filter is written by somebody else, and a half-written entry fails far from
 * whoever wrote it — on somebody's dashboard, on the next click.
 */

namespace Tests\Unit\DiluxOneMail;

class RegistryTest extends AdminTestCase {

	public function test_a_screen_can_be_added_renamed_and_taken_away(): void {
		\add_filter(
			'diluxone_mail_screens',
			static function ( array $screens ): array {
				$screens['diluxone-mail-extra'] = array(
					'title'    => 'Lo que venga',
					'callback' => 'diluxone_mail_screen_status',
				);

				$screens['diluxone-mail-log']['title'] = 'Otro nombre';

				unset( $screens['diluxone-mail-status'] );

				return $screens;
			}
		);

		$screens = \diluxone_mail_screens();

		$this->assertArrayHasKey( 'diluxone-mail-extra', $screens );
		$this->assertSame( 'Otro nombre', $screens['diluxone-mail-log']['title'] );
		$this->assertArrayNotHasKey( 'diluxone-mail-status', $screens );

		// And the menu is built out of it rather than out of a second list.
		$GLOBALS['_test_submenu'] = array();
		\diluxone_mail_menu();

		$slugs = array_map( static fn( array $args ): string => (string) $args[4], $GLOBALS['_test_submenu'] );

		$this->assertContains( 'diluxone-mail-extra', $slugs );
		$this->assertNotContains( 'diluxone-mail-status', $slugs );
	}

	/**
	 * A screen whose function is not there any more — an add-on deactivated
	 * without its filter going with it — is a fatal error on the next click,
	 * and the click is the admin menu itself.
	 */
	public function test_a_screen_that_cannot_be_rendered_is_not_offered(): void {
		\add_filter(
			'diluxone_mail_screens',
			static function ( array $screens ): array {
				$screens['fantasma']  = array( 'title' => 'Fantasma', 'callback' => 'diluxone_mail_no_existe' );
				$screens['a-medias']  = array( 'title' => 'A medias' );
				$screens['cualquier'] = 'no es un array';

				return $screens;
			}
		);

		$screens = \diluxone_mail_screens();

		$this->assertArrayNotHasKey( 'fantasma', $screens );
		$this->assertArrayNotHasKey( 'a-medias', $screens );
		$this->assertArrayNotHasKey( 'cualquier', $screens );
		$this->assertCount( 6, $screens );
	}

	public function test_a_tab_can_be_added_to_the_row(): void {
		\add_filter(
			'diluxone_mail_settings_tabs',
			static function ( array $tabs ): array {
				$tabs['extra'] = array(
					'label'  => 'Lo que venga',
					'step'   => 0,
					'needs'  => '',
					'groups' => array( 'log' ),
					'screen' => 'settings',
				);

				return $tabs;
			}
		);

		$tabs = \diluxone_mail_settings_tabs();

		$this->assertArrayHasKey( 'extra', $tabs );
		$this->assertSame( array( 'log' ), \diluxone_mail_tab_groups( 'extra' ) );
		$this->assertSame( 'settings', \diluxone_mail_tab_screen( 'extra' ) );
	}

	/**
	 * The save routine writes whatever the current tab's groups name. A group
	 * invented by a filter would be a way of writing settings this plugin
	 * never declared, from a form this plugin never drew.
	 */
	public function test_a_tab_cannot_invent_a_group_of_settings_to_save(): void {
		\add_filter(
			'diluxone_mail_settings_tabs',
			static function ( array $tabs ): array {
				$tabs['colado'] = array(
					'label'  => 'Colado',
					'step'   => 0,
					'needs'  => '',
					'groups' => array( 'log', 'lo_que_se_me_ocurra' ),
					'screen' => 'settings',
				);

				$tabs['incompleto'] = array( 'label' => 'Incompleto' );

				return $tabs;
			}
		);

		$tabs = \diluxone_mail_settings_tabs();

		$this->assertArrayNotHasKey( 'colado', $tabs );
		$this->assertArrayNotHasKey( 'incompleto', $tabs );
	}

	public function test_an_smtp_profile_can_be_added_the_way_an_api_one_already_could(): void {
		\add_filter(
			'diluxone_mail_providers',
			static function ( array $profiles ): array {
				$profiles['propio'] = array_merge(
					$profiles['custom'],
					array( 'name' => 'El de la casa', 'host' => 'smtp.casa.test', 'port' => 2525 )
				);

				return $profiles;
			}
		);

		$this->assertSame( 'smtp.casa.test', \diluxone_mail_provider( 'propio' )['host'] );
		$this->assertSame( 2525, \diluxone_mail_provider_defaults( 'propio' )['diluxone_mail_port'] );
		$this->assertSame( 'propio', \diluxone_mail_provider_defaults( 'propio' )['diluxone_mail_provider'] );
	}

	public function test_a_half_written_profile_never_reaches_the_dropdown(): void {
		\add_filter(
			'diluxone_mail_providers',
			static fn( array $profiles ): array => array_merge(
				$profiles,
				array( 'roto' => array( 'name' => 'Roto', 'host' => 'smtp.roto.test' ) )
			)
		);

		$this->assertArrayNotHasKey( 'roto', \diluxone_mail_providers() );

		// And an unknown key answers the generic profile, as it always has.
		$this->assertSame( \diluxone_mail_provider( 'custom' ), \diluxone_mail_provider( 'roto' ) );
	}

	/**
	 * Whatever else a filter does, the generic profile survives it: it is what
	 * a stored provider that no longer exists falls back to.
	 */
	public function test_the_generic_profile_cannot_be_taken_away(): void {
		\add_filter( 'diluxone_mail_providers', static fn(): array => array() );

		$this->assertArrayHasKey( 'custom', \diluxone_mail_providers() );
		$this->assertSame( 'custom', \diluxone_mail_provider_defaults( 'mailjet' )['diluxone_mail_provider'] );
	}
}
