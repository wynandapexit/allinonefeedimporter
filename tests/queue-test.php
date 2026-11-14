<?php
define('ABSPATH', '/');
class WP_Error {
    private $message;
    public function __construct($code, $message, $data = null) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class A1FI_Plugin { const OPTION = 'a1fi_feeds'; }
class A1FI_Feed_Reader {
    public static function read($file, $type, $delimiter) {
        return ['headers' => ['name'], 'rows' => [['name' => 'Product']]];
    }
    public static function validate($mapping, $headers) { return []; }
}
class FakeDb {
    public $prefix = 'wp_';
    public $insert_id = 12;
    public $last_error = '';
    public $queries = [];
    public $fail_insert = false;
    public function insert($table, $data, $formats) { return 1; }
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function query($sql) {
        $this->queries[] = $sql;
        if ($this->fail_insert && strpos($sql, 'INSERT INTO') === 0) { $this->last_error = 'insert failed'; return false; }
        return 1;
    }
    public function get_var($sql) { return 1; }
    public function delete(...$args) { return 1; }
    public function update(...$args) { return 1; }
}
function get_option($key, $default = false) { return $GLOBALS['feeds']; }
function current_time($type) { return '2026-11-14 10:00:00'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text, $domain = null) { return $text; }
function wp_json_encode($value) { return json_encode($value); }
function wp_schedule_single_event($time, $hook) { $GLOBALS['scheduled'][] = $hook; }
require __DIR__ . '/../includes/class-a1fi-queue.php';
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$GLOBALS['wpdb'] = new FakeDb();
$GLOBALS['feeds'] = [1 => ['source_type' => 'upload', 'file_path' => __FILE__, 'file_type' => 'csv', 'mapping' => ['name' => 'name']]];
$GLOBALS['scheduled'] = [];
$result = A1FI_Queue::enqueue_scheduled_run(1);
check($result === ['job_id' => 12, 'stored' => 1], 'Scheduled feed did not enqueue');
check($GLOBALS['scheduled'] === ['a1fi_process_queue'], 'Queue worker was not scheduled');
$GLOBALS['wpdb']->fail_insert = true;
$result = A1FI_Queue::enqueue_scheduled_run(1);
check(is_wp_error($result) && strpos($result->get_error_message(), 'insert failed') !== false, 'Row insertion error was not returned');
$method = new ReflectionMethod(A1FI_Queue::class, 'finish_job');
$method->setAccessible(true);
$method->invoke(null, (object) ['id' => 12], 'completed', 'Done');
$query = end($GLOBALS['wpdb']->queries);
check(strpos($query, "status IN ('queued','running')") !== false && strpos($query, 'id=12') !== false, 'Terminal update did not guard active statuses');
echo "Queue checks passed.\n";
