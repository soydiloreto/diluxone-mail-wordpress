<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads WordPress stubs so plugin code can be exercised without booting
 * WordPress. Nothing here touches the database or the running dev stack.
 */

// Composer autoload (PHPUnit, Brain Monkey, Mockery)
require_once __DIR__ . '/../vendor/autoload.php';

// Define WordPress constants that plugins expect
// ABSPATH apunta a un directorio temporal: lo único que el plugin carga desde
// ahí es wp-admin/includes/upgrade.php, y no tiene por qué aparecer un
// wp-admin/ adentro del repositorio cada vez que corren los tests.
if (!defined('ABSPATH')) {
	define('ABSPATH', sys_get_temp_dir() . '/diluxone-mail-tests/wp/');
	@mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
	@file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n");
}

// Constantes de tiempo de WordPress. Las usa cualquier código que calcule un
// vencimiento, y no dependen de que WordPress esté cargado.
foreach ([
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
] as $cst_const => $cst_valor) {
	if (!defined($cst_const)) {
		define($cst_const, $cst_valor);
	}
}

if (!defined('DILUXONE_MAIL_DIR')) {
	define('DILUXONE_MAIL_DIR', dirname(__DIR__) . '/');
}

if (!defined('DILUXONE_MAIL_URL')) {
	define('DILUXONE_MAIL_URL', 'https://example.test/wp-content/plugins/diluxone-mail/');
}

if (!defined('DILUXONE_MAIL_VERSION')) {
	define('DILUXONE_MAIL_VERSION', '0.0.0-test');
}

// Load WordPress function stubs
require_once __DIR__ . '/stubs/wordpress-stubs.php';
require_once __DIR__ . '/stubs/wp-includes/pluggable.php';
require_once __DIR__ . '/stubs/wpdb.php';
require_once __DIR__ . '/stubs/wp-admin-stubs.php';
require_once __DIR__ . '/stubs/wp-cli.php';
require_once __DIR__ . '/stubs/phpmailer.php';

$GLOBALS['wpdb'] = new DiluxOne_Test_WPDB();

// dbDelta() vive en wp-admin; acá alcanza con que exista.
if (!function_exists('dbDelta')) {
	function dbDelta($sql) { $GLOBALS['_test_dbdelta'][] = $sql; return []; }
}

