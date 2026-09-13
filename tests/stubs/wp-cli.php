<?php
/**
 * WP-CLI de mentira: anota lo que se imprime, y error() lanza en vez de salir.
 */
namespace {
	if (!defined('WP_CLI')) { define('WP_CLI', true); }

	class DiluxOne_Test_CLI_Error extends \RuntimeException {}

	if (!class_exists('WP_CLI')) {
		class WP_CLI {
			public static array $out = [];
			public static array $commands = [];
			public static function log($m): void { self::$out[] = (string) $m; }
			public static function line($m = ''): void { self::$out[] = (string) $m; }
			public static function success($m): void { self::$out[] = 'Success: ' . $m; }
			public static function warning($m): void { self::$out[] = 'Warning: ' . $m; }
			public static function error($m): void { throw new DiluxOne_Test_CLI_Error((string) $m); }
			public static function add_command(string $name, $callable, array $args = []): void { self::$commands[$name] = $callable; }
			public static function reset(): void { self::$out = []; }
		}
	}
}

namespace WP_CLI\Utils {
	if (!function_exists('WP_CLI\Utils\format_items')) {
		function format_items(string $format, array $items, $fields): void {
			$GLOBALS['_test_cli_items'][] = ['format' => $format, 'items' => $items, 'fields' => $fields];
			\WP_CLI::log('json' === $format ? (string) json_encode($items) : count($items) . ' items');
		}
	}
}
