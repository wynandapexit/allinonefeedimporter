<?php
define('ABSPATH', '/');
class A1FI_Queue { public static function jobs_table() { return 'wp_a1fi_jobs'; } }
class A1FI_Plugin { const OPTION = 'a1fi_feeds'; }
class FakeDb {
    public function get_results($sql) {
        return [(object) [
            'id' => 8, 'feed_id' => 1, 'status' => 'running', 'total_rows' => 20,
            'processed_rows' => 3, 'created_count' => 2, 'updated_count' => 1,
            'skipped_count' => 0, 'failed_count' => 0, 'started_at' => '2026-11-14',
        ]];
    }
}
function is_admin() { return true; }
function sanitize_key($value) { return $value; }
function current_user_can($cap) { return true; }
function get_option($key, $default) { return []; }
function wp_create_nonce($action) { return $action; }
function wp_json_encode($value) { return json_encode($value); }
function absint($value) { return abs((int) $value); }
function __($value, $domain = null) { return $value; }
function esc_html__($value, $domain = null) { return $value; }
function esc_html_e($value, $domain = null) { echo $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_js($value) { return addslashes($value); }
function esc_url($value) { return $value; }
function admin_url($value) { return $value; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
$_GET = ['page' => 'a1fi', 'a1fi_job' => '8'];
$GLOBALS['wpdb'] = new FakeDb();
require __DIR__ . '/../includes/class-a1fi-job-admin.php';
A1FI_Job_Admin::render_jobs_ui();
