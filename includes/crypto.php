<?php
/**
 * The SMTP credential, encrypted at rest.
 *
 * AES-256-GCM with a key derived from the site's own WordPress salts, the same
 * scheme DiluxOne Offload uses for its cloud credentials. What it protects
 * against is the credential travelling further than the site: a database dump,
 * a backup on somebody's laptop, a staging copy, a support export. In all of
 * those the salts stay in wp-config.php and the ciphertext is useless.
 *
 * What it does not protect against, and it is worth being plain about it:
 * anything running inside the site. The plugin has to hand the password to
 * PHPMailer to connect, so any code on the site — or anybody who can add
 * code — can ask for it decrypted. Encryption at rest is not a substitute for
 * keeping the credential out of the database altogether, which is what the
 * DILUXONE_MAIL_PASS constant is for and what a serious install should use.
 *
 * Rotating the salts invalidates every stored credential. That is the intended
 * behaviour — the value is tied to this install — and the screen that asks for
 * it says so rather than failing at the next send.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tag an encrypted value carries.
 *
 * It is what tells an encrypted value from one stored before this existed, and
 * it is versioned so a future change of scheme can recognise the old one.
 */
const DILUXONE_MAIL_CRYPTO_PREFIX = 'DILUXONEMAILENC1:';

const DILUXONE_MAIL_CRYPTO_CIPHER  = 'aes-256-gcm';
const DILUXONE_MAIL_CRYPTO_IV_LEN  = 12;
const DILUXONE_MAIL_CRYPTO_TAG_LEN = 16;

/** Can this PHP do it at all? */
function diluxone_mail_crypto_available(): bool {
	return function_exists( 'openssl_encrypt' )
		&& function_exists( 'openssl_decrypt' )
		&& function_exists( 'random_bytes' )
		&& in_array( DILUXONE_MAIL_CRYPTO_CIPHER, (array) openssl_get_cipher_methods(), true );
}

/** Does this value carry the tag? */
function diluxone_mail_is_encrypted( string $value ): bool {
	return 0 === strncmp( $value, DILUXONE_MAIL_CRYPTO_PREFIX, strlen( DILUXONE_MAIL_CRYPTO_PREFIX ) );
}

/**
 * The key, derived from the salts of this install.
 *
 * Two salts rather than one so that rotating either of them invalidates what
 * was stored, which is the point: a copy of the database taken to another
 * install cannot read the credential even if it also carries wp-config.php
 * from somewhere else.
 */
function diluxone_mail_crypto_key(): string {
	return hash_hmac( 'sha256', 'diluxone-mail-v1', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
}

/**
 * Encrypts a credential.
 *
 * Idempotent, and an empty string stays empty: "there is no password" is not a
 * secret worth a payload.
 *
 * On failure it returns an empty string rather than the plaintext. Storing the
 * credential in the clear because encrypting it did not work is exactly the
 * outcome this function exists to prevent, and a caller that gets '' back is
 * expected to say so instead of writing it.
 */
function diluxone_mail_encrypt( string $plaintext ): string {
	if ( '' === $plaintext || diluxone_mail_is_encrypted( $plaintext ) ) {
		return $plaintext;
	}

	if ( ! diluxone_mail_crypto_available() ) {
		return '';
	}

	$tag    = '';
	$iv     = random_bytes( DILUXONE_MAIL_CRYPTO_IV_LEN );
	$cipher = openssl_encrypt( $plaintext, DILUXONE_MAIL_CRYPTO_CIPHER, diluxone_mail_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, '', DILUXONE_MAIL_CRYPTO_TAG_LEN );

	if ( false === $cipher ) {
		return '';
	}

	return DILUXONE_MAIL_CRYPTO_PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Nothing is being obfuscated: ciphertext is binary and has to survive a text column.
}

/**
 * Decrypts a credential.
 *
 * Null means "this cannot be recovered" — a rotated salt, a truncated value, a
 * PHP without openssl — and is not the same as an empty password. The caller
 * has to tell the difference, because one of them asks the person to type the
 * credential again and the other does not.
 */
function diluxone_mail_decrypt( string $ciphertext ): ?string {
	if ( ! diluxone_mail_is_encrypted( $ciphertext ) || ! diluxone_mail_crypto_available() ) {
		return null;
	}

	$payload = base64_decode( substr( $ciphertext, strlen( DILUXONE_MAIL_CRYPTO_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See above.

	if ( false === $payload || strlen( $payload ) < DILUXONE_MAIL_CRYPTO_IV_LEN + DILUXONE_MAIL_CRYPTO_TAG_LEN ) {
		return null;
	}

	$plain = openssl_decrypt(
		substr( $payload, DILUXONE_MAIL_CRYPTO_IV_LEN + DILUXONE_MAIL_CRYPTO_TAG_LEN ),
		DILUXONE_MAIL_CRYPTO_CIPHER,
		diluxone_mail_crypto_key(),
		OPENSSL_RAW_DATA,
		substr( $payload, 0, DILUXONE_MAIL_CRYPTO_IV_LEN ),
		substr( $payload, DILUXONE_MAIL_CRYPTO_IV_LEN, DILUXONE_MAIL_CRYPTO_TAG_LEN )
	);

	return false === $plain ? null : $plain;
}

/**
 * The stored credential, whatever shape it is in.
 *
 * Three answers, and they are not the same thing: the password, an empty
 * string when there is none, and null when there is one and it cannot be read.
 */
function diluxone_mail_stored_password( string $stored ): ?string {
	if ( '' === $stored ) {
		return '';
	}

	return diluxone_mail_is_encrypted( $stored ) ? diluxone_mail_decrypt( $stored ) : $stored;
}

/**
 * Encrypts a credential that was stored before this existed.
 *
 * Runs on admin_init, where it is one read of an autoloaded option, and writes
 * only once: after it, the value carries the tag and is left alone.
 */
function diluxone_mail_encrypt_stored_password(): void {
	if ( ! diluxone_mail_crypto_available() ) {
		return;
	}

	foreach ( array( 'site', 'network' ) as $scope ) {
		$stored = 'network' === $scope ? get_site_option( 'diluxone_mail_pass', '' ) : get_option( 'diluxone_mail_pass', '' );
		$stored = is_string( $stored ) ? $stored : '';

		if ( '' === $stored || diluxone_mail_is_encrypted( $stored ) ) {
			continue;
		}

		$encrypted = diluxone_mail_encrypt( $stored );

		if ( '' === $encrypted ) {
			continue;
		}

		if ( 'network' === $scope ) {
			update_site_option( 'diluxone_mail_pass', $encrypted );
			continue;
		}

		update_option( 'diluxone_mail_pass', $encrypted );
	}
}
add_action( 'admin_init', 'diluxone_mail_encrypt_stored_password' );
