<?php
/**
 * The menu and what its screens share.
 *
 * Every menu entry is a screen with a life of its own. What lives here is
 * what all of them repeat: the title with the plugin's name in front, the
 * notices, a screen's URL, and the "you cannot change this from here" notes
 * that are the most important part of a form that does not lie.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

const DILUXONE_MAIL_MENU = 'diluxone-mail';

/**
 * The settings screen's slug.
 *
 * The menu slug belongs to the first screen of the menu, which is the
 * overview: WordPress gives the top-level entry to whatever renders first.
 * So the settings live at a slug of their own, and everything that links to
 * them uses this rather than spelling it out.
 */
const DILUXONE_MAIL_SETTINGS = 'diluxone-mail-settings';

/**
 * The provider screen's slug.
 *
 * Choosing a provider is a sequence walked once, with a step that opens the
 * next; the settings are things somebody comes back to change. One row of tabs
 * holding both made the sequence look like a place to rummage in.
 *
 * `_PAGE` because the bare name is taken: DILUXONE_MAIL_PROVIDER is how a site
 * pins the provider from wp-config.php, and defining it here made every
 * install believe its environment had chosen a provider called
 * "diluxone-mail-provider".
 */
const DILUXONE_MAIL_PROVIDER_PAGE = 'diluxone-mail-provider';

/**
 * The name the plugin introduces itself with in the dashboard.
 *
 * Written once: the menu, every screen title and the browser tab all use it.
 * Written in three places, sooner or later they say three different things.
 */
function diluxone_mail_plugin_name(): string {
	return (string) apply_filters( 'diluxone_mail_plugin_name', __( 'DiluxOne Mail', 'diluxone-mail' ) );
}

/** A screen's title, with the plugin's name in front. */
function diluxone_mail_screen_title( string $title ): string {
	return sprintf(
		/* translators: 1: plugin name, 2: screen name */
		_x( '%1$s | %2$s', 'title of a dashboard screen', 'diluxone-mail' ),
		diluxone_mail_plugin_name(),
		$title
	);
}

/** The same, in the browser tab. */
function diluxone_mail_admin_title( string $admin_title, string $title ): string {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen instanceof WP_Screen || false === strpos( (string) $screen->id, DILUXONE_MAIL_MENU ) ) {
		return $admin_title;
	}

	return str_replace( $title, diluxone_mail_screen_title( $title ), $admin_title );
}
add_filter( 'admin_title', 'diluxone_mail_admin_title', 10, 2 );

/**
 * The menu's screens, in order.
 *
 * @return array<string, string>
 */
function diluxone_mail_screens(): array {
	return array(
		'diluxone-mail'          => __( 'Overview', 'diluxone-mail' ),
		'diluxone-mail-provider' => __( 'Provider', 'diluxone-mail' ),
		'diluxone-mail-settings' => __( 'Settings', 'diluxone-mail' ),
		'diluxone-mail-log'      => __( 'Mail log', 'diluxone-mail' ),
		'diluxone-mail-dns'      => __( 'Deliverability', 'diluxone-mail' ),
		'diluxone-mail-status'   => __( 'Status', 'diluxone-mail' ),
	);
}

/** The menu. */
function diluxone_mail_menu(): void {
	add_menu_page(
		diluxone_mail_plugin_name(),
		diluxone_mail_plugin_name(),
		'manage_options',
		DILUXONE_MAIL_MENU,
		'diluxone_mail_screen_overview',
		'dashicons-email-alt',
		76
	);

	$callbacks = array(
		'diluxone-mail'          => 'diluxone_mail_screen_overview',
		'diluxone-mail-provider' => 'diluxone_mail_screen_provider',
		'diluxone-mail-settings' => 'diluxone_mail_screen_settings',
		'diluxone-mail-log'      => 'diluxone_mail_screen_log',
		'diluxone-mail-dns'      => 'diluxone_mail_screen_dns',
		'diluxone-mail-status'   => 'diluxone_mail_screen_status',
	);

	foreach ( diluxone_mail_screens() as $slug => $title ) {
		add_submenu_page( DILUXONE_MAIL_MENU, $title, $title, 'manage_options', $slug, $callbacks[ $slug ] );
	}
}
add_action( 'admin_menu', 'diluxone_mail_menu' );

/**
 * The URL of one of the plugin's screens, with whatever arguments are needed.
 *
 * @param array<string, mixed> $args
 */
function diluxone_mail_admin_url( string $screen, array $args = array() ): string {
	return add_query_arg( array_merge( array( 'page' => $screen ), $args ), admin_url( 'admin.php' ) );
}

/**
 * The shared header: title and, where there are any, the notices that apply
 * everywhere.
 *
 * The observer-mode and pre_wp_mail notices go on every screen of the plugin
 * and not only on settings: whoever looks at the log and sees "handed to
 * FluentSMTP" needs the explanation at hand.
 */
function diluxone_mail_screen_open( string $title ): void {
	echo '<div class="wrap diluxone-mail-admin">';
	printf( '<h1>%s</h1>', esc_html( diluxone_mail_screen_title( $title ) ) );

	diluxone_mail_done_notice();
	diluxone_mail_observer_notice();
	diluxone_mail_pre_wp_mail_notice();
}

/** The closing tag. */
function diluxone_mail_screen_close(): void {
	echo '</div>';
}

/** A short notice at the top of the screen. */
function diluxone_mail_notice( string $text, string $type = 'success' ): void {
	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $text )
	);
}

/**
 * The notice matching the `diluxone_mail_done` key in the URL.
 *
 * Every admin action redirects with a key, and the text of each one is here.
 * A text that is not here is not shown: the key comes from the URL.
 */
function diluxone_mail_done_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- It only picks a text from a closed list; it changes nothing.
	$done = isset( $_GET['diluxone_mail_done'] ) ? sanitize_key( wp_unslash( $_GET['diluxone_mail_done'] ) ) : '';

	if ( '' === $done ) {
		return;
	}

	$texts = array(
		'saved'             => array( __( 'Settings saved.', 'diluxone-mail' ), 'success' ),
		'profile-applied'   => array( __( 'Provider profile applied. Paste the credentials and save.', 'diluxone-mail' ), 'success' ),
		'took-over'         => array( __( 'DiluxOne Mail is now handling this site\'s outgoing mail.', 'diluxone-mail' ), 'success' ),
		'revalidated'       => array( __( 'DNS cache cleared and the diagnosis run again.', 'diluxone-mail' ), 'success' ),
		'connected'         => array( __( 'The server answered and the credentials work. They are saved.', 'diluxone-mail' ), 'success' ),
		'no-crypto'         => array( __( 'The server answered, but this PHP cannot encrypt the password (openssl with AES-256-GCM is missing), and it is not stored in the clear. Set it through the DILUXONE_MAIL_PASS constant in wp-config.php instead.', 'diluxone-mail' ), 'error' ),
		'no-api'            => array( __( 'That provider has no API here, only its SMTP server. Choose SMTP, or a provider that does.', 'diluxone-mail' ), 'error' ),
		'key-ok'            => array( __( 'The provider recognised the key. It is saved.', 'diluxone-mail' ), 'success' ),
		'key-unchecked'     => array( __( 'The key is saved. The provider could not be asked whether it is valid, so the test message is what will tell you.', 'diluxone-mail' ), 'warning' ),
		'key-refused'       => array( __( 'The provider does not recognise that key, so nothing was saved.', 'diluxone-mail' ), 'error' ),
		'domains-refreshed' => array( __( 'The list of domains was read again from the provider.', 'diluxone-mail' ), 'success' ),
		'domains-failed'    => array( __( 'The provider could not be asked for its domains. The address can be typed by hand.', 'diluxone-mail' ), 'warning' ),
		'connection-failed' => array( __( 'The server did not accept the connection, so nothing was saved. What went wrong is below.', 'diluxone-mail' ), 'error' ),
		'tested'            => array( __( 'Test message sent.', 'diluxone-mail' ), 'success' ),
		'not-allowed'       => array( __( 'This site\'s settings are fixed by the network and cannot be changed here.', 'diluxone-mail' ), 'warning' ),
	);

	if ( isset( $texts[ $done ] ) ) {
		diluxone_mail_notice( $texts[ $done ][0], $texts[ $done ][1] );
	}
}

/**
 * How the provenance of a value is explained.
 *
 * It is the text next to every read-only control, and the one the status
 * screen uses. It names the concrete origin, because "defined by the
 * environment" without saying which sends people looking blindly.
 */
function diluxone_mail_source_label( string $source, string $origin ): string {
	switch ( $source ) {
		case 'constant':
			/* translators: %s: name of the constant */
			return sprintf( __( 'defined by the environment — PHP constant %s', 'diluxone-mail' ), $origin );
		case 'env':
			/* translators: %s: name of the variable */
			return sprintf( __( 'defined by the environment — variable %s', 'diluxone-mail' ), $origin );
		case 'unreadable':
			return __( 'stored, but it can no longer be decrypted — type it again', 'diluxone-mail' );
		case 'network':
			return __( 'set by the network', 'diluxone-mail' );
		case 'site':
			return __( 'set on this site', 'diluxone-mail' );
		default:
			return __( 'default value', 'diluxone-mail' );
	}
}

/**
 * Is the site fixing this setting from code?
 *
 * Another plugin can pin a value through the `diluxone_mail_option` filter —
 * because on that site it is not an option but how things work. When that
 * happens the control in the dashboard saves and changes nothing, which is
 * exactly the kind of lie a settings screen has to avoid. With this it can be
 * shown next to the control.
 */
function diluxone_mail_option_forced( string $key ): bool {
	return diluxone_mail_option( $key ) !== diluxone_mail_option_stored( $key )['value'];
}

/**
 * Who is fixing a setting from code.
 *
 * "Something on the site decided this" is of no use to anybody: whoever reads
 * that wants to go and remove it, and does not know where. Here the file and
 * the function come out.
 *
 * @return array<int, string>
 */
function diluxone_mail_option_forced_by(): array {
	$who = array();

	foreach ( diluxone_mail_hook_origins( 'diluxone_mail_option' ) as $origin ) {
		$who[] = ltrim( str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', $origin['file'] ), '/' );
	}

	return $who;
}

/** The notice that a setting is fixed from code, naming who fixes it. */
function diluxone_mail_forced_notice( string $key ): void {
	if ( ! diluxone_mail_option_forced( $key ) ) {
		return;
	}

	$who = diluxone_mail_option_forced_by();
	?>
	<p class="description diluxone-mail-forced">
		<?php esc_html_e( 'This site fixes this from code: whatever is chosen here, it stays as it is.', 'diluxone-mail' ); ?>
		<?php if ( array() !== $who ) : ?>
			<code><?php echo esc_html( implode( ', ', $who ) ); ?></code>
		<?php endif; ?>
	</p>
	<?php
}

/**
 * The caption that says where a value came from.
 *
 * Only for the values that come from somewhere worth naming: one set on this
 * site, or left at its default, needs no explanation next to the control.
 *
 * @param array<string, mixed> $field
 */
function diluxone_mail_source_caption( array $field ): void {
	if ( 'site' === $field['source'] || 'default' === $field['source'] ) {
		return;
	}

	printf( ' <span class="description diluxone-mail-source">— %s</span>', esc_html( (string) $field['label'] ) );
}

/**
 * Loads a view from templates/ with its data.
 *
 * @param array<string, mixed> $data
 */
function diluxone_mail_view( string $name, array $data = array() ): void {
	$file = DILUXONE_MAIL_DIR . 'templates/' . $name . '.php';

	if ( file_exists( $file ) ) {
		include $file;
	}
}

/** The plugin's dashboard styles. */
function diluxone_mail_admin_styles( string $hook ): void {
	if ( false === strpos( $hook, 'diluxone-mail' ) && ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
		return;
	}

	$css = DILUXONE_MAIL_DIR . 'assets/diluxone-mail-admin.css';

	wp_enqueue_style(
		'diluxone-mail-admin',
		DILUXONE_MAIL_URL . 'assets/diluxone-mail-admin.css',
		array(),
		file_exists( $css ) ? (string) filemtime( $css ) : DILUXONE_MAIL_VERSION
	);

	// Only where there is a form to help: the log, the status and somebody's
	// profile have nothing for it to do.
	if ( false === strpos( $hook, DILUXONE_MAIL_SETTINGS ) && false === strpos( $hook, 'diluxone-mail-network' ) ) {
		return;
	}

	$js = DILUXONE_MAIL_DIR . 'assets/js/diluxone-mail-admin.js';

	wp_enqueue_script(
		'diluxone-mail-admin',
		DILUXONE_MAIL_URL . 'assets/js/diluxone-mail-admin.js',
		array(),
		file_exists( $js ) ? (string) filemtime( $js ) : DILUXONE_MAIL_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'diluxone_mail_admin_styles' );
