<?php
define('ABSPATH', '/');
class WooCommerce {}
class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class A1FI_Queue {
    public static $calls = [];
    public static $fail = false;
    public static function enqueue_scheduled_run($id) {
        self::$calls[] = $id;
        return self::$fail ? new WP_Error('test', 'Feed unavailable') : ['job_id' => 42, 'stored' => 3];
    }
}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function current_datetime() { return new DateTimeImmutable($GLOBALS['now'], new DateTimeZone('UTC')); }
function current_time($type) { return current_datetime()->format('Y-m-d H:i:s'); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text, $domain = null) { return $text; }
function sanitize_text_field($text) { return $text; }
require __DIR__ . '/../includes/class-a1fi-plugin.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function feed($extra = []) {
    return array_merge(['enabled' => true, 'schedule_enabled' => true, 'schedule_days' => ['saturday'], 'schedule_time' => '09:30'], $extra);
}
$GLOBALS['now'] = '2026-11-14 09:29:00';
$GLOBALS['options'] = ['a1fi_feeds' => [1 => feed(), 2 => feed(['enabled' => false]), 3 => feed(['schedule_days' => ['monday']])]];
A1FI_Plugin::run_scheduled();
check(A1FI_Queue::$calls === [], 'Runs before the scheduled time or on disabled feeds');
$GLOBALS['now'] = '2026-11-14 10:00:00';
A1FI_Plugin::run_scheduled();
check(A1FI_Queue::$calls === [1], 'A delayed check did not enqueue the scheduled feed');
check($GLOBALS['options']['a1fi_feeds'][1]['last_slot'] === '2026-11-14 09:30', 'Incorrect recorded slot');
A1FI_Plugin::run_scheduled();
check(A1FI_Queue::$calls === [1], 'The same slot was enqueued twice');
$GLOBALS['options']['a1fi_feeds'][4] = feed();
A1FI_Queue::$fail = true;
A1FI_Plugin::run_scheduled();
check($GLOBALS['options']['a1fi_feeds'][4]['last_result'] === 'Feed unavailable', 'Enqueue error not reported');
check($GLOBALS['options']['a1fi_feeds'][4]['last_slot'] === '2026-11-14 09:30', 'Failed slot not recorded');
echo "Scheduler checks passed.\n";
