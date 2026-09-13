<?php
/**
 * What the screens, the handlers and the transport need in order to run
 * without WordPress: minimal classes and the functions that end in exit().
 *
 * wp_safe_redirect() and wp_die() throw an exception instead of ending the
 * process: an admin_post handler does its job, redirects and exits, and the
 * exception is how to see where it wanted to go without killing PHPUnit.
 */

class DiluxOne_Test_Redirect extends \RuntimeException {}
class DiluxOne_Test_Die extends \RuntimeException {}

if (!function_exists('wp_safe_redirect')) {
	function wp_safe_redirect(string $url, int $status = 302): void { throw new DiluxOne_Test_Redirect($url); }
}
if (!function_exists('wp_redirect')) {
	function wp_redirect(string $url, int $status = 302): void { throw new DiluxOne_Test_Redirect($url); }
}
if (!function_exists('wp_die')) {
	function wp_die($message = ''): void { throw new DiluxOne_Test_Die((string) $message); }
}
if (!function_exists('check_admin_referer')) {
	function check_admin_referer($action = -1, string $name = '_wpnonce') {
		if (!empty($GLOBALS['_test_nonce_fails'])) { throw new DiluxOne_Test_Die('nonce'); }
		return 1;
	}
}
if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($n, $a = -1) { return empty($GLOBALS['_test_nonce_fails']) ? 1 : false; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a = -1): string { return 'nonce'; } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a = -1, $n = '_wpnonce', $r = true, $e = true): string { $h = '<input type="hidden" name="' . $n . '" value="nonce">'; if ($e) echo $h; return $h; } }
if (!function_exists('wp_get_referer')) { function wp_get_referer() { return $GLOBALS['_test_referer'] ?? false; } }
if (!function_exists('submit_button')) { function submit_button($t = '', $type = 'primary', $n = 'submit', $wrap = true, $o = null): void { echo '<button name="' . esc_attr((string) $n) . '">' . esc_html((string) $t) . '</button>'; } }
if (!function_exists('selected')) { function selected($a, $b = true, $e = true): string { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ($e) echo $r; return $r; } }
if (!function_exists('checked')) { function checked($a, $b = true, $e = true): string { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ($e) echo $r; return $r; } }
if (!function_exists('disabled')) { function disabled($a, $b = true, $e = true): string { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ($e) echo $r; return $r; } }
if (!function_exists('wp_readonly')) { function wp_readonly($a, $b = true, $e = true): string { $r = (string) $a === (string) $b ? ' readonly="readonly"' : ''; if ($e) echo $r; return $r; } }
if (!function_exists('add_menu_page')) { function add_menu_page(...$a): string { $GLOBALS['_test_menu'][] = $a; return 'toplevel_page_' . $a[3]; } }
if (!function_exists('add_submenu_page')) { function add_submenu_page(...$a): string { $GLOBALS['_test_submenu'][] = $a; return $a[0] . '_page_' . $a[3]; } }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style(...$a): void { $GLOBALS['_test_styles'][] = $a; } }
if (!function_exists('get_user_by')) {
	function get_user_by(string $field, $value) {
		$prop = ['email' => 'user_email', 'login' => 'user_login', 'id' => 'ID', 'ID' => 'ID'][$field] ?? $field;
		foreach ($GLOBALS['_test_users'] ?? [] as $u) { if ((string) ($u->$prop ?? '') === (string) $value) return $u; }
		return false;
	}
}
if (!function_exists('get_sites')) { function get_sites(array $a = []): array { return $GLOBALS['_test_sites'] ?? []; } }
if (!function_exists('get_site')) { function get_site(int $id) { foreach ($GLOBALS['_test_sites'] ?? [] as $s) { if ($s->blog_id === $id) return $s; } return null; } }
if (!function_exists('get_current_screen')) { function get_current_screen() { return $GLOBALS['_test_screen'] ?? null; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post(string $s): string { return $s; } }

if (!class_exists('WP_Site')) {
	class WP_Site { public int $blog_id; public string $blogname; public function __construct(int $id, string $name) { $this->blog_id = $id; $this->blogname = $name; } }
}
if (!class_exists('WP_Screen')) {
	class WP_Screen { public string $id = ''; public function __construct(string $id = '') { $this->id = $id; } }
}

/**
 * WP_List_Table, just enough for the log table to paint without WordPress.
 */
if (!class_exists('WP_List_Table')) {
	class WP_List_Table {
		public array $items = [];
		public array $_column_headers = [];
		protected array $_args = [];
		protected array $_pagination_args = [];
		public function __construct(array $args = []) { $this->_args = $args; }
		public function get_pagenum(): int { return max(1, (int) ($_GET['paged'] ?? 1)); }
		protected function set_pagination_args(array $a): void { $this->_pagination_args = $a; }
		public function get_pagination_arg(string $k) { return $this->_pagination_args[$k] ?? null; }
		protected function row_actions(array $actions): string { return '<div class="row-actions">' . implode(' | ', $actions) . '</div>'; }
		public function search_box(string $text, string $id): void { echo '<p class="search-box">' . esc_html($text) . '</p>'; }
		public function display(): void {
			echo '<table class="wp-list-table">';
			$this->extra_tablenav('top');
			foreach ($this->items as $item) {
				echo '<tr>';
				foreach (array_keys($this->_column_headers[0] ?? []) as $col) {
					$m = 'column_' . $col;
					echo '<td>' . (method_exists($this, $m) ? $this->$m($item) : $this->column_default($item, $col)) . '</td>';
				}
				echo '</tr>';
			}
			if ([] === $this->items) { $this->no_items(); }
			echo '</table>';
		}
		protected function extra_tablenav($which): void {}
		protected function column_default($item, $column_name) { return ''; }
		public function no_items(): void { echo 'No items.'; }
	}
}

