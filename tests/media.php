<?php
define('ABSPATH', __DIR__);
$GLOBALS['dse_options'] = array(
    'dse_enabled_fields' => array('photo'),
    'dse_state' => array('revision' => 0, 'values' => array(), 'history' => array()),
    'dse_media_allowlist' => array(12, 21, 19, 12, 'stray', 23)
);
function get_option($key, $fallback = null) { return $GLOBALS['dse_options'][$key] ?? $fallback; }
function wp_attachment_is_image($id) { return in_array($id, array(12, 19, 21, 23), true); }
function wp_unslash($value) { return stripslashes($value); }
function shortcode_atts($defaults, $atts, $name = '') { return array_merge($defaults, $atts); }
function wp_get_attachment_image($id, $size, $icon = false, $attributes = array()) {
    return '<img src="image-' . $id . '.jpg" alt="Image de test">';
}
require dirname(__DIR__) . '/includes/class-dse-plugin.php';
require dirname(__DIR__) . '/includes/class-dse-media.php';
function ensure_media($test, $message) {
    if (!$test) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
function reject_media($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException $e) { return; }
    ensure_media(false, $message);
}
ensure_media(isset(DSE_Plugin::definitions()['photo']), 'Photo field configured');
ensure_media(DSE_Media::curated_ids() === array(12, 21, 19, 23), 'Valid IDs deduplicated');
ensure_media(DSE_Media::sanitize_selection('19') === '19', 'Approved image selected');
reject_media(function () { DSE_Media::sanitize_selection('5000'); }, 'Unapproved ID refused');
reject_media(function () { DSE_Media::sanitize_selection('not-an-id'); }, 'Invalid ID refused');
reject_media(function () { DSE_Media::sanitize_selection(array(19)); }, 'Array payload refused');
ensure_media(DSE_Plugin::sanitize_field('photo', '12') === '12', 'Photo field uses media whitelist');
ensure_media(DSE_Plugin::shortcode(array('field' => 'photo')) === '', 'Empty media renders empty');
ensure_media(DSE_Media::render_image('19') === '<img src="image-19.jpg" alt="Image de test">', 'Public photo uses attachment APIs');
ensure_media(DSE_Media::render_image('not-an-id') === '', 'Invalid photo cannot render');
$GLOBALS['dse_options']['dse_media_allowlist'] = array(19);
ensure_media(DSE_Media::render_image('12') !== '', 'Published photo survives whitelist narrowing');
reject_media(function () { DSE_Media::sanitize_selection('12'); }, 'Removed image blocked for new selection');
echo "OK — media allowlist, sanitizer, shortcode and publication retention\n";
