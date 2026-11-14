<?php
define('ABSPATH', '/');
define('DOING_AJAX', true);
class JsonResponse extends Exception {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
class A1FI_Queue { public static function jobs_table() { return 'wp_a1fi_jobs'; } }
class A1FI_Plugin { const OPTION = 'a1fi_feeds'; }
class A1FI_Import_Logger {
    public static function render_viewer() { echo '<section id="a1fi-import-logs">' . (int) $_GET['a1fi_log_feed'] . '</section>'; }
}
class A1FI_Job_Error_Viewer {
    public static function render() { echo '<section id="a1fi-job-error-details">Errors</section>'; }
}
class FakeDb {
    public $last_error = '';
    public function get_results($sql) {
        $this->last_error = $GLOBALS['database_error'] ?? '';
        return [];
    }
}
function is_admin() { return true; }
function sanitize_key($value) { return $value; }
function current_user_can($cap) { return $GLOBALS['allowed']; }
function check_ajax_referer($action) { $GLOBALS['nonce_action'] = $action; }
function get_option($key, $default) { return []; }
function wp_create_nonce($action) { return $action; }
function wp_json_encode($value) { return json_encode($value); }
function absint($value) { return abs((int) $value); }
function __($value, $domain = null) { return $value; }
function esc_html__($value, $domain = null) { return $value; }
function esc_html_e($value, $domain = null) { echo $value; }
function esc_js($value) { return addslashes($value); }
function wp_send_json_success($data) { throw new JsonResponse($data, 200); }
function wp_send_json_error($data, $status) { throw new JsonResponse($data, $status); }
require __DIR__ . '/../includes/class-a1fi-job-admin.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function refresh_monitor() {
    try { A1FI_Job_Admin::refresh_monitor(); } catch (JsonResponse $response) { return $response; }
    throw new RuntimeException('No response');
}
$GLOBALS['wpdb'] = new FakeDb();
$_POST = ['action' => 'a1fi_refresh_monitor', 'feed_id' => '7', 'job_id' => '8'];
$GLOBALS['allowed'] = false;
check(refresh_monitor()->status === 403, 'Unauthorized monitoring request was allowed');
$GLOBALS['allowed'] = true;
$response = refresh_monitor();
check($response->status === 200 && strpos($response->data['logs'], '>7</section>') !== false, 'Log feed filter was not retained');
check(strpos($response->data['errors'], 'a1fi-job-error-details') !== false, 'Error details missing');
check(strpos($response->data['jobs'], 'a1fi-jobs-template') !== false, 'Jobs table missing');
check($GLOBALS['nonce_action'] === 'a1fi_refresh_monitor', 'Monitoring nonce not checked');
$GLOBALS['database_error'] = 'database failed';
$response = refresh_monitor();
check($response->status === 500 && strpos($response->data, 'database failed') !== false, 'Monitoring database failure was hidden');
echo "Monitoring endpoint checks passed.\n";
