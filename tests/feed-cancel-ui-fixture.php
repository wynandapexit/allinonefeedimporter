<?php
define('ABSPATH', '/');
class A1FI_Queue { public static function jobs_table() { return 'wp_a1fi_jobs'; } }
class A1FI_Plugin { const OPTION = 'a1fi_feeds'; }
class FakeDb {
    public function get_results($sql) { return [(object) ['feed_id' => 5, 'active_count' => 1]]; }
}
function is_admin() { return true; }
function sanitize_key($value) { return $value; }
function current_user_can($cap) { return true; }
function get_option($key, $default) { return [5 => ['name' => 'Feed']]; }
function wp_create_nonce($action) { return $action; }
function wp_json_encode($value) { return json_encode($value); }
function __($value, $domain = null) { return $value; }
function esc_html_e($value, $domain = null) { echo $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_js($value) { return addslashes($value); }
$_GET = ['page' => 'a1fi'];
$GLOBALS['wpdb'] = new FakeDb();
require __DIR__ . '/../includes/class-a1fi-feed-cancel.php';
A1FI_Feed_Cancel::render_cancel_controls();
