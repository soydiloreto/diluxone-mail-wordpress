<?php
/**
 * Minimal WordPress function stubs for unit testing.
 *
 * These stubs provide basic implementations of WordPress functions
 * that DTOs and helpers use. For hook functions (add_action, add_filter, etc.)
 * use Brain Monkey instead of stubs.
 */

// ── Sanitization functions ──────────────────────────────────────

if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field(string $str): string {
		return trim(strip_tags($str));
	}
}

if (!function_exists('absint')) {
	function absint($maybeint): int {
		return abs((int) $maybeint);
	}
}

if (!function_exists('esc_url_raw')) {
	function esc_url_raw(string $url): string {
		return filter_var($url, FILTER_SANITIZE_URL) ?: '';
	}
}

// ── Site / URL functions ────────────────────────────────────────
// Backed by $GLOBALS['_test_wp_url_base'] so tests can override the host.

if (!function_exists('_test_wp_url_base')) {
	function _test_wp_url_base(): string {
		return $GLOBALS['_test_wp_url_base'] ?? 'http://localhost';
	}
}

if (!function_exists('site_url')) {
	function site_url(string $path = '', ?string $scheme = null): string {
		$base = _test_wp_url_base();
		return $path ? $base . '/' . ltrim($path, '/') : $base;
	}
}

if (!function_exists('home_url')) {
	function home_url(string $path = '', ?string $scheme = null): string {
		return site_url($path, $scheme);
	}
}

if (!function_exists('untrailingslashit')) {
	function untrailingslashit(string $s): string {
		return rtrim($s, '/');
	}
}

if (!function_exists('current_time')) {
	function current_time(string $type, $gmt = 0) {
		return $type === 'timestamp' || $type === 'U' ? time() : gmdate('Y-m-d H:i:s');
	}
}

if (!function_exists('wp_parse_url')) {
	function wp_parse_url(string $url, int $component = -1) {
		return parse_url($url, $component);
	}
}

// ── Escaping functions ──────────────────────────────────────────

if (!function_exists('esc_html')) {
	function esc_html(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_attr')) {
	function esc_attr(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_url')) {
	function esc_url(string $url): string {
		return filter_var($url, FILTER_SANITIZE_URL) ?: '';
	}
}

// ── i18n functions ──────────────────────────────────────────────

if (!function_exists('__')) {
	function __(string $text, string $domain = 'default'): string {
		return $text;
	}
}

if (!function_exists('_e')) {
	function _e(string $text, string $domain = 'default'): void {
		echo $text;
	}
}

// ── Utility functions ───────────────────────────────────────────

if (!function_exists('wp_parse_args')) {
	function wp_parse_args($args, array $defaults = []): array {
		if (is_object($args)) {
			$parsed = get_object_vars($args);
		} elseif (is_array($args)) {
			$parsed = $args;
		} else {
			parse_str((string) $args, $parsed);
		}
		return array_merge($defaults, $parsed);
	}
}

if (!function_exists('did_action')) {
	function did_action(string $hook_name): int {
		return 0;
	}
}

if (!function_exists('apply_filters')) {
	function apply_filters(string $hook_name, $value, ...$args) {
		if (!isset($GLOBALS['wp_filter'][$hook_name])) return $value;
		$cbs = $GLOBALS['wp_filter'][$hook_name]->callbacks; ksort($cbs);
		foreach ($cbs as $list) foreach ($list as $cb) {
			$value = call_user_func_array($cb['function'], array_slice(array_merge([$value], $args), 0, max(1, $cb['accepted_args'])));
		}
		return $value;
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, int $options = 0, int $depth = 512) {
		return json_encode($data, $options, $depth);
	}
}

// ── Options API (backed by $GLOBALS so tests can set/reset state) ──

if (!function_exists('get_option')) {
	function get_option(string $option, $default = false) {
		$store = $GLOBALS['_test_wp_options'] ?? [];
		return array_key_exists($option, $store) ? $store[$option] : $default;
	}
}

if (!function_exists('update_option')) {
	function update_option(string $option, $value, $autoload = null): bool {
		if (!isset($GLOBALS['_test_wp_options'])) {
			$GLOBALS['_test_wp_options'] = [];
		}
		$GLOBALS['_test_wp_options'][$option] = $value;
		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option(string $option): bool {
		if (isset($GLOBALS['_test_wp_options'][$option])) {
			unset($GLOBALS['_test_wp_options'][$option]);
		}
		return true;
	}
}

// ── Sanitization (cont.) ────────────────────────────────────────
//
// Los hooks (add_action / add_filter) NO se stubean: los provee Brain Monkey
// dentro de cada test, y un stub acá lo taparía — los tests que verifican en
// qué hook y con qué prioridad se registra algo pasarían a fallar siempre.

if (!function_exists('sanitize_key')) {
	function sanitize_key(string $key): string {
		return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
	}
}

// ── User meta en memoria ────────────────────────────────────────
//
// Suficiente para ejercitar la lógica de tokens de acceso sin base de datos.

if (!isset($GLOBALS['cst_test_user_meta'])) {
	$GLOBALS['cst_test_user_meta'] = [];
}

if (!function_exists('get_user_meta')) {
	function get_user_meta(int $user_id, string $key, bool $single = false) {
		return $GLOBALS['cst_test_user_meta'][$user_id][$key] ?? '';
	}
}

if (!function_exists('update_user_meta')) {
	function update_user_meta(int $user_id, string $key, $value): bool {
		$GLOBALS['cst_test_user_meta'][$user_id][$key] = $value;
		return true;
	}
}

if (!function_exists('delete_user_meta')) {
	function delete_user_meta(int $user_id, string $key): bool {
		unset($GLOBALS['cst_test_user_meta'][$user_id][$key]);
		return true;
	}
}

if (!function_exists('wp_hash')) {
	// El wp_hash real usa las sales del sitio. Para el test alcanza con que sea
	// determinista y de una sola dirección.
	function wp_hash(string $data): string {
		return hash_hmac('md5', $data, 'clave-de-prueba');
	}
}

if (!function_exists('wp_generate_password')) {
	function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string {
		$alfabeto = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$salida = '';
		for ($i = 0; $i < $length; $i++) {
			$salida .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
		}
		return $salida;
	}
}

if (!function_exists('add_query_arg')) {
	function add_query_arg(array $args, string $url): string {
		return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
	}
}

if (!function_exists('add_shortcode')) {
	function add_shortcode(string $tag, $callback): void {}
}

if (!function_exists('is_user_logged_in')) {
	function is_user_logged_in(): bool { return false; }
}

// ── Usuarios y contraseñas ────────────────────────────────────────────────
// Los tests que miran la política del segundo factor necesitan una persona con
// roles, y los códigos de respaldo necesitan hashear y comprobar. Se resuelven
// con lo mínimo: un array de usuarios y un hash de verdad, no uno de mentira,
// para que la prueba de «no se guarda en claro» signifique algo.

if (!isset($GLOBALS['_test_wp_users'])) {
	$GLOBALS['_test_wp_users'] = [];
}

if (!function_exists('get_userdata')) {
	function get_userdata(int $user_id) {
		return $GLOBALS['_test_wp_users'][$user_id] ?? false;
	}
}

if (!function_exists('wp_hash_password')) {
	function wp_hash_password(string $password): string {
		return password_hash($password, PASSWORD_DEFAULT);
	}
}

if (!function_exists('wp_check_password')) {
	function wp_check_password(string $password, string $hash, int $user_id = 0): bool {
		return password_verify($password, $hash);
	}
}

if (!function_exists('wp_rand')) {
	function wp_rand(int $min = 0, int $max = 0): int {
		return random_int($min, $max);
	}
}

// Una WP_User mínima. El código de producción comprueba `instanceof WP_User`
// antes de leer roles, que es lo correcto; sin esta clase los tests pasarían
// por el camino de «no existe» y no probarían nada.
if (!class_exists('WP_User')) {
	class WP_User {
		public $ID = 0;
		public $roles = [];
		public $user_email = '';
		public $user_login = '';
		public $first_name = '';
		public $last_name = '';
		public $display_name = '';
		public $user_registered = '2020-01-01 00:00:00';

		public function __construct(int $id = 0, array $roles = []) {
			$this->ID = $id;
			$this->roles = $roles;
		}
	}
}

// ── Transients ────────────────────────────────────────────────────────────
// Los desafíos de las passkeys viven acá: son de un solo uso y de vida corta,
// que es exactamente lo que hace un transient.

if (!isset($GLOBALS['_test_wp_transients'])) {
	$GLOBALS['_test_wp_transients'] = [];
}

if (!function_exists('set_transient')) {
	function set_transient(string $key, $value, int $expiration = 0): bool {
		$GLOBALS['_test_wp_transients'][$key] = $value;
		return true;
	}
}

if (!function_exists('get_transient')) {
	function get_transient(string $key) {
		return $GLOBALS['_test_wp_transients'][$key] ?? false;
	}
}

if (!function_exists('delete_transient')) {
	function delete_transient(string $key): bool {
		unset($GLOBALS['_test_wp_transients'][$key]);
		return true;
	}
}

if (!function_exists('wp_list_pluck')) {
	function wp_list_pluck($list, string $field): array {
		$out = array();

		foreach ((array) $list as $key => $row) {
			$out[$key] = is_object($row) ? $row->$field : $row[$field];
		}

		return $out;
	}
}

if (!function_exists('wp_unslash')) {
	function wp_unslash($value) {
		return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
	}
}

// ── Red (multisitio) en memoria ──────────────────────────────────
//
// is_multisite() contesta lo que diga el test, y las options de la red
// viven en su propio arreglo. Alcanza para ejercitar la precedencia
// sitio/red sin una red de verdad.

if (!function_exists('is_multisite')) {
	function is_multisite(): bool {
		return (bool) ($GLOBALS['_test_multisite'] ?? false);
	}
}

if (!function_exists('get_current_blog_id')) {
	function get_current_blog_id(): int {
		return (int) ($GLOBALS['_test_blog_id'] ?? 1);
	}
}

if (!function_exists('get_site_option')) {
	function get_site_option(string $option, $default = false) {
		$store = $GLOBALS['_test_wp_site_options'] ?? [];
		return array_key_exists($option, $store) ? $store[$option] : $default;
	}
}

if (!function_exists('update_site_option')) {
	function update_site_option(string $option, $value): bool {
		$GLOBALS['_test_wp_site_options'][$option] = $value;
		return true;
	}
}

if (!function_exists('delete_site_option')) {
	function delete_site_option(string $option): bool {
		unset($GLOBALS['_test_wp_site_options'][$option]);
		return true;
	}
}

if (!function_exists('get_site_transient')) {
	function get_site_transient(string $transient) {
		$store = $GLOBALS['_test_wp_site_transients'] ?? [];
		return array_key_exists($transient, $store) ? $store[$transient] : false;
	}
}

if (!function_exists('set_site_transient')) {
	function set_site_transient(string $transient, $value, int $expiration = 0): bool {
		$GLOBALS['_test_wp_site_transients'][$transient] = $value;
		return true;
	}
}

if (!function_exists('delete_site_transient')) {
	function delete_site_transient(string $transient): bool {
		unset($GLOBALS['_test_wp_site_transients'][$transient]);
		return true;
	}
}

if (!function_exists('is_email')) {
	function is_email($email) {
		return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false ? (string) $email : false;
	}
}

if (!function_exists('sanitize_email')) {
	function sanitize_email(string $email): string {
		return is_email(trim($email)) ? trim($email) : '';
	}
}

if (!function_exists('wp_generate_uuid4')) {
	function wp_generate_uuid4(): string {
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
	}
}

if (!function_exists('wp_normalize_path')) {
	function wp_normalize_path(string $path): string {
		return str_replace('\\', '/', $path);
	}
}

if (!function_exists('trailingslashit')) {
	function trailingslashit(string $value): string {
		return rtrim($value, '/\\') . '/';
	}
}

if (!function_exists('add_query_arg')) {
	function add_query_arg(...$args) {
		return is_array($args[0]) ? ($args[1] ?? '') . '?' . http_build_query($args[0]) : (string) ($args[2] ?? '');
	}
}

if (!function_exists('wp_remote_get')) {
	function wp_remote_get($url, array $args = []) {
		return $GLOBALS['_test_wp_remote_get'] ?? new \WP_Error('http', 'no network in tests');
	}
}

if (!function_exists('wp_remote_retrieve_response_code')) {
	function wp_remote_retrieve_response_code($response) {
		return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
	}
}

if (!function_exists('wp_remote_retrieve_body')) {
	function wp_remote_retrieve_body($response): string {
		return is_array($response) ? (string) ($response['body'] ?? '') : '';
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing): bool {
		return $thing instanceof \WP_Error;
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error {
		public function __construct(public string $code = '', public string $message = '') {}
		public function get_error_message(): string { return $this->message; }
	}
}

if (!defined('WP_PLUGIN_DIR')) {
	define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/diluxone-mail-tests/plugins');
	@mkdir(WP_PLUGIN_DIR, 0777, true);
}
if (!defined('WP_CONTENT_DIR')) {
	define('WP_CONTENT_DIR', dirname(WP_PLUGIN_DIR));
}

// ── Hooks: un sistema mínimo, con la forma de $wp_filter de WordPress ──
//
// Los callbacks se guardan en $GLOBALS['wp_filter'][$hook]->callbacks igual
// que en WordPress, así observer.php puede recorrerlos, y apply_filters()
// los corre de verdad, así los filtros propios del plugin se pueden probar.

if (!class_exists('WP_Hook')) {
	class WP_Hook { public array $callbacks = []; }
}

function _test_hook(string $hook): WP_Hook {
	if (!isset($GLOBALS['wp_filter'][$hook])) { $GLOBALS['wp_filter'][$hook] = new WP_Hook(); }
	return $GLOBALS['wp_filter'][$hook];
}

function _test_hook_id($callback): string {
	if (is_string($callback)) return $callback;
	if ($callback instanceof \Closure) return spl_object_hash($callback);
	if (is_array($callback)) return (is_object($callback[0]) ? spl_object_hash($callback[0]) : $callback[0]) . '::' . $callback[1];
	return spl_object_hash($callback);
}

if (!function_exists('add_filter')) {
	function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool {
		_test_hook($hook)->callbacks[$priority][_test_hook_id($callback)] = ['function' => $callback, 'accepted_args' => $args];
		return true;
	}
}
if (!function_exists('add_action')) {
	function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool { return add_filter($hook, $callback, $priority, $args); }
}
if (!function_exists('remove_filter')) {
	function remove_filter(string $hook, $callback, int $priority = 10): bool {
		unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority][_test_hook_id($callback)]);
		return true;
	}
}
if (!function_exists('remove_action')) {
	function remove_action(string $hook, $callback, int $priority = 10): bool { return remove_filter($hook, $callback, $priority); }
}
if (!function_exists('has_filter')) {
	function has_filter(string $hook, $callback = false): bool { return isset($GLOBALS['wp_filter'][$hook]) && [] !== array_filter($GLOBALS['wp_filter'][$hook]->callbacks); }
}
if (!function_exists('do_action')) {
	function do_action(string $hook, ...$args): void {
		if (!isset($GLOBALS['wp_filter'][$hook])) return;
		$cbs = $GLOBALS['wp_filter'][$hook]->callbacks; ksort($cbs);
		foreach ($cbs as $list) foreach ($list as $cb) call_user_func_array($cb['function'], array_slice($args, 0, $cb['accepted_args']));
	}
}
if (!function_exists('do_action_ref_array')) {
	function do_action_ref_array(string $hook, array $args): void { do_action($hook, ...$args); }
}

if (!function_exists('get_theme_root')) {
	function get_theme_root(): string { return '/tmp/themes'; }
}
if (!function_exists('home_url')) {
	function home_url(string $path = ''): string { return 'https://example.test' . $path; }
}
if (!function_exists('wp_parse_url')) {
	function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('current_time')) {
	function current_time(string $type, $gmt = 0): string { return gmdate('Y-m-d H:i:s'); }
}

if (!function_exists('sanitize_textarea_field')) {
	function sanitize_textarea_field(string $str): string {
		return trim(strip_tags($str));
	}
}


// ── Lo que usan las pantallas y los comandos ──────────────────────
if (!function_exists('_x')) { function _x(string $t, string $c, string $d = 'default'): string { return $t; } }
if (!function_exists('_n')) { function _n(string $s, string $p, int $n, string $d = 'default'): string { return 1 === $n ? $s : $p; } }
if (!function_exists('esc_html__')) { function esc_html__(string $t, string $d = 'default'): string { return esc_html($t); } }
if (!function_exists('esc_html_e')) { function esc_html_e(string $t, string $d = 'default'): void { echo esc_html($t); } }
if (!function_exists('esc_attr__')) { function esc_attr__(string $t, string $d = 'default'): string { return esc_attr($t); } }
if (!function_exists('esc_attr_e')) { function esc_attr_e(string $t, string $d = 'default'): void { echo esc_attr($t); } }
if (!function_exists('admin_url')) { function admin_url(string $p = ''): string { return 'https://example.test/wp-admin/' . ltrim($p, '/'); } }
if (!function_exists('network_admin_url')) { function network_admin_url(string $p = ''): string { return 'https://example.test/wp-admin/network/' . ltrim($p, '/'); } }
if (!function_exists('wp_nonce_url')) { function wp_nonce_url(string $u, $a = -1): string { return $u . (str_contains($u, '?') ? '&' : '?') . '_wpnonce=test'; } }
if (!function_exists('wp_get_environment_type')) { function wp_get_environment_type(): string { return $GLOBALS['_test_env_type'] ?? 'local'; } }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled(string $h) { return $GLOBALS['_test_cron'][$h] ?? false; } }
if (!function_exists('wp_schedule_event')) { function wp_schedule_event(int $t, string $r, string $h): bool { $GLOBALS['_test_cron'][$h] = $t; return true; } }
if (!function_exists('wp_unschedule_event')) { function wp_unschedule_event(int $t, string $h): bool { unset($GLOBALS['_test_cron'][$h]); return true; } }
if (!function_exists('get_bloginfo')) { function get_bloginfo(string $k = ''): string { return 'Sitio de prueba'; } }
if (!function_exists('wp_specialchars_decode')) { function wp_specialchars_decode(string $s, $q = ENT_NOQUOTES): string { return html_entity_decode($s, ENT_QUOTES); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(): int { return (int) ($GLOBALS['_test_user_id'] ?? 1); } }
if (!function_exists('wp_get_current_user')) { function wp_get_current_user(): object { return (object) ['ID' => get_current_user_id(), 'user_email' => 'admin@example.test']; } }
if (!function_exists('current_user_can')) { function current_user_can(string $cap, ...$a): bool { return (bool) ($GLOBALS['_test_can'] ?? true); } }
if (!function_exists('get_current_screen')) { function get_current_screen() { return null; } }
if (!function_exists('is_network_admin')) { function is_network_admin(): bool { return false; } }
if (!function_exists('is_super_admin')) { function is_super_admin(): bool { return true; } }
if (!function_exists('get_site')) { function get_site(int $id) { return null; } }
if (!function_exists('human_time_diff')) { function human_time_diff(int $a, int $b = 0): string { return 'un rato'; } }
if (!function_exists('get_date_from_gmt')) { function get_date_from_gmt(string $s, string $f = 'Y-m-d H:i:s'): string { return $s; } }
if (!function_exists('gmdate_i18n')) { function gmdate_i18n(string $f): string { return gmdate($f); } }
