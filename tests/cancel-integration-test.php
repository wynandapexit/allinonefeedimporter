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
class SQLiteWpdb {
    public $db;
    public $last_error = '';
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE wp_a1fi_jobs (id INTEGER PRIMARY KEY, feed_id INTEGER, status TEXT, finished_at TEXT, message TEXT);
            CREATE TABLE wp_a1fi_job_rows (id INTEGER PRIMARY KEY, job_id INTEGER, status TEXT);
            CREATE TABLE wp_a1fi_import_logs (id INTEGER PRIMARY KEY, job_id INTEGER);
            INSERT INTO wp_a1fi_jobs (id,feed_id,status) VALUES (46,5,'running'),(47,6,'running'),(48,5,'completed');
            INSERT INTO wp_a1fi_job_rows VALUES (1,46,'queued'),(2,47,'queued'),(3,48,'completed');
            INSERT INTO wp_a1fi_import_logs VALUES (1,46),(2,47),(3,48)");
    }
    public function prepare($sql, ...$args) {
        $index = 0;
        return preg_replace_callback('/%[ds]/', function ($match) use (&$index, $args) {
            $value = $args[$index++];
            return $match[0] === '%d' ? (string) (int) $value : $this->db->quote($value);
        }, $sql);
    }
    public function get_col($sql) {
        $this->last_error = '';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
    public function query($sql) {
        $this->last_error = '';
        if (strpos($sql, 'INNER JOIN') !== false) {
            if (!preg_match('/j\.id IN \(([0-9,]+)\)/', $sql, $ids)) throw new RuntimeException('Unexpected job selection');
            $matching = "SELECT id FROM wp_a1fi_jobs WHERE id IN ($ids[1]) AND status='cancelled'";
            if (strpos($sql, 'UPDATE wp_a1fi_job_rows') === 0) {
                $sql = "UPDATE wp_a1fi_job_rows SET status='cancelled' WHERE status='queued' AND job_id IN ($matching)";
            } elseif (strpos($sql, 'DELETE r FROM wp_a1fi_job_rows') === 0) {
                $sql = "DELETE FROM wp_a1fi_job_rows WHERE job_id IN ($matching)";
            } elseif (strpos($sql, 'DELETE l FROM wp_a1fi_import_logs') === 0) {
                $sql = "DELETE FROM wp_a1fi_import_logs WHERE job_id IN ($matching)";
            } else {
                throw new RuntimeException('Unexpected joined query');
            }
        }
        try { return $this->db->exec($sql); }
        catch (PDOException $error) { $this->last_error = $error->getMessage(); return false; }
    }
}
function current_user_can($cap) { return true; }
function check_ajax_referer($action) {}
function absint($value) { return abs((int) $value); }
function current_time($type) { return '2026-11-14 10:00:00'; }
function __($value, $domain = null) { return $value; }
function get_transient($key) { return !empty($GLOBALS['worker_active']); }
function wp_send_json_success($data) { throw new JsonResponse($data, 200); }
function wp_send_json_error($data, $status) { throw new JsonResponse($data, $status); }
require __DIR__ . '/../includes/class-a1fi-feed-cancel.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function run_cancel($db, $worker_active) {
    $GLOBALS['wpdb'] = $db;
    $GLOBALS['worker_active'] = $worker_active;
    $_POST['feed_id'] = 5;
    try { A1FI_Feed_Cancel::cancel_feed_jobs(); } catch (JsonResponse $response) { return $response; }
    throw new RuntimeException('No response');
}
$db = new SQLiteWpdb();
$response = run_cancel($db, false);
check($response->status === 200 && $response->data['cleared'], 'Cancellation did not clear the selected job');
foreach (['wp_a1fi_job_rows' => 'job_id', 'wp_a1fi_import_logs' => 'job_id', 'wp_a1fi_jobs' => 'id'] as $table => $column) {
    $ids = $db->db->query("SELECT $column FROM $table ORDER BY $column")->fetchAll(PDO::FETCH_COLUMN);
    check(array_map('intval', $ids) === [47, 48], "Cleanup affected the wrong records in $table");
}
$db = new SQLiteWpdb();
$response = run_cancel($db, true);
check($response->status === 200 && !$response->data['cleared'], 'Active worker did not defer cleanup');
check($db->db->query('SELECT status FROM wp_a1fi_jobs WHERE id=46')->fetchColumn() === 'cancelled', 'Job was not cancelled');
check((int) $db->db->query('SELECT COUNT(*) FROM wp_a1fi_import_logs WHERE job_id=46')->fetchColumn() === 1, 'Logs were deleted while worker was active');
echo "Cancellation database checks passed.\n";
