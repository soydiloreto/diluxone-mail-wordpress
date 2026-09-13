<?php
/**
 * A $wpdb that records every query and answers whatever the test sets up.
 *
 * It is enough to test what matters in log.php: which SQL is built, with which
 * placeholders, and what is handed to insert/update/replace. It runs nothing.
 */
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

class DiluxOne_Test_WPDB {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public string $dbname = 'tests';
	/** @var array<int, array{method: string, sql: string, args: mixed}> */
	public array $calls = [];
	/** @var mixed */
	public $next_var = 0;
	/** @var array<int, mixed> */
	public array $next_results = [];
	/** @var mixed */
	public $next_row = null;
	public int $rows_affected = 1;

	public function reset(): void { $this->calls = []; $this->next_var = 0; $this->next_results = []; $this->next_row = null; }
	public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
	public function esc_like(string $s): string { return addcslashes($s, '_%\\'); }
	public function prepare(string $sql, ...$args): string {
		$args = 1 === count($args) && is_array($args[0]) ? $args[0] : $args;
		$i = 0;
		return preg_replace_callback('/%[idsfi]/', function ($m) use (&$i, $args) {
			$v = $args[$i++] ?? '';
			return 'i' === $m[0][1] ? '`' . $v . '`' : ('%d' === $m[0] ? (string) (int) $v : "'" . addslashes((string) $v) . "'");
		}, $sql);
	}
	public function get_var(string $sql) { $this->calls[] = ['method' => 'get_var', 'sql' => $sql, 'args' => null]; return $this->next_var; }
	public function get_results(string $sql, $out = OBJECT) { $this->calls[] = ['method' => 'get_results', 'sql' => $sql, 'args' => null]; return $this->next_results; }
	public function get_row(string $sql, $out = OBJECT) { $this->calls[] = ['method' => 'get_row', 'sql' => $sql, 'args' => null]; return $this->next_row; }
	public function get_col(string $sql) { $this->calls[] = ['method' => 'get_col', 'sql' => $sql, 'args' => null]; return []; }
	public function query(string $sql) { $this->calls[] = ['method' => 'query', 'sql' => $sql, 'args' => null]; return $this->rows_affected; }
	public function insert(string $t, array $d, $f = null) { $this->calls[] = ['method' => 'insert', 'sql' => $t, 'args' => $d]; return 1; }
	public function update(string $t, array $d, array $w, $f = null, $wf = null) { $this->calls[] = ['method' => 'update', 'sql' => $t, 'args' => ['data' => $d, 'where' => $w]]; return 1; }
	public function replace(string $t, array $d, $f = null) { $this->calls[] = ['method' => 'replace', 'sql' => $t, 'args' => $d]; return 1; }
	/** @return array<int, array{method: string, sql: string, args: mixed}> */
	public function of(string $method): array { return array_values(array_filter($this->calls, fn($c) => $c['method'] === $method)); }
}
