<?php
define('ABSPATH', '/');
class JsonResponse extends Exception {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
class A1FI_Queue {
    public static function jobs_table() { return 'wp_a1fi_jobs'; }
    public static function rows_table() { return 'wp_a1fi_job_rows'; }
}
class A1FI_Import_Logger {
    public static function table() { return 'wp_a1fi_import_logs'; }
}
class FakeDb {
    public $last_error = '';
    public $ids = [46];
    public $lookup_error = '';
    public $fail_at = 0;
    public $affected = 1;
    public $deleted_jobs = 1;
    public $queries = [];
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_col($sql) { $this->last_error = $this->lookup_error; return $this->ids; }
    public function query($sql) {
        $this->queries[] = $sql;
        if (count($this->queries) === $this->fail_at) { $this->last_error = 'database error'; return false; }
        if (count($this->queries) === 1) return $this->affected;
        if (count($this->queries) === 5) return $this->deleted_jobs;
        return 1;
    }
}
function current_user_can($cap) { return $GLOBALS['allowed']; }
function check_ajax_referer($action) { $GLOBALS['nonce_action'] = $action; }
function absint($value) { return abs((int) $value); }
function current_time($type) { return '2026-11-14 10:00:00'; }
function __($value, $domain = null) { return $value; }
function get_transient($key) { return !empty($GLOBALS['locks'][$key]); }
function wp_send_json_success($data) { throw new JsonResponse($data, 200); }
function wp_send_json_error($data, $status) { throw new JsonResponse($data, $status); }
require __DIR__ . '/../includes/class-a1fi-feed-cancel.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function cancel($db) {
    $GLOBALS['wpdb'] = $db;
    try { A1FI_Feed_Cancel::cancel_feed_jobs(); } catch (JsonResponse $response) { return $response; }
    throw new RuntimeException('No response');
}
$_POST['feed_id'] = '5';
$GLOBALS['allowed'] = false;
check(cancel(new FakeDb())->status === 403, 'Unauthorized user was allowed');
$GLOBALS['allowed'] = true;
$db = new FakeDb();
$db->lookup_error = 'lookup failed';
check(cancel($db)->status === 500 && !$db->queries, 'Lookup failure was reported as success');
$db = new FakeDb();
$db->fail_at = 1;
check(cancel($db)->status === 500 && count($db->queries) === 1, 'Job update failure was reported as success');
$db = new FakeDb();
$db->affected = 0;
check(cancel($db)->status === 409 && count($db->queries) === 1, 'Unchanged jobs were reported as cancelled');
$db = new FakeDb();
$db->fail_at = 2;
$response = cancel($db);
check($response->status === 500 && strpos($response->data, 'Jobs were cancelled') !== false, 'Partial cancellation was not reported');
$db = new FakeDb();
$response = cancel($db);
check($response->status === 200 && $response->data['cancelled'] === 1 && $response->data['cleared'], 'Cancellation and cleanup failed');
check(strpos($db->queries[1], "j.status='cancelled'") !== false, 'Rows of non-cancelled jobs are not protected');
check(count($db->queries) === 5 && strpos($db->queries[2], 'DELETE r FROM wp_a1fi_job_rows') !== false && strpos($db->queries[3], 'DELETE l FROM wp_a1fi_import_logs') !== false && strpos($db->queries[4], "DELETE FROM wp_a1fi_jobs") !== false, 'Cancelled jobs and logs were not cleared');
foreach (array_slice($db->queries, 2) as $query) {
    check(strpos($query, 'IN (46)') !== false && strpos($query, "status='cancelled'") !== false, 'Cleanup is not limited to the cancelled job');
}
$db = new FakeDb();
$GLOBALS['locks']['a1fi_queue_lock'] = true;
$response = cancel($db);
check($response->status === 200 && !$response->data['cleared'] && count($db->queries) === 2, 'Cleanup ran while the worker was active');
$GLOBALS['locks'] = [];
$db = new FakeDb();
$db->fail_at = 4;
$response = cancel($db);
check($response->status === 500 && strpos($response->data, 'clearing logs failed') !== false && count($db->queries) === 4, 'Log cleanup failure was not reported');
$db = new FakeDb();
$db->deleted_jobs = 0;
check(cancel($db)->status === 409, 'Incomplete job deletion was reported as success');
check($GLOBALS['nonce_action'] === 'a1fi_cancel_and_clear_feed', 'Cancellation nonce not checked');
echo "Feed cancellation checks passed.\n";
