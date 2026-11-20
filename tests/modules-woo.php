<?php
// Strict product editing contract, with WooCommerce stand-ins.
define('ABSPATH', __DIR__);
$GLOBALS['dse_user'] = 4;
$GLOBALS['dse_options'] = array('dse_modules' => array(
    'products' => array('enabled' => true, 'grants' => array(
        '4' => array('create', 'content'), '5' => array('prices'), '6' => array('stock', 'live'),
    )),
));
$GLOBALS['dse_caps'] = array(
    4 => array('read', 'dse_access'),
    5 => array('read', 'dse_access'),
    6 => array('read', 'dse_access'),
    7 => array('read'),
);
function get_option($key, $default = null) { return $GLOBALS['dse_options'][$key] ?? $default; }
function get_current_user_id() { return $GLOBALS['dse_user']; }
function user_can($uid, $cap) { return in_array($cap, $GLOBALS['dse_caps'][$uid] ?? array(), true); }
function wp_unslash($v) { return stripslashes($v); }
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function sanitize_textarea_field($v) { return trim(strip_tags($v)); }
function wc_format_decimal($v) { return str_replace(',', '.', $v); }
function term_exists($id, $taxonomy) { return $taxonomy === 'product_cat' && $id === 11; }
function wp_json_encode($x) { return json_encode($x); }
function wc_get_product($id) { return null; }
function wc_get_products($args) { return array(); }
class DSE_Media {
    public static function curated_ids() { return array(22); }
}
class WC_Product_Simple {
    public $status = 'draft';
    public $type = 'simple';
    public $name = 'Produit';
    public $manage = true;
    public $sale = '';
    public $backorders = 'no';
    public $last_price = null;
    public $last_status = null;
    public $last_stock = null;
    public function get_type() { return $this->type; }
    public function get_status() { return $this->status; }
    public function get_id() { return 42; }
    public function get_name() { return $this->name; }
    public function get_description() { return ''; }
    public function get_short_description() { return ''; }
    public function get_image_id() { return 22; }
    public function get_category_ids() { return array(11); }
    public function get_regular_price() { return '17'; }
    public function get_sale_price($context = 'view') { return $this->sale; }
    public function get_price($context = 'view') { return '17'; }
    public function get_stock_status($context = 'view') { return 'instock'; }
    public function get_backorders($context = 'view') { return $this->backorders; }
    public function set_regular_price($v) { $this->regular = $v; }
    public function set_price($v) { $this->last_price = $v; }
    public function set_stock_quantity($v) { $this->last_stock = $v; }
    public function set_stock_status($v) { $this->last_status = $v; }
    public function get_manage_stock() { return $this->manage; }
    public function get_stock_quantity() { return 10; }
    public function get_date_modified() { return null; }
}
require dirname(__DIR__) . '/includes/class-dse-modules.php';
require dirname(__DIR__) . '/includes/class-dse-woo.php';
function ensure_product($ok, $msg) { if (!$ok) { fwrite(STDERR, "FAIL: {$msg}\n"); exit(1); } }
function rejects($callback, $msg) {
    try { $callback(); } catch (InvalidArgumentException $error) { return; }
    ensure_product(false, $msg);
}
$product = new WC_Product_Simple();
ensure_product(DSE_Woo::available(), 'Woo detection');
ensure_product(DSE_Woo::editable_product($product), 'Only simple');
ensure_product(count(DSE_Woo::clean_changes(array('name' => 'Nouveau', 'description' => 'Texte'), null, true)) === 2, 'Create editorial draft');
ensure_product(DSE_Woo::clean_changes(array('description' => '<b>Texte</b>'), $product)['description'] === 'Texte', 'Sanitize description');
$preserved = new WC_Product_Simple();
$preserved->name = 'Titre <em>importé</em>';
$unchanged = DSE_Woo::clean_changes(array(
    'name' => 'Titre <em>importé</em>', 'description' => 'Autre description',
), $preserved);
ensure_product(!array_key_exists('name', $unchanged), 'Existing formatted field preserved untouched');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('name' => str_repeat('a', 201)), $product);
}, 'Title length enforced');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('regular_price' => '2'), $product);
}, 'Editorial role cannot change prices');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('image_id' => '22'), $product);
}, 'Editorial role cannot edit imagery');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('category_ids' => array('11')), $product);
}, 'Editorial role cannot change categories');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('stock_quantity' => '0'), $product);
}, 'Editorial role cannot change stocks');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('unknown' => 'value'), $product);
}, 'Unknown product field blocked');
$product->type = 'variation';
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('description' => 'x'), $product);
}, 'Variations must not be rewritten');
$product->type = 'simple';
$product->status = 'publish';
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('name' => 'x'), $product);
}, 'Published product requires a second grant');
$product->status = 'draft';
$GLOBALS['dse_user'] = 5;
ensure_product(DSE_Woo::clean_changes(array('regular_price' => '24,50'), $product)['regular_price'] === '24.50', 'Price scope');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('regular_price' => '-1'), $product);
}, 'Negative price blocked');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('regular_price' => '1e5'), $product);
}, 'Scientific notation blocked');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('name' => 'Not allowed'), $product);
}, 'Commercial role cannot edit content');
$product->sale = '11';
ensure_product(DSE_Woo::clean_changes(array('regular_price' => '17'), $product) === array(), 'Unchanged price never rewrites an existing promotion');
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('regular_price' => '19'), $product);
}, 'Promotion price cannot be overwritten');
$product->sale = '';
$apply = new ReflectionMethod('DSE_Woo', 'apply');
$apply->setAccessible(true);
$apply->invoke(null, $product, array('regular_price' => '19'));
ensure_product($product->last_price === '19', 'Effective storefront price updated');
$GLOBALS['dse_user'] = 6;
ensure_product(DSE_Woo::clean_changes(array('stock_quantity' => '10'), $product) === array(), 'Unchanged stock is a no-op');
ensure_product(DSE_Woo::clean_changes(array('stock_quantity' => '0'), $product)['stock_quantity'] === 0, 'Stock only with grant');
$apply->invoke(null, $product, array('stock_quantity' => 0));
ensure_product($product->last_status === 'outofstock', 'Zero stock marked unavailable');
$product->backorders = 'yes';
$apply->invoke(null, $product, array('stock_quantity' => 0));
ensure_product($product->last_status === 'onbackorder', 'Zero with backorders preserved');
$product->backorders = 'no';
$product->manage = false;
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('stock_quantity' => '2'), $product);
}, 'Cannot silently enable native stock management');
$product->manage = true;
$product->status = 'publish';
ensure_product(DSE_Woo::clean_changes(array('stock_quantity' => '2'), $product)['stock_quantity'] === 2, 'Live editing requires both grants');
$GLOBALS['dse_user'] = 4;
$GLOBALS['dse_options']['dse_modules']['products']['grants']['4'][] = 'images';
$GLOBALS['dse_options']['dse_modules']['products']['grants']['4'][] = 'categories';
$product->status = 'draft';
ensure_product(DSE_Woo::clean_changes(array('image_id' => '22'), $product) === array(), 'Same image does not trigger update');
ensure_product(DSE_Woo::clean_changes(array('category_ids' => array('', '11')), $product) === array(), 'Same category set does not trigger update');
$GLOBALS['dse_options']['dse_modules']['products']['enabled'] = false;
rejects(function () use ($product) {
    DSE_Woo::clean_changes(array('stock_quantity' => '2'), $product);
}, 'Disabling module revokes previously granted stock access');
echo "OK — simple products, draft/live separation and independent field permissions\n";
