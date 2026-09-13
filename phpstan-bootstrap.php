<?php
/**
 * PHPStan analysis bootstrap.
 *
 * Defines plugin constants that are normally created at runtime by the
 * main plugin file (diluxone-mail.php). PHPStan analyzes the
 * codebase statically without executing anything, so it never sees the
 * `define()` calls there. Without these stubs, every reference to
 * `DILUXONE_MAIL_DIR` and friends produces "Constant not found".
 *
 * This file is referenced from phpstan.neon's `bootstrapFiles:` list.
 * It is excluded from the wp.org deploy via .distignore. It is NOT
 * loaded at plugin runtime — only by PHPStan during analysis.
 *
 * @package DiluxOneMail
 */

if ( ! defined( 'DILUXONE_MAIL_VERSION' ) ) {
	define( 'DILUXONE_MAIL_VERSION', '0.0.0-phpstan-stub' );
}
if ( ! defined( 'DILUXONE_MAIL_DIR' ) ) {
	define( 'DILUXONE_MAIL_DIR', __DIR__ . '/' );
}
if ( ! defined( 'DILUXONE_MAIL_URL' ) ) {
	define( 'DILUXONE_MAIL_URL', 'https://example.test/wp-content/plugins/diluxone-mail/' );
}
if ( ! defined( 'DILUXONE_MAIL_FILE' ) ) {
	define( 'DILUXONE_MAIL_FILE', __DIR__ . '/diluxone-mail.php' );
}

// Constantes que WordPress define en tiempo de ejecución y que el análisis
// estático no ve porque salen de wp-includes/default-constants.php.
if ( ! defined( 'COOKIEHASH' ) ) {
	define( 'COOKIEHASH', 'phpstan' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
