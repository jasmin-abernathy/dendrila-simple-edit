<?php
// Lightweight dependency-free contract tests; not a substitute for a live WordPress test.
define('ABSPATH', __DIR__);
$GLOBALS['dse_options'] = array(
    'dse_enabled_fields' => array('phone', 'hours'),
    'dse_state' => array(
        'revision' => 0,
        'values' => array('phone' => '01 02', 'hours' => 'Lundi 9–18', 'presentation' => 'À conserver'),
        'history' => array(),
    ),
);
$GLOBALS['dse_caps'] = array('read', 'dse_access', 'dse_edit', 'dse_publish');

function get_option($key, $fallback = null) { return isset($GLOBALS['dse_options'][$key]) ? $GLOBALS['dse_options'][$key] : $fallback; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['dse_caps'], true); }
function wp_unslash($input) { return is_string($input) ? stripslashes($input) : $input; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
function shortcode_atts($defaults, $given, $shortcode = '') { return array_merge($defaults, is_array($given) ? $given : array()); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function current_time($type, $gmt = false) { return '2026-10-10 12:00:00'; }
function get_current_user_id() { return 31; }
function maybe_serialize($value) { return is_array($value) ? serialize($value) : $value; }
function wp_cache_delete($key, $group) { return true; }
function do_action($name, ...$args) { $GLOBALS['dse_actions'][] = array($name, $args); }

class DSE_Test_DB {
    public $options = 'wp_options';
    public $posts = 'wp_posts';
    public $last_sql;
    public $args;
    public function prepare($query, ...$args) { $this->args = $args; $this->last_sql = $query; return 'prepared'; }
    public function esc_like($value) { return addcslashes($value, '%_\\'); }
    public function get_results($query) { return array((object) array('ID' => 16, 'post_title' => 'Accueil')); }
    public function query($query) {
        if (maybe_serialize($GLOBALS['dse_options']['dse_state']) !== $this->args[2]) {
            return 0;
        }
        $GLOBALS['dse_options']['dse_state'] = unserialize($this->args[0]);
        return 1;
    }
}
$wpdb = new DSE_Test_DB();

require dirname(__DIR__) . '/includes/class-dse-plugin.php';
function expect($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function expect_invalid($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException $e) { return; }
    expect(false, $message);
}

expect(DSE_Plugin::can_access(), 'Dedicated role can access dashboard');
expect(DSE_Plugin::can_edit(), 'Dedicated role can publish configured fields');
$GLOBALS['dse_caps'] = array('read');
expect(!DSE_Plugin::can_access() && !DSE_Plugin::can_edit(), 'Reader cannot use dashboard');
$GLOBALS['dse_caps'] = array('manage_options');
expect(DSE_Plugin::can_access() && DSE_Plugin::can_edit(), 'Administrator retains access');
$GLOBALS['dse_caps'] = array('read', 'dse_access', 'dse_edit', 'dse_publish');

expect(DSE_Plugin::enabled_fields() === array('phone', 'hours'), 'Allowlist bounded by definitions');
expect_invalid(function () {
    DSE_Plugin::validate_changes(array('presentation' => 'Secret'), DSE_Plugin::enabled_fields());
}, 'Unconfigured field rejected');
expect_invalid(function () {
    DSE_Plugin::validate_changes(array('edit_theme_options' => '1'), DSE_Plugin::enabled_fields());
}, 'Extra key rejected');
expect_invalid(function () {
    DSE_Plugin::validate_changes(array('phone' => array('bad')), DSE_Plugin::enabled_fields());
}, 'Nested payload rejected');

$write = new ReflectionMethod('DSE_Plugin', 'commit_change');
$write->setAccessible(true);
expect($write->invoke(null, 0, array('phone' => '<script>alert(1)</script>09')) === 'saved', 'Permitted edit persists');
expect(DSE_Plugin::state()['values']['phone'] === 'alert(1)09', 'HTML stripped from site field');
expect(DSE_Plugin::state()['values']['presentation'] === 'À conserver', 'Unconfigured content unchanged');
expect($write->invoke(null, 0, array('phone' => 'overwrite')) === 'conflict', 'Stale revision rejected');
expect(DSE_Plugin::state()['revision'] === 1, 'Conflict does not increment revision');
expect(strpos(DSE_Plugin::shortcode(array('field' => 'phone')), '<script>') === false, 'Public output escaped');
expect(DSE_Plugin::shortcode(array('field' => 'not_a_field')) === '', 'Unknown shortcode empty');
expect($write->invoke(null, 1, array(), 0) === 'saved', 'Revision restored');
expect(DSE_Plugin::state()['values']['phone'] === '01 02', 'Restore returns old value');
expect(DSE_Plugin::state()['values']['presentation'] === 'À conserver', 'Restore retains other fields');
$GLOBALS['dse_options']['dse_enabled_fields'] = array('phone');
expect($write->invoke(null, 2, array(), 1) === 'saved', 'Restore respects latest allowlist');
expect(DSE_Plugin::state()['values']['hours'] === 'Lundi 9–18', 'Newly disabled field untouched');

expect(DSE_Plugin::public_locations('phone')[0]->post_title === 'Accueil', 'Shortcode reference discovered');
expect(strpos($wpdb->last_sql, "post_status = 'publish'") !== false, 'Private pages excluded from discovery');
expect(DSE_Plugin::public_locations('hours') === array(), 'Disabled field discovery refused');
$GLOBALS['dse_options']['dse_custom_fields'] = array(
    'zone_bienvenue' => array('label' => 'Bienvenue', 'type' => 'textarea'),
    'invalid-name!' => array('label' => 'Invalide', 'type' => 'text'),
);
$GLOBALS['dse_options']['dse_enabled_fields'] = array('phone', 'zone_bienvenue', 'invalid-name!');
expect(isset(DSE_Plugin::definitions()['zone_bienvenue']), 'Custom zone is supported');
expect(!isset(DSE_Plugin::definitions()['invalid-name!']), 'Invalid configured zone ignored');
expect(DSE_Plugin::enabled_fields() === array('phone', 'zone_bienvenue'), 'Invalid zone cannot gain edit permission');
expect($write->invoke(null, 3, array('zone_bienvenue' => '<b>Bonjour</b>')) === 'saved', 'Custom field edit');
expect(DSE_Plugin::shortcode(array('field' => 'zone_bienvenue')) === 'Bonjour', 'Custom zone renders safely');

expect(count($GLOBALS['dse_actions']) === 4, 'Each successful change emits exactly one invalidation event');
expect($GLOBALS['dse_actions'][3][0] === 'dse_fields_updated', 'Cache extension hook name is stable');
echo "OK — permissions, allowlist, sanitization, concurrency, shortcode, restore\n";
