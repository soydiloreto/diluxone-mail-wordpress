<?php
/**
 * A fake wp_mail(), deliberately under a path containing /wp-includes/:
 * observer.php decides that "somebody replaced wp_mail()" by looking at the
 * file it is defined in, and this one has to pass as WordPress's own.
 *
 * It reproduces the real sequence: the wp_mail filter, pre_wp_mail, and then
 * wp_mail_succeeded or wp_mail_failed depending on what the test says.
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
