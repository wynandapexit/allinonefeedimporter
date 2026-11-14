<?php
define('ABSPATH', '/');
define('MINUTE_IN_SECONDS', 60);
class Redirected extends Exception {}
class A1FI_Queue {
    public static function jobs_table() { return 'wp_a1fi_jobs'; }
    public static function rows_table() { return 'wp_a1fi_job_rows'; }
}
class A1FI_Import_Logger {
    public static function table() { return 'wp_a1fi_import_logs'; }
}
class FakeDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows_affected = 0;
    public $has_image_table = false;
    public $fail_at = 0;
    public $queries = [];
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function prepare($sql, $value) { return $sql; }
    public function get_var($sql) { return $this->has_image_table ? 'wp_a1fi_image_queue' : null; }
    public function query($sql) {
        $this->queries[] = $sql;
        if (count($this->queries) === $this->fail_at) { $this->last_error = 'database error'; return false; }
        $this->last_error = '';
        $this->rows_affected = 1;
        return 1;
    }
}
function add_action(...$args) {}
function current_user_can($cap) { return true; }
function check_admin_referer($action) {}
function __($text, $domain = null) { return $text; }
function sanitize_text_field($text) { return $text; }
function get_current_user_id() { return 1; }
function set_transient($key, $value, $expiration) { $GLOBALS['result'] = $value; }
function admin_url($path) { return $path; }
function add_query_arg($key, $value, $url) { return $url; }
function wp_safe_redirect($url) { throw new Redirected(); }
require __DIR__ . '/../includes/class-a1fi-history-cleanup.php';
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function run_cleanup($db) {
    $GLOBALS['wpdb'] = $db;
    try { A1FI_History_Cleanup::clear_history(); } catch (Redirected $e) {}
    return $GLOBALS['result'];
}
$db = new FakeDb();
$result = run_cleanup($db);
check($result['success'] && count($db->queries) === 3, 'Cleanup without a legacy image table failed');
check(strpos($db->queries[0], "j.status NOT IN ('queued','running')") !== false, 'Active rows are not protected');
$db = new FakeDb();
$db->has_image_table = true;
$result = run_cleanup($db);
check($result['success'] && count($db->queries) === 4 && strpos($db->queries[3], "status IN ('completed','failed')") !== false, 'Legacy image cleanup failed');
$db = new FakeDb();
$db->fail_at = 2;
$result = run_cleanup($db);
check(!$result['success'] && count($db->queries) === 2 && strpos($result['error'], 'logs') !== false, 'Cleanup did not stop and report the failing step');
echo "Cleanup checks passed.\n";
