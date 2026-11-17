<?php
define('ABSPATH', '/');
class A1FI_Plugin { const OPTION = 'a1fi_feeds'; }
class Product {
    public function get_regular_price($context) {
        if (!empty($GLOBALS['slow_row'])) {
            $GLOBALS['slow_row'] = false;
            usleep((A1FI_Queue::BATCH_SECONDS + 1) * 1000000);
        }
        return '10.00';
    }
}
class FakeDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = [];
    public $job;
    public function __construct($count) {
        $this->job = (object) ['id' => 46, 'feed_id' => 5, 'status' => 'running', 'total_rows' => $count, 'processed_rows' => 0, 'created_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'errors' => null];
        for ($i = 1; $i <= $count; $i++) {
            $this->rows[$i] = (object) ['id' => $i, 'row_number' => $i, 'payload' => '{"name":"Product","sku":"sku","regular_price":"10"}', 'status' => 'queued'];
        }
    }
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_row($sql) { return clone $this->job; }
    public function get_var($sql) {
        if (strpos($sql, 'COUNT(*)') !== false) {
            if (strpos($sql, "status='queued'") !== false) return count(array_filter($this->rows, static function ($row) { return $row->status === 'queued'; }));
            return count($this->rows);
        }
        return $this->job->status;
    }
    public function get_results($sql) {
        preg_match('/LIMIT (\d+)/', $sql, $limit);
        return array_slice(array_values(array_filter($this->rows, static function ($row) { return $row->status === 'queued'; })), 0, (int) $limit[1]);
    }
    public function update($table, $data, $where, ...$args) {
        if ($table === 'wp_a1fi_job_rows') $this->rows[$where['id']]->status = $data['status'];
        return 1;
    }
    public function query($sql) {
        if (preg_match('/processed_rows=processed_rows\+1,`(\w+)`=/', $sql, $matches)) {
            $this->job->processed_rows++;
            $this->job->{$matches[1]}++;
        } elseif (strpos($sql, 'SET status=completed') !== false) {
            $this->job->status = 'completed';
        }
        return 1;
    }
}
function get_option($key, $default = false) { return [5 => ['mapping' => ['name' => 'name', 'sku' => 'sku', 'regular_price' => 'regular_price']]]; }
function __($value, $domain = null) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wc_get_product_id_by_sku($sku) { return 42; }
function wc_get_product($id) { return new Product(); }
function is_wp_error($value) { return false; }
function current_time($type) { return '2026-11-17 10:00:00'; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['scheduled'][] = $hook; }
require __DIR__ . '/../includes/class-a1fi-queue.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function run_batch() {
    static $method;
    if (!$method) { $method = new ReflectionMethod(A1FI_Queue::class, 'process_batch'); $method->setAccessible(true); }
    $method->invoke(null);
}
$GLOBALS['scheduled'] = [];
$GLOBALS['wpdb'] = new FakeDb(A1FI_Queue::BATCH_SIZE + 2);
run_batch();
check($GLOBALS['wpdb']->job->processed_rows === 100 && $GLOBALS['wpdb']->job->skipped_count === 100, 'Fast unchanged rows did not fill a batch');
check($GLOBALS['scheduled'] === ['a1fi_process_queue'], 'Remaining rows were not scheduled');
run_batch();
check($GLOBALS['wpdb']->job->processed_rows === 102 && $GLOBALS['wpdb']->job->skipped_count === 102 && $GLOBALS['wpdb']->job->status === 'completed', 'Remaining rows were not processed and counted');

$GLOBALS['scheduled'] = [];
$GLOBALS['wpdb'] = new FakeDb(2);
$GLOBALS['slow_row'] = true;
run_batch();
check($GLOBALS['wpdb']->job->processed_rows === 1 && $GLOBALS['wpdb']->rows[2]->status === 'queued', 'Worker did not stop between rows after its time budget');
check($GLOBALS['scheduled'] === ['a1fi_process_queue'], 'Time-limited batch did not schedule its remaining rows');
run_batch();
check($GLOBALS['wpdb']->job->processed_rows === 2 && $GLOBALS['wpdb']->job->status === 'completed', 'Time-limited batch did not resume');
echo "Queue throughput checks passed.\n";
