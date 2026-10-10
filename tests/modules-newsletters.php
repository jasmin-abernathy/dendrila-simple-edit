<?php
// Isolated permission and newsletter-boundary contract test.
define('ABSPATH', __DIR__);
$GLOBALS['dse_current_user'] = 7;
$GLOBALS['dse_caps'] = array(
    7 => array('read', 'dse_access'),
    8 => array('read', 'dse_access'),
    9 => array('read', 'manage_options'),
    10 => array('read'),
);
$GLOBALS['dse_options'] = array();
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['dse_options']) ? $GLOBALS['dse_options'][$name] : $default;
}
function get_current_user_id() { return $GLOBALS['dse_current_user']; }
function user_can($id, $cap) { return in_array($cap, $GLOBALS['dse_caps'][$id] ?? array(), true); }
function current_user_can($cap) { return user_can(get_current_user_id(), $cap); }
function check_admin_referer($nonce) { return true; }
function wp_unslash($value) { return $value; }
function get_user_by($field, $value) { return $field === 'id' && (int) $value === 7 ? (object) array('ID' => 7) : false; }
function wp_die($message, $title = '', $args = array()) { throw new RuntimeException($message); }
function register_post_type($name, $config) { $GLOBALS['dse_registered'] = array($name, $config); }
require dirname(__DIR__) . '/includes/class-dse-modules.php';
require dirname(__DIR__) . '/includes/class-dse-newsletters.php';
function ensure($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function denied($callback, $message) {
    try { $callback(); } catch (RuntimeException $e) { return; }
    ensure(false, $message);
}
ensure(!DSE_Modules::allowed('newsletters', 'create'), 'Disabled by default');
ensure(!DSE_Modules::allowed('products', 'prices'), 'Products disabled by default');
ensure(!DSE_Modules::allowed('newsletters', 'send'), 'No dispatch permission exists');
ensure(!DSE_Modules::allowed('newsletters', 'schedule'), 'No scheduler permission exists');
$GLOBALS['dse_options'][DSE_Modules::OPTION] = array(
    'newsletters' => array('enabled' => true, 'grants' => array('7' => array('create','review'))),
    'products' => array('enabled' => false, 'grants' => array('7' => array('prices'))),
);
ensure(DSE_Modules::allowed('newsletters', 'create'), 'Explicit user grant');
ensure(DSE_Modules::allowed('newsletters', 'review'), 'Review is independent');
ensure(!DSE_Modules::allowed('newsletters', 'edit'), 'No implicit editing');
ensure(!DSE_Modules::allowed('newsletters', 'approve'), 'No implicit approval');
ensure(!DSE_Modules::allowed('newsletters', 'create', 8), 'Other manager isolated');
ensure(DSE_Modules::allowed('newsletters', 'edit', 9), 'Admin may edit enabled module');
ensure(!DSE_Modules::allowed('newsletters', 'send', 9), 'Admin cannot activate nonexistent send');
ensure(!DSE_Modules::allowed('newsletters', 'create', 10), 'Reader without DSE grant rejected');
ensure(!DSE_Modules::allowed('products', 'prices'), 'Disabled module overrides stored grants');
$GLOBALS['dse_caps'][7][] = 'manage_woocommerce';
ensure(!DSE_Modules::allowed('products', 'prices'), 'Broad Woo capability cannot bypass disabled module');
DSE_Newsletters::register();
ensure($GLOBALS['dse_registered'][0] === 'dse_newsletter', 'Independent internal content type');
$config = $GLOBALS['dse_registered'][1];
ensure($config['public'] === false && $config['show_ui'] === false &&
    $config['show_in_rest'] === false, 'No public or native editor route');
ensure($config['capabilities']['publish_posts'] === 'do_not_allow', 'No native publishing rights');
$post = (object) array('post_title' => 'Test', 'post_content' => "Line\nTwo",
    'post_status' => 'draft', 'post_modified_gmt' => '2026-10-10 10:00:00');
$version = DSE_Newsletters::fingerprint($post);
$post->post_content = 'Changed';
ensure($version !== DSE_Newsletters::fingerprint($post), 'Stale text detected');
denied(function () { DSE_Newsletters::approve(); }, 'No approval privilege cannot approve');
$GLOBALS['dse_current_user'] = 8;
$_POST = array('dse_nid' => '0', 'dse_nsubject' => 'Test', 'dse_nbody' => 'Contenu');
denied(function () { DSE_Newsletters::save(); }, 'Second manager cannot create without permission');
$GLOBALS['dse_current_user'] = 9;
$_POST = array('dse_module' => 'newsletters', 'dse_module_enabled' => '1',
    'dse_grants' => array('7' => array('send')));
denied(function () { DSE_Modules::configure(); }, 'Forged send action rejected by config endpoint');
$GLOBALS['dse_current_user'] = 7;
$_POST = array();
$GLOBALS['dse_options'][DSE_Modules::OPTION]['newsletters']['enabled'] = false;
ensure(!DSE_Modules::allowed('newsletters', 'create', 9), 'Disabling blocks admin path too');
echo "OK — per-user module ACL, closed newsletter sending boundary, private drafts\n";
