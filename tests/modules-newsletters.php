<?php
function __($text,$domain) { return $text; }
// Isolated permission and newsletter-boundary contract test.
define('ABSPATH', __DIR__);
$GLOBALS['dendrila_simple_edit_current_user'] = 7;
$GLOBALS['dendrila_simple_edit_caps'] = array(
    7 => array('read', 'dendrila_simple_edit_access'),
    8 => array('read', 'dendrila_simple_edit_access'),
    9 => array('read', 'manage_options'),
    10 => array('read'),
);
$GLOBALS['dendrila_simple_edit_options'] = array();
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['dendrila_simple_edit_options']) ? $GLOBALS['dendrila_simple_edit_options'][$name] : $default;
}
function get_current_user_id() { return $GLOBALS['dendrila_simple_edit_current_user']; }
function get_userdata($id) { $r = array(7=>'newsletter_editor',8=>'other_editor',9=>'administrator',10=>'subscriber'); return (object) array('ID'=>(int)$id,'roles'=>array($r[$id] ?? 'subscriber')); }
function wp_roles() { return (object) array('roles'=>array('newsletter_editor'=>array('name'=>'Editor'),'other_editor'=>array('name'=>'Other editor'),'subscriber'=>array('name'=>'Subscriber'))); }
function user_can($id, $cap) { return in_array($cap, $GLOBALS['dendrila_simple_edit_caps'][$id] ?? array(), true); }
function current_user_can($cap) { return user_can(get_current_user_id(), $cap); }
function check_admin_referer($nonce) { return true; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return is_string($value) ? trim(strip_tags($value)) : $value; }
function map_deep($value, $callback) { if (is_array($value)) { foreach ($value as $key => $part) { $value[$key] = map_deep($part, $callback); } return $value; } return $callback($value); }
function get_user_by($field, $value) { return $field === 'id' && (int) $value === 7 ? (object) array('ID' => 7) : false; }
function wp_die($message, $title = '', $args = array()) { throw new RuntimeException($message); }
function register_post_type($name, $config) { $GLOBALS['dendrila_simple_edit_registered'] = array($name, $config); }
function parse_blocks($content) {
    preg_match_all('~<!-- wp:(paragraph|heading)(?: (\\{[^>]+\\}))? -->([\\s\\S]*?)<!-- /wp:\\1 -->~', $content, $matches, PREG_SET_ORDER);
    $blocks = array();
    foreach ($matches as $match) {
        $attrs = !empty($match[2]) ? json_decode($match[2], true) : array();
        $blocks[] = array('blockName'=>'core/'.$match[1], 'attrs'=>is_array($attrs)?$attrs:array(), 'innerBlocks'=>array(), 'innerHTML'=>$match[3]);
    }
    return $blocks;
}
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function wp_strip_all_tags($value) { return strip_tags((string)$value); }
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-approvals.php';
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-modules.php';
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-newsletters.php';
function ensure($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function denied($callback, $message) {
    try { $callback(); } catch (RuntimeException $e) { return; }
    ensure(false, $message);
}
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'create'), 'Disabled by default');
ensure(!Dendrila_Simple_Edit_Modules::allowed('products', 'prices'), 'Products disabled by default');
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'send'), 'Sending is disabled by default');
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'schedule'), 'No scheduler permission exists');
$GLOBALS['dendrila_simple_edit_options'][Dendrila_Simple_Edit_Modules::OPTION] = array(
    'newsletters' => array('enabled' => true, 'roles' => array('newsletter_editor' => array('create','review'))),
    'products' => array('enabled' => false, 'roles' => array('newsletter_editor' => array('prices'))),
);
ensure(Dendrila_Simple_Edit_Modules::allowed('newsletters', 'create'), 'Explicit role grant');
ensure(Dendrila_Simple_Edit_Modules::allowed('newsletters', 'review'), 'Review is independent');
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'edit'), 'No implicit editing');
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'approve'), 'No implicit approval');
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'create', 8), 'Other manager isolated');
ensure(Dendrila_Simple_Edit_Modules::allowed('newsletters', 'edit', 9), 'Admin may edit enabled module');
ensure(Dendrila_Simple_Edit_Modules::allowed('newsletters', 'send', 9), 'Admin can manage enabled campaigns');
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'create', 10), 'Reader without DSE grant rejected');
ensure(!Dendrila_Simple_Edit_Modules::allowed('products', 'prices'), 'Disabled module overrides stored grants');
$GLOBALS['dendrila_simple_edit_caps'][7][] = 'manage_woocommerce';
ensure(!Dendrila_Simple_Edit_Modules::allowed('products', 'prices'), 'Broad Woo capability cannot bypass disabled module');
Dendrila_Simple_Edit_Newsletters::register();
ensure($GLOBALS['dendrila_simple_edit_registered'][0] === 'dendrila_simple_edit', 'Independent internal content type');
$config = $GLOBALS['dendrila_simple_edit_registered'][1];
ensure($config['public'] === false && $config['show_ui'] === false &&
    $config['show_in_rest'] === false, 'No public or native editor route');
ensure($config['capabilities']['publish_posts'] === 'do_not_allow', 'No native publishing rights');
$post = (object) array('post_title' => 'Test', 'post_content' => "Line\nTwo",
    'post_status' => 'draft', 'post_modified_gmt' => '2026-10-10 10:00:00');
$version = Dendrila_Simple_Edit_Newsletters::fingerprint($post);
$post->post_content = 'Changed';
ensure($version !== Dendrila_Simple_Edit_Newsletters::fingerprint($post), 'Stale text detected');
denied(function () { Dendrila_Simple_Edit_Newsletters::approve(); }, 'No approval privilege cannot approve');
$GLOBALS['dendrila_simple_edit_current_user'] = 8;
$_POST = array('dendrila_simple_edit_nid' => '0', 'dendrila_simple_edit_nsubject' => 'Test', 'dendrila_simple_edit_nbody' => 'Contenu');
denied(function () { Dendrila_Simple_Edit_Newsletters::save(); }, 'Second manager cannot create without permission');
$GLOBALS['dendrila_simple_edit_current_user'] = 9;
$_POST = array('dendrila_simple_edit_module' => 'newsletters', 'dendrila_simple_edit_module_enabled' => '1',
    'dendrila_simple_edit_roles' => array('newsletter_editor' => array('unknown_operation')));
denied(function () { Dendrila_Simple_Edit_Modules::configure(); }, 'Unknown action rejected by config endpoint');
$GLOBALS['dendrila_simple_edit_current_user'] = 7;
$_POST = array();
$GLOBALS['dendrila_simple_edit_options'][Dendrila_Simple_Edit_Modules::OPTION]['newsletters']['enabled'] = false;
ensure(!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'create', 9), 'Disabling blocks admin path too');
echo "OK — per-role module ACL, closed newsletter sending boundary, private drafts\n";

require_once dirname(__DIR__) . '/includes/class-dendrila-simple-edit-block-editor.php';
ensure(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor("First line\nSecond line"), 'Plain text can enter the visual editor');
ensure(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<p>First<br>second</p>'), 'Simple paragraphs can enter the visual editor');
ensure(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<!-- wp:paragraph --><p>Structured</p><!-- /wp:paragraph -->'), 'Simple Gutenberg paragraphs can be edited');
ensure(!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<p>[gallery]</p>'), 'Embedded shortcode stays protected');
