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
// Hooks (add_action / add_filter) are NOT stubbed: Brain Monkey provides them
// inside each test, and a stub here would shadow that — the tests that check
// which hook and which priority something registers on would start failing
// every time.

if (!function_exists('sanitize_key')) {
	function sanitize_key(string $key): string {
		return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
	}
}

// ── In-memory user meta ─────────────────────────────────────────
//
// Enough to exercise the access-token logic without a database.

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
	// The real wp_hash uses the site's salts. For the test it is enough that it
	// is deterministic and one-way.
	function wp_hash(string $data): string {
		return hash_hmac('md5', $data, 'clave-de-prueba');
	}
}

if (!function_exists('wp_generate_password')) {
	function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string {
		$alfabeto = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out = '';
		for ($i = 0; $i < $length; $i++) {
			$out .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
		}
		return $out;
	}
}

if (!function_exists('add_query_arg')) {
	function add_query_arg(...$a): string {
		// WordPress's two signatures: (array $args, $url) and ($key, $value, $url).
		$args = is_array($a[0]) ? $a[0] : [(string) $a[0] => $a[1] ?? ''];
		$url  = (string) (is_array($a[0]) ? ($a[1] ?? '') : ($a[2] ?? ''));
		return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
	}
}

if (!function_exists('add_shortcode')) {
	function add_shortcode(string $tag, $callback): void {}
}

if (!function_exists('is_user_logged_in')) {
	function is_user_logged_in(): bool { return false; }
}

// ── Users and passwords ───────────────────────────────────────────────────
// The tests that look at the second-factor policy need a person with roles, and
// the backup codes need hashing and checking. Both are solved with the bare
// minimum: an array of users and a real hash, not a fake one, so that the "it
// is not stored in the clear" test means something.

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
	/**
	 * The salts the credential's key is derived from. Fixed per test run and
	 * changeable, so a test can rotate them and see what that does.
	 */
	function wp_salt(string $scheme = 'auth'): string {
		return ($GLOBALS['_test_salt'] ?? 'sal-de-prueba') . '|' . $scheme;
	}

	function wp_rand(int $min = 0, int $max = 0): int {
		return random_int($min, $max);
	}
}

// A minimal WP_User. Production code checks `instanceof WP_User` before
// reading roles, which is the right thing to do; without this class the tests
// would take the "does not exist" path and prove nothing.
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
// The passkey challenges live here: they are single-use and short-lived, which
// is exactly what a transient does.

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

// ── In-memory network (multisite) ────────────────────────────────
//
// is_multisite() answers whatever the test says, and the network options live
// in their own array. It is enough to exercise the site/network precedence
// without a real network.

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


if (!function_exists('wp_remote_get')) {
	function wp_remote_get($url, array $args = []) {
		$GLOBALS['_test_http'][] = ['method' => 'GET', 'url' => $url, 'args' => $args];

		$answer = $GLOBALS['_test_wp_remote_get'] ?? null;

		// A map keyed by a piece of the URL, for the calls that chain: ask for
		// the accounts, then ask each account for its domains.
		if (is_array($answer) && !isset($answer['response'])) {
			foreach ($answer as $needle => $response) {
				if (false !== strpos($url, (string) $needle)) return $response;
			}

			return new \WP_Error('http', 'no double for ' . $url);
		}

		return $answer ?? new \WP_Error('http', 'no network in tests');
	}
}

if (!function_exists('wp_remote_post')) {
	function wp_remote_post($url, array $args = []) {
		$GLOBALS['_test_http'][] = ['method' => 'POST', 'url' => $url, 'args' => $args];

		return $GLOBALS['_test_wp_remote_post'] ?? new \WP_Error('http', 'no network in tests');
	}
}

if (!function_exists('wp_check_filetype')) {
	function wp_check_filetype($file, $mimes = null): array {
		$ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
		$map = ['pdf' => 'application/pdf', 'txt' => 'text/plain', 'png' => 'image/png'];

		return ['ext' => $ext ?: false, 'type' => $map[$ext] ?? false];
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

if (!function_exists('esc_js')) {
	function esc_js(string $text): string { return addslashes($text); }
}

if (!function_exists('wp_kses')) {
	/**
	 * Enough of it for the tests: the real one strips what is not allowed, and
	 * what the plugin passes through it is markup it built itself.
	 */
	function wp_kses(string $string, $allowed = [], $protocols = []): string {
		return $string;
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing): bool {
		return $thing instanceof \WP_Error;
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error {
		/** @param mixed $data */
		public function __construct(public string $code = '', public string $message = '', public $data = '') {}
		public function get_error_message(): string { return $this->message; }
		/** @return mixed */
		public function get_error_data() { return $this->data; }
	}
}

if (!defined('WP_PLUGIN_DIR')) {
	define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/diluxone-mail-tests/plugins');
	@mkdir(WP_PLUGIN_DIR, 0777, true);
}
if (!defined('WP_CONTENT_DIR')) {
	define('WP_CONTENT_DIR', dirname(WP_PLUGIN_DIR));
}

// ── Hooks: a minimal system, shaped like WordPress's $wp_filter ──
//
// Callbacks are stored in $GLOBALS['wp_filter'][$hook]->callbacks just as in
// WordPress, so observer.php can walk them, and apply_filters() really runs
// them, so the plugin's own filters can be tested.

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
if (!function_exists('remove_all_filters')) {
	function remove_all_filters(string $hook, $priority = false): bool {
		unset($GLOBALS['wp_filter'][$hook]);
		return true;
	}
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
	/**
	 * With a callback it answers about that one, like the real has_filter():
	 * code that restores its own hook asks precisely that question, and a
	 * double that answers "somebody is on this hook" tests nothing.
	 */
	function has_filter(string $hook, $callback = false): bool {
		if (!isset($GLOBALS['wp_filter'][$hook])) return false;
		$callbacks = array_filter($GLOBALS['wp_filter'][$hook]->callbacks);
		if (false === $callback) return [] !== $callbacks;
		$id = _test_hook_id($callback);
		foreach ($callbacks as $list) { if (isset($list[$id])) return true; }
		return false;
	}
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


// ── What the screens and the commands use ────────────────────────
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
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook(string $h): int { $GLOBALS['_test_wp_cleared_hooks'][] = $h; unset($GLOBALS['_test_cron'][$h]); return 1; } }
if (!function_exists('get_bloginfo')) { function get_bloginfo(string $k = ''): string { return 'Sitio de prueba'; } }
if (!function_exists('wp_specialchars_decode')) { function wp_specialchars_decode(string $s, $q = ENT_NOQUOTES): string { return html_entity_decode($s, ENT_QUOTES); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(): int { return (int) ($GLOBALS['_test_user_id'] ?? 1); } }
if (!function_exists('wp_get_current_user')) { function wp_get_current_user(): object { return (object) ['ID' => get_current_user_id(), 'user_email' => 'admin@example.test']; } }
if (!function_exists('current_user_can')) { function current_user_can(string $cap, ...$a): bool { return (bool) ($GLOBALS['_test_can'] ?? true); } }
if (!function_exists('is_network_admin')) { function is_network_admin(): bool { return false; } }
if (!function_exists('is_super_admin')) { function is_super_admin(): bool { return true; } }
if (!function_exists('human_time_diff')) { function human_time_diff(int $a, int $b = 0): string { return 'un rato'; } }
if (!function_exists('get_date_from_gmt')) { function get_date_from_gmt(string $s, string $f = 'Y-m-d H:i:s'): string { return $s; } }
if (!function_exists('gmdate_i18n')) { function gmdate_i18n(string $f): string { return gmdate($f); } }
