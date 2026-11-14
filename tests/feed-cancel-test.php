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
class FakeDb {
    public $last_error = '';
    public $ids = [46];
    public $lookup_error = '';
    public $fail_at = 0;
    public $affected = 1;
    public $queries = [];
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_col($sql) { $this->last_error = $this->lookup_error; return $this->ids; }
    public function query($sql) {
        $this->queries[] = $sql;
        if (count($this->queries) === $this->fail_at) { $this->last_error = 'database error'; return false; }
        return count($this->queries) === 1 ? $this->affected : 1;
    }
}
function current_user_can($cap) { return $GLOBALS['allowed']; }
function check_ajax_referer($action) { $GLOBALS['nonce_action'] = $action; }
function absint($value) { return abs((int) $value); }
function current_time($type) { return '2026-11-14 10:00:00'; }
function __($value, $domain = null) { return $value; }
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
check($response->status === 200 && $response->data['cancelled'] === 1, 'Cancellation failed');
check(strpos($db->queries[1], "j.status='cancelled'") !== false, 'Rows of non-cancelled jobs are not protected');
check($GLOBALS['nonce_action'] === 'a1fi_feed_cancel', 'Cancellation nonce not checked');
echo "Feed cancellation checks passed.\n";
