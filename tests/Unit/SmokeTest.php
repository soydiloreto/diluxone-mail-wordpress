<?php
/**
 * Smoke test to verify PHPUnit infrastructure works.
 *
 * Validates that PHPUnit, the WordPress stubs and the bootstrap constants
 * are all in place. If this suite fails, nothing else in tests/ is meaningful.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase {

	public function test_phpunit_works(): void {
		$this->assertTrue(true);
	}

	public function test_wordpress_stubs_loaded(): void {
		$this->assertTrue(function_exists('sanitize_text_field'));
		$this->assertTrue(function_exists('esc_html'));
		$this->assertTrue(function_exists('__'));
		$this->assertTrue(function_exists('get_option'));
		$this->assertTrue(function_exists('wp_parse_args'));
		$this->assertTrue(function_exists('apply_filters'));
	}

	public function test_constants_defined(): void {
		$this->assertTrue(defined('ABSPATH'));
		$this->assertTrue(defined('DILUXONE_MAIL_DIR'));
		$this->assertTrue(defined('DILUXONE_MAIL_VERSION'));
	}

	public function test_sanitize_text_field_stub(): void {
		$this->assertSame('hello', sanitize_text_field('  <b>hello</b>  '));
	}

	public function test_esc_html_stub(): void {
		$this->assertSame('&lt;script&gt;', esc_html('<script>'));
	}

	public function test_get_option_stub_returns_default(): void {
		$this->assertFalse(get_option('nonexistent'));
		$this->assertSame('fallback', get_option('nonexistent', 'fallback'));
	}
}
