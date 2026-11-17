<?php
define('ABSPATH', __DIR__ . '/fixtures/');
class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class WC_Product_Simple {
    public $id = 0;
    public $name = '';
    public $regular_price = '';
    public $sale_price = '';
    public $quantity = null;
    public $manage_stock = false;
    public $image = 0;
    public $gallery = [];
    public $saves = 0;
    public $type = 'simple';
    public function is_type($types) { return in_array($this->type, (array) $types, true); }
    public function set_name($value) { $this->name = $value; }
    public function set_sku($value) {}
    public function set_status($value) {}
    public function set_description($value) {}
    public function set_short_description($value) {}
    public function set_category_ids($value) {}
    public function get_regular_price($context = '') { return $this->regular_price; }
    public function get_sale_price($context = '') { return $this->sale_price; }
    public function set_regular_price($value) { $this->regular_price = $value; }
    public function set_sale_price($value) { $this->sale_price = $value; }
    public function get_manage_stock($context = '') { return $this->manage_stock; }
    public function get_stock_quantity($context = '') { return $this->quantity; }
    public function set_manage_stock($value) { $this->manage_stock = $value; }
    public function set_stock_quantity($value) { $this->quantity = $value; }
    public function get_image_id($context = '') { return $this->image; }
    public function get_gallery_image_ids($context = '') { return $this->gallery; }
    public function set_image_id($id) { $this->image = $id; }
    public function set_gallery_image_ids($ids) { $this->gallery = $ids; }
    public function save() { $this->saves++; if (!$this->id) $this->id = 42; $GLOBALS['last_saved_product'] = $this; return $this->id; }
}
function __($value, $domain = null) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wp_kses_post($value) { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wc_get_product_id_by_sku($sku) { return $GLOBALS['product']->id; }
function wc_get_product($id) { return $GLOBALS['product']; }
function wp_http_validate_url($url) { return filter_var($url, FILTER_VALIDATE_URL) && strpos($url, 'https://') === 0; }
function esc_url_raw($url) { return $url; }
function wp_attachment_is_image($id) { return isset($GLOBALS['attachments'][$id]); }
function get_post_meta($id, $key, $single) { return $GLOBALS['attachments'][$id]['url'] ?? ''; }
function get_posts($args) {
    return array_keys(array_filter($GLOBALS['attachments'], static function ($item) use ($args) { return $item['parent'] === $args['post_parent']; }));
}
function media_sideload_image($url, $product_id, $desc, $type) {
    $GLOBALS['downloads'][] = $url;
    if (!empty($GLOBALS['fail_download'])) return new WP_Error('download', 'Download failed');
    $id = 100 + count($GLOBALS['downloads']);
    $GLOBALS['attachments'][$id] = ['url' => $url, 'parent' => $product_id];
    return $id;
}
require __DIR__ . '/../includes/class-a1fi-queue.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function import_row($row) {
    static $method;
    if (!$method) { $method = new ReflectionMethod(A1FI_Queue::class, 'import_product'); $method->setAccessible(true); }
    return $method->invoke(null, $row, array_combine(array_keys($row), array_keys($row)));
}
function import_with_mapping($row, $mapping) {
    $method = new ReflectionMethod(A1FI_Queue::class, 'import_product');
    $method->setAccessible(true);
    return $method->invoke(null, $row, $mapping);
}
$product = new WC_Product_Simple();
$product->id = 42;
$product->name = 'Existing name';
$product->regular_price = '10.00';
$product->sale_price = '8.00';
$product->manage_stock = true;
$product->quantity = 4;
$product->image = 5;
$product->gallery = [6];
$GLOBALS['product'] = $product;
$GLOBALS['attachments'] = [
    5 => ['url' => 'https://example.test/one.jpg', 'parent' => 42],
    6 => ['url' => 'https://example.test/two.jpg', 'parent' => 42],
];
$GLOBALS['downloads'] = [];
$row = ['name' => 'Changed name', 'sku' => 'sku', 'regular_price' => '10', 'sale_price' => '8.0', 'stock_quantity' => '4', 'images' => 'https://example.test/one.jpg,https://example.test/two.jpg'];
$result = import_row($row);
check($result['outcome'] === 'skipped' && $product->saves === 0 && !$GLOBALS['downloads'], 'Unchanged product was not skipped');
check($product->name === 'Existing name', 'Existing name was overwritten');
$GLOBALS['attachments'][5]['url'] = '';
$row['images'] = 'https://example.test/new.jpg,https://example.test/one.jpg';
check(import_row($row)['outcome'] === 'skipped' && $product->saves === 0 && !$GLOBALS['downloads'] && $product->image === 5 && $product->gallery === [6], 'Changed or untracked images caused an unchanged product to be updated');
$row['regular_price'] = '12.50';
$result = import_row($row);
check($result['outcome'] === 'updated' && $product->saves === 1 && $product->regular_price === '12.50' && $product->quantity === 4, 'Regular price change was not applied');
check(!$GLOBALS['downloads'] && $product->image === 5 && $product->gallery === [6], 'Images changed during a price and stock update');
$row['images'] = 'invalid';
check(import_row($row)['outcome'] === 'skipped' && $product->saves === 1, 'Invalid image URL affected an existing product');
$row['stock_quantity'] = '0';
check(import_row($row)['outcome'] === 'updated' && $product->quantity === 0 && $product->saves === 2 && !$GLOBALS['downloads'], 'Stock quantity change was not applied independently of images');
check(import_row($row)['outcome'] === 'skipped' && $product->saves === 2, 'Repeated stock update was not skipped');
$row['sale_price'] = '7.00';
check(import_row($row)['outcome'] === 'updated' && $product->sale_price === '7.00' && !$GLOBALS['downloads'], 'Sale price change was not applied independently of images');
$product->manage_stock = false;
unset($row['images']);
check(import_row($row)['outcome'] === 'updated' && $product->manage_stock, 'Stock management was not enabled');
$row['sale_price'] = '12.50';
check(import_row($row)['outcome'] === 'updated' && $product->sale_price === '', 'Equal regular and sale prices did not clear the sale price');
$saves = $product->saves;
check(import_row($row)['outcome'] === 'skipped' && $product->saves === $saves, 'Equal sale price was repeatedly updated');
$row['sale_price'] = '14';
check(import_row($row)['outcome'] === 'skipped' && $product->saves === $saves, 'Above-regular sale price was repeatedly updated');
$row['sale_price'] = '11';
check(import_row($row)['outcome'] === 'updated' && $product->sale_price === '11', 'Valid sale price was not applied after an invalid one');
$row['regular_price'] = '10';
check(import_row($row)['outcome'] === 'updated' && $product->regular_price === '10' && $product->sale_price === '', 'Sale price was not cleared after the regular price fell');
$product->type = 'variable';
$row['sale_price'] = '10';
check(import_row($row)['outcome'] === 'updated' && $product->sale_price === '10', 'Variable product sale price was incorrectly cleared');
$product->type = 'simple';
$row['sale_price'] = '7';
check(import_row($row)['outcome'] === 'updated' && $product->sale_price === '7', 'Sale price was not restored');
$product->image = 0;
$product->gallery = [6];
$row['images'] = 'https://example.test/other.jpg';
check(import_row($row)['outcome'] === 'skipped' && !$GLOBALS['downloads'] && $product->image === 0 && $product->gallery === [6], 'Gallery-only product was treated as missing an image');
$product->gallery = [];
unset($row['images']);
check(import_row($row)['outcome'] === 'skipped' && !$GLOBALS['downloads'], 'Image-free product with no mapped images was updated');
$row['images'] = 'https://example.test/two.jpg';
check(import_row($row)['outcome'] === 'updated' && $product->image === 6 && !$GLOBALS['downloads'], 'Previously attached image was not reused for an image-free product');
check(import_row($row)['outcome'] === 'skipped' && !$GLOBALS['downloads'], 'Product with reused image was not skipped on the next run');
$product->image = 0;
$row['images'] = 'https://example.test/missing.jpg';
check(import_row($row)['outcome'] === 'updated' && $product->image === 101 && count($GLOBALS['downloads']) === 1, 'Image-free product did not receive its mapped image');
check(import_row($row)['outcome'] === 'skipped' && count($GLOBALS['downloads']) === 1, 'Newly assigned image was downloaded again');
$product->image = 0;
$row['images'] = 'invalid';
check(is_wp_error(import_row($row)), 'Invalid image URL for an image-free product was not reported');
$same_price_product = new WC_Product_Simple();
$same_price_product->id = 43;
$same_price_product->regular_price = '100.00';
$same_price_product->sale_price = '';
$same_price_product->manage_stock = true;
$same_price_product->quantity = 4;
$same_price_product->image = 5;
$GLOBALS['product'] = $same_price_product;
$same_price_row = ['name' => 'Product', 'sku' => 'sku', 'rrp_incl' => '100', 'jhbstock' => '4'];
$same_price_mapping = ['name' => 'name', 'sku' => 'sku', 'regular_price' => 'rrp_incl', 'sale_price' => 'rrp_incl', 'stock_quantity' => 'jhbstock'];
check(import_with_mapping($same_price_row, $same_price_mapping)['outcome'] === 'skipped' && $same_price_product->saves === 0, 'Identical regular and sale price mappings caused an unchanged product update');
$same_price_row['rrp_incl'] = '110';
check(import_with_mapping($same_price_row, $same_price_mapping)['outcome'] === 'updated' && $same_price_product->regular_price === '110' && $same_price_product->sale_price === '', 'Changed shared price mapping was not applied correctly');
check(import_with_mapping($same_price_row, $same_price_mapping)['outcome'] === 'skipped' && $same_price_product->saves === 1, 'Shared price mapping caused a repeated product update');
$GLOBALS['product'] = new WC_Product_Simple();
$new_row = ['name' => 'New product', 'sku' => 'new', 'regular_price' => '23', 'stock_quantity' => '3', 'images' => 'https://example.test/brand-new.jpg'];
$result = import_row($new_row);
check($result['outcome'] === 'created' && $GLOBALS['last_saved_product']->name === 'New product' && $GLOBALS['last_saved_product']->quantity === 3 && $GLOBALS['last_saved_product']->image > 0, 'New product was not created with its mapped fields and image');
check(count($GLOBALS['downloads']) === 2, 'New product image was not downloaded');
$GLOBALS['product'] = new WC_Product_Simple();
$new_row['images'] = 'invalid';
check(is_wp_error(import_row($new_row)), 'Invalid image URL for a new product was not reported');
$GLOBALS['product'] = new WC_Product_Simple();
$GLOBALS['fail_download'] = true;
$new_row['images'] = 'https://example.test/unavailable.jpg';
check(is_wp_error(import_row($new_row)), 'New product image download failure was not reported');
echo "Product update and image import checks passed.\n";
