<?php
/**
 * wp_mail() de mentira, en una ruta con /wp-includes/ a propósito: observer.php
 * decide que «alguien reemplazó wp_mail()» mirando el archivo donde está
 * definida, y ésta tiene que pasar por la de WordPress.
 *
 * Reproduce la secuencia real: filtro wp_mail, pre_wp_mail, y después
 * wp_mail_succeeded o wp_mail_failed según lo que diga el test.
 */
if (!function_exists('wp_mail')) {
	function wp_mail($to, string $subject, string $message, $headers = '', $attachments = []) {
		$atts = apply_filters('wp_mail', compact('to', 'subject', 'message', 'headers', 'attachments'));
		$pre  = apply_filters('pre_wp_mail', null, $atts);
		if (null !== $pre) return $pre;
		$GLOBALS['_test_wp_mail_calls'][] = $atts;
		if (!empty($GLOBALS['_test_wp_mail_fails'])) {
			do_action('wp_mail_failed', new WP_Error('wp_mail_failed', (string) $GLOBALS['_test_wp_mail_fails']));
			return false;
		}
		do_action('wp_mail_succeeded', $atts);
		return true;
	}
}
