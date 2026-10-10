<?php
// Contract checks with fake WordPress and ACF APIs. These do not replace site testing.
define('ABSPATH', __DIR__);
$GLOBALS['allowed_caps'] = array('read', 'dse_access', 'dse_edit', 'dse_publish');
$GLOBALS['configured'] = array(
    'dse_enabled_fields' => array('zone_intro'),
    'dse_custom_fields' => array('zone_intro' => array('label' => 'Introduction', 'type' => 'textarea')),
    'dse_acf_mappings' => array(
        'acf_1234567890abcdef' => array('post_id' => 22, 'field_key' => 'field_intro')
    )
);
$GLOBALS['posts'] = array(
    22 => (object) array('post_status' => 'publish', 'post_password' => '',
        'post_type' => 'article', 'post_title' => 'Contenu visible'),
    23 => (object) array('post_status' => 'draft', 'post_password' => '',
        'post_type' => 'article', 'post_title' => 'Contenu privé'),
    24 => (object) array('post_status' => 'publish', 'post_password' => 'secret',
        'post_type' => 'article', 'post_title' => 'Protégé')
);
$GLOBALS['fields'] = array(
    'field_intro' => array('key' => 'field_intro', 'name' => 'intro', 'label' => 'Introduction',
        'type' => 'text', 'parent' => 'group_123', 'maxlength' => 100),
    'field_nested' => array('key' => 'field_nested', 'name' => 'nested', 'label' => 'Nested',
        'type' => 'text', 'parent' => 'field_repeater'),
    'field_repeater' => array('key' => 'field_repeater', 'name' => 'repeater', 'label' => 'Repeater',
        'type' => 'repeater', 'parent' => 'group_123'),
);
$GLOBALS['acf_values'] = array('22:field_intro' => 'Bonjour');
function get_option($key, $fallback = null) { return $GLOBALS['configured'][$key] ?? $fallback; }
function current_user_can($key) { return in_array($key, $GLOBALS['allowed_caps'], true); }
function get_post($post_id) { return $GLOBALS['posts'][$post_id] ?? null; }
function get_post_type_object($type) { return $type === 'article' ? (object) array('public' => true) : false; }
function get_post_type($post_id) { return 'acf-field-group'; }
function acf_get_field($key) { return $GLOBALS['fields'][$key] ?? false; }
function get_field($key, $post_id, $format = true) { return $GLOBALS['acf_values'][$post_id . ':' . $key] ?? false; }
function update_field($key, $value, $post_id) {
    $GLOBALS['acf_values'][$post_id . ':' . $key] = $value;
    return true;
}
function wp_unslash($value) { return stripslashes($value); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function esc_url_raw($value, $protocols = array()) {
    return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), $protocols, true) ? $value : '';
}

class DSE_Test_Query {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $last_query = '';
    public function esc_like($s) { return addcslashes($s, '%_'); }
    public function prepare($sql, $value) { $this->last_query = $sql; return 'statement'; }
    public function get_results($sql) {
        return array((object) array(
            'ID' => 22,
            'post_title' => 'Contenu visible',
            'meta_value' => json_encode(array(
                array('elType' => 'container', 'elements' => array(
                    array('widgetType' => 'shortcode',
                        'settings' => array('shortcode' => '[dendrila_simple_edit field="zone_intro"]'))
                ))
            ))
        ));
    }
}
$wpdb = new DSE_Test_Query();
require dirname(__DIR__) . '/includes/class-dse-plugin.php';
require dirname(__DIR__) . '/includes/class-dse-acf.php';
require dirname(__DIR__) . '/includes/class-dse-builders.php';

function check_it($okay, $message) {
    if (!$okay) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
check_it(DSE_ACF::available(), 'ACF available');
check_it(is_array(DSE_ACF::resolve(22, 'field_intro')), 'Published mapped field resolves');
check_it(DSE_ACF::resolve(23, 'field_intro') === false, 'Draft refused');
check_it(DSE_ACF::resolve(24, 'field_intro') === false, 'Password protected post refused');
check_it(DSE_ACF::resolve(22, 'field_nested') === false, 'Nested field refused');
check_it(DSE_ACF::resolve(22, 'field_repeater') === false, 'Complex field refused');
check_it(DSE_ACF::resolve(22, '../../etc/passwd') === false, 'Malformed key refused');
check_it(DSE_ACF::mapped('acf_1234567890abcdef') !== false, 'Only assigned mapping resolves');
check_it(DSE_ACF::mapped('acf_1111111111111111') === false, 'Unknown mapping refused');
check_it(DSE_ACF::raw_value(DSE_ACF::mapped('acf_1234567890abcdef'), 22) === 'Bonjour', 'Raw value from ACF');
check_it(DSE_ACF::version('Bonjour') !== DSE_ACF::version('Bonjour !'), 'Change fingerprint detects edits');
$method = new ReflectionMethod('DSE_ACF', 'sanitize');
$method->setAccessible(true);
check_it($method->invoke(null, $GLOBALS['fields']['field_intro'], '<script>alert(1)</script>Salut') === 'alert(1)Salut', 'Text sanitized');
check_it($method->invoke(null, $GLOBALS['fields']['field_intro'], str_repeat('a', 101)) === false, 'Max length enforced');

check_it(DSE_Builders::exact_shortcode('[dendrila_simple_edit field="zone_intro"]', 'zone_intro'), 'Canonical shortcode');
check_it(!DSE_Builders::exact_shortcode('[dendrila_simple_edit field="other"]', 'zone_intro'), 'No match for other field');
check_it(!DSE_Builders::contains_shortcode_widget(array(
    array('widgetType' => 'html',
        'settings' => array('html' => '[dendrila_simple_edit field="zone_intro"]'))
), 'zone_intro'), 'HTML widget excluded');
check_it(DSE_Builders::elementor_shortcode_locations('zone_intro')[0]['id'] === 22, 'Elementor shortcode widget discovered');
check_it(strpos($wpdb->last_query, "p.post_status = 'publish'") !== false, 'Elementor query excludes unpublished');
check_it(DSE_Builders::elementor_shortcode_locations('hidden') === array(), 'Unconfigured field not searched');
echo "OK — ACF mapping, post status, nested types, formatting, Elementor shortcode search\n";
