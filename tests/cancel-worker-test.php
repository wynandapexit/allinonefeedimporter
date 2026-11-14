<?php
define('ABSPATH', '/');
class A1FI_Plugin { const OPTION = 'a1fi_feeds'; }
class WC_Product_Simple {
    public function set_name($value) {}
    public function set_status($value) {}
    public function save() { $GLOBALS['saves']++; $GLOBALS['wpdb']->status = 'cancelled'; return 1; }
}
class FakeDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $status = 'running';
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_row($sql) {
        return (object) ['id' => 46, 'feed_id' => 5, 'status' => 'running', 'total_rows' => 2, 'processed_rows' => 0, 'errors' => null];
    }
    public function get_var($sql) {
        return strpos($sql, 'COUNT(*)') !== false ? 2 : $this->status;
    }
    public function get_results($sql) {
        return [(object) ['id' => 1, 'row_number' => 1, 'payload' => '{"name":"First"}'], (object) ['id' => 2, 'row_number' => 2, 'payload' => '{"name":"Second"}']];
    }
    public function update(...$args) { return 1; }
    public function query($sql) { return 1; }
}
function get_option($key, $default = false) { return [5 => ['mapping' => ['name' => 'name']]]; }
function __($value, $domain = null) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function is_wp_error($value) { return false; }
require __DIR__ . '/../includes/class-a1fi-queue.php';
$GLOBALS['wpdb'] = new FakeDb();
$GLOBALS['saves'] = 0;
$method = new ReflectionMethod(A1FI_Queue::class, 'process_batch');
$method->setAccessible(true);
$method->invoke(null);
if ($GLOBALS['saves'] !== 1) throw new RuntimeException('Worker continued to the next row after cancellation');
echo "Worker cancellation checks passed.\n";
