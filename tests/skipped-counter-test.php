<?php
define('ABSPATH', '/');
class A1FI_Plugin { const OPTION = 'a1fi_feeds'; }
class Product {
    public function get_regular_price($context) { return '10.00'; }
}
class FakeDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = [];
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_row($sql) {
        return (object) ['id' => 46, 'feed_id' => 5, 'status' => 'running', 'total_rows' => 1, 'processed_rows' => 1, 'created_count' => 0, 'updated_count' => 0, 'skipped_count' => 1, 'failed_count' => 0, 'errors' => null];
    }
    public function get_var($sql) { return strpos($sql, 'COUNT(*)') !== false ? 1 : 'running'; }
    public function get_results($sql) { return [(object) ['id' => 1, 'row_number' => 1, 'payload' => '{"name":"Product","sku":"sku","regular_price":"10"}']]; }
    public function update(...$args) { return 1; }
    public function query($sql) { $this->queries[] = $sql; return 1; }
}
function get_option($key, $default = false) { return [5 => ['mapping' => ['name' => 'name', 'sku' => 'sku', 'regular_price' => 'regular_price']]]; }
function __($value, $domain = null) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wc_get_product_id_by_sku($sku) { return 42; }
function wc_get_product($id) { return new Product(); }
function is_wp_error($value) { return false; }
function current_time($type) { return '2026-11-14 10:00:00'; }
require __DIR__ . '/../includes/class-a1fi-queue.php';
$GLOBALS['wpdb'] = new FakeDb();
$method = new ReflectionMethod(A1FI_Queue::class, 'process_batch');
$method->setAccessible(true);
$method->invoke(null);
$queries = implode("\n", $GLOBALS['wpdb']->queries);
if (strpos($queries, '`skipped_count`=`skipped_count`+1') === false || strpos($queries, 'skipped 1') === false) {
    throw new RuntimeException('Unchanged rows were not counted as skipped');
}
echo "Skipped counter checks passed.\n";
