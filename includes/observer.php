<?php
/**
 * Is another plugin handling this site's mail?
 *
 * There are seven million sites with an SMTP plugin already installed and
 * working. Nobody is going to uninstall theirs to try this one out. So if, on
 * start-up, it finds somebody else handling delivery, it does not fight: it
 * starts logging and diagnosing without touching anything, and says so. A
 * site whose mail works should not have to break to try this out.
 *
 * Detecting the other one has two parts. The list of known plugins — by file
 * name — is the fast one and the one that yields a readable name. But the one
 * that counts is the second: looking at who is actually hooked into the three
 * places mail can be taken over from. A plugin that is not on the list still
 * shows up there, with the file it comes from.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The known mail plugins, by main file.
 *
 * Only the ones whose file name is known. One that is not here does not stay
 * invisible: the hook-based detection below catches it, only with the folder
 * name instead of the pretty one.
 *
 * @return array<string, string>
 */
function diluxone_mail_known_mailers(): array {
	return array(
		'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
		'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
		'post-smtp/postman-smtp.php'        => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
		'wp-ses/wp-ses.php'                 => 'WP Offload SES Lite',
		'wp-offload-ses/wp-offload-ses.php' => 'WP Offload SES',
		'mailgun/mailgun.php'               => 'Mailgun',
		'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
		'smtp-mailer/main.php'              => 'SMTP Mailer',
		'wp-mail-bank/wp-mail-bank.php'     => 'Mail Bank',
		'site-mailer/site-mailer.php'       => 'Site Mailer',
	);
}

/**
 * Where a callback comes from: the file, and which plugin if any.
 *
 * It works with anonymous functions too, which is the case that matters: the
 * Azure App Service mail plugin hooks pre_wp_mail with a closure, and without
 * this there would be no way of saying who it was.
 *
 * @param mixed $callback The callback exactly as it sits in $wp_filter.
 * @return array{file: string, plugin: string, name: string}
 *         plugin: the folder inside wp-content/plugins, or '' if not a plugin.
 */
function diluxone_mail_callback_origin( $callback ): array {
	$file = '';

	try {
		if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
			$file = (string) ( new ReflectionFunction( $callback ) )->getFileName();
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$file = (string) ( new ReflectionMethod( $callback[0], (string) $callback[1] ) )->getFileName();
		} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			$file = (string) ( new ReflectionMethod( $callback, '__invoke' ) )->getFileName();
		}
	} catch ( ReflectionException $e ) {
		$file = '';
	}

	$file    = wp_normalize_path( $file );
	$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	$plugin  = '';

	if ( '' !== $file && 0 === strpos( $file, $plugins ) ) {
		$plugin = (string) strtok( substr( $file, strlen( $plugins ) ), '/' );
	}

	$name = $plugin;

	foreach ( diluxone_mail_known_mailers() as $basename => $pretty ) {
		if ( '' !== $plugin && 0 === strpos( $basename, $plugin . '/' ) ) {
			$name = $pretty;
			break;
		}
	}

	return array(
		'file'   => $file,
		'plugin' => $plugin,
		'name'   => $name,
	);
}

/**
 * Who is hooked into a hook, this plugin aside.
 *
 * @return array<int, array{file: string, plugin: string, name: string, priority: int, callback: mixed}>
 */
function diluxone_mail_hook_origins( string $hook ): array {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return array();
	}

	$own = wp_normalize_path( DILUXONE_MAIL_DIR );
	$out = array();

	foreach ( $wp_filter[ $hook ]->callbacks as $priority => $hooked ) {
		foreach ( $hooked as $entry ) {
			$origin = diluxone_mail_callback_origin( $entry['function'] );

			if ( '' === $origin['file'] || 0 === strpos( $origin['file'], $own ) ) {
				continue;
			}

			$out[] = array_merge(
				$origin,
				array(
					'priority' => (int) $priority,
					'callback' => $entry['function'],
				)
			);
		}
	}

	return $out;
}

/**
 * The plugins currently handling the mail, if any.
 *
 * The three ways a plugin can take delivery over are all checked:
 *
 *   - Redefining wp_mail() entirely. It is pluggable, and WP Mail SMTP and
 *     Post SMTP do exactly that. It is detected by the file it is defined in:
 *     if that is not wp-includes/pluggable.php, somebody replaced it.
 *   - Hooking phpmailer_init, which is what this plugin and most others do.
 *   - Cutting the send short in pre_wp_mail, which is what the ones delivering
 *     over an HTTP API rather than SMTP do.
 *
 * The list is built once per request: the expensive part is the reflection,
 * and the result does not change while the page loads. $fresh rebuilds it,
 * for use right after detaching somebody.
 *
 * @return array<int, array{name: string, plugin: string, how: string}>
 */
function diluxone_mail_other_mailers( bool $fresh = false ): array {
	static $cache = null;

	if ( null !== $cache && ! $fresh ) {
		return $cache;
	}

	$seen = array();

	// wp_mail() replaced.
	if ( function_exists( 'wp_mail' ) ) {
		try {
			$file = wp_normalize_path( (string) ( new ReflectionFunction( 'wp_mail' ) )->getFileName() );
		} catch ( ReflectionException $e ) {
			$file = '';
		}

		if ( '' !== $file && false === strpos( $file, '/wp-includes/' ) ) {
			$origin = diluxone_mail_callback_origin( 'wp_mail' );

			$seen[ '' !== $origin['plugin'] ? $origin['plugin'] : $file ] = array(
				'name'   => '' !== $origin['name'] ? $origin['name'] : basename( $file ),
				'plugin' => $origin['plugin'],
				'how'    => 'wp_mail',
			);
		}
	}

	foreach ( array( 'phpmailer_init', 'pre_wp_mail' ) as $hook ) {
		foreach ( diluxone_mail_hook_origins( $hook ) as $origin ) {
			$key = '' !== $origin['plugin'] ? $origin['plugin'] : $origin['file'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = array(
				'name'   => '' !== $origin['name'] ? $origin['name'] : basename( $origin['file'] ),
				'plugin' => $origin['plugin'],
				'how'    => $hook,
			);
		}
	}

	/**
	 * Filters the list of plugins detected as handling the mail.
	 *
	 * @param array<int, array{name: string, plugin: string, how: string}> $mailers
	 */
	$cache = (array) apply_filters( 'diluxone_mail_other_mailers', array_values( $seen ) );

	return $cache;
}

/**
 * Does this plugin send the mail, or does it only watch?
 *
 * It is the question mailer.php asks before touching PHPMailer, and the one
 * the status screen answers.
 */
function diluxone_mail_transport_active(): bool {
	$mode = (string) diluxone_mail_option( 'diluxone_mail_mode' );

	if ( 'observe' === $mode ) {
		return false;
	}

	if ( 'transport' === $mode ) {
		return true;
	}

	return array() === diluxone_mail_other_mailers();
}

/**
 * The observer-mode notice, on the plugin's screens.
 *
 * It says who is handling delivery and offers to take over. If the mode is
 * 'observe' by hand there is nothing to report: it was a decision.
 */
function diluxone_mail_observer_notice(): void {
	if ( 'auto' !== (string) diluxone_mail_option( 'diluxone_mail_mode' ) ) {
		return;
	}

	$others = diluxone_mail_other_mailers();

	if ( array() === $others ) {
		return;
	}

	$names = implode( ', ', array_column( $others, 'name' ) );
	$url   = wp_nonce_url( admin_url( 'admin-post.php?action=diluxone_mail_take_over' ), 'diluxone_mail_take_over' );
	?>
	<div class="notice notice-info">
		<p>
			<?php
			printf(
				/* translators: %s: names of the detected plugins */
				esc_html__( '%s is handling this site\'s outgoing mail. DiluxOne Mail is logging and diagnosing without touching it.', 'diluxone-mail' ),
				esc_html( $names )
			);
			?>
			<a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Take over', 'diluxone-mail' ); ?></a>
		</p>
	</div>
	<?php
}

/** The "Take over" button: switches the mode to 'transport'. */
function diluxone_mail_take_over(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-mail' ) );
	}

	check_admin_referer( 'diluxone_mail_take_over' );

	diluxone_mail_save_options( array( 'diluxone_mail_mode' => 'transport' ) );

	wp_safe_redirect( diluxone_mail_admin_url( 'diluxone-mail', array( 'diluxone_mail_done' => 'took-over' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_mail_take_over', 'diluxone_mail_take_over' );
