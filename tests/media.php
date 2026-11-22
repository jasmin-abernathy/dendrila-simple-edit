<?php
define('ABSPATH', __DIR__);
$GLOBALS['dendrila_simple_edit_options'] = array(
    'dendrila_simple_edit_enabled_fields' => array('photo'),
    'dendrila_simple_edit_state' => array('revision' => 0, 'values' => array(), 'history' => array()),
    'dendrila_simple_edit_media_allowlist' => array(12, 21, 19, 12, 'stray', 23)
);
function current_user_can($capability) { return $capability === 'dendrila_simple_edit_access'; }
function get_current_user_id() { return 7; }
function get_posts($query) { return array(); }
function get_post($id) { return null; }
function get_option($key, $fallback = null) { return $GLOBALS['dendrila_simple_edit_options'][$key] ?? $fallback; }
function wp_attachment_is_image($id) { return in_array($id, array(12, 19, 21, 23), true); }
function wp_unslash($value) { return stripslashes($value); }
function shortcode_atts($defaults, $atts, $name = '') { return array_merge($defaults, $atts); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function selected($current, $expected, $echo = true) { return $current === $expected ? 'selected' : ''; }
function get_the_title($id) { return 'Image ' . $id; }

function wp_get_attachment_image($id, $size, $icon = false, $attributes = array()) {
    return '<img src="image-' . $id . '.jpg" alt="Image de test">';
}
require_once dirname(__DIR__) . '/includes/class-dendrila-simple-edit-approvals.php';
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-plugin.php';
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-media.php';
function ensure_media($test, $message) {
    if (!$test) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
function reject_media($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException $e) { return; }
    ensure_media(false, $message);
}
ensure_media(isset(Dendrila_Simple_Edit_Plugin::definitions()['photo']), 'Photo field configured');
ensure_media(Dendrila_Simple_Edit_Media::curated_ids() === array(12, 21, 19, 23), 'Valid IDs deduplicated');
ensure_media(Dendrila_Simple_Edit_Media::sanitize_selection('19') === '19', 'Approved image selected');
reject_media(function () { Dendrila_Simple_Edit_Media::sanitize_selection('5000'); }, 'Unapproved ID refused');
reject_media(function () { Dendrila_Simple_Edit_Media::sanitize_selection('not-an-id'); }, 'Invalid ID refused');
reject_media(function () { Dendrila_Simple_Edit_Media::sanitize_selection(array(19)); }, 'Array payload refused');
ensure_media(Dendrila_Simple_Edit_Plugin::sanitize_field('photo', '12') === '12', 'Photo field uses media whitelist');
ensure_media(Dendrila_Simple_Edit_Plugin::shortcode(array('field' => 'photo')) === '', 'Empty media renders empty');
ensure_media(Dendrila_Simple_Edit_Media::render_image('19') === '<img src="image-19.jpg" alt="Image de test">', 'Public photo uses attachment APIs');
ensure_media(Dendrila_Simple_Edit_Media::render_image('not-an-id') === '', 'Invalid photo cannot render');
$GLOBALS['dendrila_simple_edit_options']['dendrila_simple_edit_media_allowlist'] = array(19);
ensure_media(Dendrila_Simple_Edit_Media::render_image('12') !== '', 'Published photo survives whitelist narrowing');
reject_media(function () { Dendrila_Simple_Edit_Media::sanitize_selection('12'); }, 'Removed image blocked for new selection');
ob_start();
Dendrila_Simple_Edit_Media::render_picker('photo', '12', 'dendrila-simple-edit-image');
$html = ob_get_clean();
ensure_media(strpos($html, 'value="12" selected') !== false, 'Revoked current photo is displayed as the current value');
ensure_media(strpos($html, 'name="dendrila_simple_edit_fields[photo]"') !== false, 'Picker uses scoped field');
echo "OK — media allowlist, sanitizer, shortcode and publication retention\n";
