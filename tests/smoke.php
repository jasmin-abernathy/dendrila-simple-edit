<?php
// Lightweight dependency-free contract tests; not a substitute for a live WordPress test.
define('ABSPATH', __DIR__);
$GLOBALS['dendrila_simple_edit_options'] = array(
    'dendrila_simple_edit_enabled_fields' => array('phone', 'hours'),
    'dendrila_simple_edit_state' => array(
        'revision' => 0,
        'values' => array('phone' => '01 02', 'hours' => 'Lundi 9–18', 'presentation' => 'À conserver'),
        'history' => array(),
    ),
);
$GLOBALS['dendrila_simple_edit_caps'] = array('read', 'dendrila_simple_edit_access', 'dendrila_simple_edit_edit', 'dendrila_simple_edit_publish');

function __($value, $domain = null) { return $value; }
function esc_html__($value, $domain = null) { return $value; }
function user_can($id, $cap) { return current_user_can($cap); }
function get_userdata($id) { return (object) array('ID' => (int) $id, 'roles' => array('dendrila_simple_editor')); }
function get_option($key, $fallback = null) { return isset($GLOBALS['dendrila_simple_edit_options'][$key]) ? $GLOBALS['dendrila_simple_edit_options'][$key] : $fallback; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['dendrila_simple_edit_caps'], true); }
function wp_unslash($input) { return is_string($input) ? stripslashes($input) : $input; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
function shortcode_atts($defaults, $given, $shortcode = '') { return array_merge($defaults, is_array($given) ? $given : array()); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function current_time($type, $gmt = false) { return '2026-10-10 12:00:00'; }
function get_current_user_id() { return 31; }
function maybe_serialize($value) { return is_array($value) ? serialize($value) : $value; }
function wp_cache_get_last_changed($group) { return "1"; }
function wp_cache_get($key, $group) { return $GLOBALS["cache"][$group][$key] ?? false; }
function wp_cache_set($key, $value, $group, $ttl) { $GLOBALS["cache"][$group][$key] = $value; return true; }
function wp_cache_delete($key, $group) { return true; }
function do_action($name, ...$args) { $GLOBALS['dendrila_simple_edit_actions'][] = array($name, $args); }

class Dendrila_Simple_Edit_Test_DB {
    public $last_error = "";
    public $options = 'wp_options';
    public $posts = 'wp_posts';
    public $last_sql;
    public $args;
    public function prepare($query, ...$args) { $this->args = $args; $this->last_sql = $query; return 'prepared'; }
    public function esc_like($value) { return addcslashes($value, '%_\\'); }
    public function get_results($query) { return array((object) array('ID' => 16, 'post_title' => 'Accueil')); }
    public function query($query) {
        if (maybe_serialize($GLOBALS['dendrila_simple_edit_options']['dendrila_simple_edit_state']) !== $this->args[2]) {
            return 0;
        }
        $GLOBALS['dendrila_simple_edit_options']['dendrila_simple_edit_state'] = unserialize($this->args[0]);
        return 1;
    }
}
$wpdb = new Dendrila_Simple_Edit_Test_DB();

require_once dirname(__DIR__) . '/includes/class-dendrila-simple-edit-approvals.php';
require_once dirname(__DIR__) . '/includes/class-dendrila-simple-edit-setup.php';
require_once dirname(__DIR__) . '/includes/class-dendrila-simple-edit-modules.php';
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-plugin.php';
function expect($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function expect_invalid($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException $e) { return; }
    expect(false, $message);
}

expect(Dendrila_Simple_Edit_Plugin::can_access(), 'Dedicated role can access dashboard');
expect(Dendrila_Simple_Edit_Plugin::can_edit(), 'Dedicated role can publish configured fields');
$GLOBALS['dendrila_simple_edit_caps'] = array('read');
expect(!Dendrila_Simple_Edit_Plugin::can_access() && !Dendrila_Simple_Edit_Plugin::can_edit(), 'Reader cannot use dashboard');
$GLOBALS['dendrila_simple_edit_caps'] = array('manage_options');
expect(Dendrila_Simple_Edit_Plugin::can_access() && Dendrila_Simple_Edit_Plugin::can_edit(), 'Administrator retains access');
$GLOBALS['dendrila_simple_edit_caps'] = array('read', 'dendrila_simple_edit_access', 'dendrila_simple_edit_edit', 'dendrila_simple_edit_publish');

expect(Dendrila_Simple_Edit_Plugin::enabled_fields() === array('phone', 'hours'), 'Allowlist bounded by definitions');
expect_invalid(function () {
    Dendrila_Simple_Edit_Plugin::validate_changes(array('presentation' => 'Secret'), Dendrila_Simple_Edit_Plugin::enabled_fields());
}, 'Unconfigured field rejected');
expect_invalid(function () {
    Dendrila_Simple_Edit_Plugin::validate_changes(array('edit_theme_options' => '1'), Dendrila_Simple_Edit_Plugin::enabled_fields());
}, 'Extra key rejected');
expect_invalid(function () {
    Dendrila_Simple_Edit_Plugin::validate_changes(array('phone' => array('bad')), Dendrila_Simple_Edit_Plugin::enabled_fields());
}, 'Nested payload rejected');

$write = new ReflectionMethod('Dendrila_Simple_Edit_Plugin', 'commit_change');
if (PHP_VERSION_ID < 80100) { $write->setAccessible(true); }
expect($write->invoke(null, 0, array('phone' => '<script>alert(1)</script>09')) === 'saved', 'Permitted edit persists');
expect(Dendrila_Simple_Edit_Plugin::state()['values']['phone'] === 'alert(1)09', 'HTML stripped from site field');
expect(Dendrila_Simple_Edit_Plugin::state()['values']['presentation'] === 'À conserver', 'Unconfigured content unchanged');
expect($write->invoke(null, 0, array('phone' => 'overwrite')) === 'conflict', 'Stale revision rejected');
expect(Dendrila_Simple_Edit_Plugin::state()['revision'] === 1, 'Conflict does not increment revision');
expect(strpos(Dendrila_Simple_Edit_Plugin::shortcode(array('field' => 'phone')), '<script>') === false, 'Public output escaped');
expect(Dendrila_Simple_Edit_Plugin::shortcode(array('field' => 'not_a_field')) === '', 'Unknown shortcode empty');
expect($write->invoke(null, 1, array(), 0) === 'saved', 'Revision restored');
expect(Dendrila_Simple_Edit_Plugin::state()['values']['phone'] === '01 02', 'Restore returns old value');
expect(Dendrila_Simple_Edit_Plugin::state()['values']['presentation'] === 'À conserver', 'Restore retains other fields');
$GLOBALS['dendrila_simple_edit_options']['dendrila_simple_edit_enabled_fields'] = array('phone');
expect($write->invoke(null, 2, array(), 1) === 'saved', 'Restore respects latest allowlist');
expect(Dendrila_Simple_Edit_Plugin::state()['values']['hours'] === 'Lundi 9–18', 'Newly disabled field untouched');

expect(Dendrila_Simple_Edit_Plugin::public_locations('phone')[0]->post_title === 'Accueil', 'Shortcode reference discovered');
expect(strpos($wpdb->last_sql, "post_status = 'publish'") !== false, 'Private pages excluded from discovery');
expect(Dendrila_Simple_Edit_Plugin::public_locations('hours') === array(), 'Disabled field discovery refused');
$GLOBALS['dendrila_simple_edit_options']['dendrila_simple_edit_custom_fields'] = array(
    'zone_bienvenue' => array('label' => 'Bienvenue', 'type' => 'textarea'),
    'invalid-name!' => array('label' => 'Invalide', 'type' => 'text'),
);
$GLOBALS['dendrila_simple_edit_options']['dendrila_simple_edit_enabled_fields'] = array('phone', 'zone_bienvenue', 'invalid-name!');
expect(isset(Dendrila_Simple_Edit_Plugin::definitions()['zone_bienvenue']), 'Custom zone is supported');
expect(!isset(Dendrila_Simple_Edit_Plugin::definitions()['invalid-name!']), 'Invalid configured zone ignored');
expect(Dendrila_Simple_Edit_Plugin::enabled_fields() === array('phone', 'zone_bienvenue'), 'Invalid zone cannot gain edit permission');
expect($write->invoke(null, 3, array('zone_bienvenue' => '<b>Bonjour</b>')) === 'saved', 'Custom field edit');
expect(Dendrila_Simple_Edit_Plugin::shortcode(array('field' => 'zone_bienvenue')) === 'Bonjour', 'Custom zone renders safely');

expect(count($GLOBALS['dendrila_simple_edit_actions']) === 4, 'Each successful change emits exactly one invalidation event');
expect($GLOBALS['dendrila_simple_edit_actions'][3][0] === 'dendrila_simple_edit_fields_updated', 'Cache extension hook name is stable');
echo "OK — permissions, allowlist, sanitization, concurrency, shortcode, restore\n";
