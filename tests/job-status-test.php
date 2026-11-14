<?php
define('ABSPATH', '/');
class JsonResponse extends Exception {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
class A1FI_Queue { public static function jobs_table() { return 'wp_a1fi_jobs'; } }
class FakeDb {
    public $job;
    public function prepare($sql, $id) { return sprintf($sql, $id); }
    public function get_row($sql) { return $this->job; }
}
function current_user_can($cap) { return $GLOBALS['allowed']; }
function check_ajax_referer($action) { $GLOBALS['nonce_action'] = $action; }
function absint($value) { return abs((int) $value); }
function __($text, $domain = null) { return $text; }
function wp_send_json_success($data) { throw new JsonResponse($data, 200); }
function wp_send_json_error($data, $status) { throw new JsonResponse($data, $status); }
require __DIR__ . '/../includes/class-a1fi-job-admin.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function request_status() {
    try { A1FI_Job_Admin::job_status(); } catch (JsonResponse $response) { return $response; }
    throw new RuntimeException('No response');
}
$GLOBALS['wpdb'] = new FakeDb();
$_POST['job_id'] = '8';
$GLOBALS['allowed'] = false;
check(request_status()->status === 403, 'Unauthorized user was allowed');
$GLOBALS['allowed'] = true;
check(request_status()->status === 404, 'Missing job was not reported');
$GLOBALS['wpdb']->job = (object) [
    'id' => '8', 'status' => 'running', 'total_rows' => '20', 'processed_rows' => '3',
    'created_count' => '2', 'updated_count' => '1', 'skipped_count' => '0',
    'failed_count' => '0', 'message' => null, 'errors' => '["error"]',
];
$response = request_status();
check($GLOBALS['nonce_action'] === 'a1fi_job_status', 'Status nonce was not checked');
check($response->status === 200 && $response->data['processed'] === 3 && $response->data['total'] === 20 && $response->data['errors'] === ['error'], 'Incorrect progress response');
echo "Job status checks passed.\n";
