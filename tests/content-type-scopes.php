<?php
/** Isolated regression test for type-level scopes and safe Gutenberg text editing. */
define('ABSPATH', __DIR__);
$GLOBALS['test_options'] = array();
$GLOBALS['test_admin'] = false;
$GLOBALS['test_event_grant'] = false;
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['test_options']) ? $GLOBALS['test_options'][$key] : $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['test_options'][$key] = $value; return true; }
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['test_admin']; }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function wp_strip_all_tags($value) { return strip_tags((string)$value); }
function parse_blocks($content) {
    $blocks = array();
    preg_match_all('~<!-- wp:(paragraph|heading)(?: (\\{[^>]+\\}))? -->([\\s\\S]*?)<!-- /wp:\\1 -->~', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attrs = !empty($match[2]) ? json_decode($match[2], true) : array();
        $blocks[] = array('blockName' => 'core/' . $match[1], 'attrs' => is_array($attrs) ? $attrs : array(),
            'innerBlocks' => array(), 'innerHTML' => $match[3]);
    }
    return $blocks;
}
final class Dendrila_Simple_Edit_Modules {
    public static function allowed($module, $action) {
        return $module === 'editorial' && $action === 'edit_events' && $GLOBALS['test_event_grant'];
    }
}
require_once __DIR__ . '/../includes/class-dendrila-simple-edit-block-editor.php';
require_once __DIR__ . '/../includes/class-dendrila-simple-edit-post-editor.php';
require_once __DIR__ . '/../includes/class-dendrila-simple-edit-event-editor.php';
function verify_case($ok, $label) { if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } }
verify_case(Dendrila_Simple_Edit_Post_Editor::editable_types() === array('page', 'post', 'tribe_events'), 'Default all types for legacy installs');
verify_case(Dendrila_Simple_Edit_Post_Editor::type_enabled('page'), 'Existing pages enabled without individual selections');
$GLOBALS['test_admin'] = true;
verify_case(Dendrila_Simple_Edit_Post_Editor::save_types(array('post', 'tribe_events')), 'Admin can configure types');
$GLOBALS['test_admin'] = false;
verify_case(!Dendrila_Simple_Edit_Post_Editor::type_enabled('page'), 'Site type restriction for delegated roles');
verify_case(Dendrila_Simple_Edit_Post_Editor::type_enabled('post'), 'Article type remains available');
verify_case(!Dendrila_Simple_Edit_Post_Editor::save_types(array('page')), 'Nonadmin cannot configure types');
$ref = new ReflectionMethod('Dendrila_Simple_Edit_Event_Editor', 'can_modify_events');
if (PHP_VERSION_ID < 80100) { $ref->setAccessible(true); }
verify_case(!$ref->invoke(null), 'Delegated events denied without role action');
$GLOBALS['test_event_grant'] = true;
verify_case($ref->invoke(null), 'Delegated events enabled by role and type');
$GLOBALS['test_admin'] = true;
verify_case(Dendrila_Simple_Edit_Post_Editor::type_enabled('page'), 'Admin bypasses site type restrictions');
verify_case($ref->invoke(null), 'Admin bypasses event role action');
verify_case(Dendrila_Simple_Edit_Post_Editor::save_types(array()), 'Admin may disable delegated types without affecting admin');
$GLOBALS['test_admin'] = false;
verify_case(!$ref->invoke(null), 'Type restriction blocks delegated events');
verify_case(!Dendrila_Simple_Edit_Post_Editor::save_types(array('invalid')), 'Unknown type blocked');
$old = '<div data-dse-block="heading" data-dse-align="center"><h2 data-dse-color="green" data-dse-align="center">Bienvenue</h2></div>' . "\n" .
    '<div data-dse-block="text"><p data-dse-color="blue">Texte existant</p></div>' . "\n" .
    '<div data-dse-block="button" data-dse-align="center"><a href="https://example.test/" class="dse-newsletter-cta" data-dse-color="green">Découvrir le laboratoire</a></div>' . "\n" .
    '<div data-dse-block="link"><a href="https://example.test/">Retour au laboratoire</a></div>';
verify_case(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($old), 'Legacy Simple Edit pages with headings, text, buttons and links remain editable');
verify_case(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<p>Ordinary paragraph</p>'), 'Simple HTML remains editable');
verify_case(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<!-- wp:paragraph --><p>Gutenberg</p><!-- /wp:paragraph -->'), 'Simple Gutenberg paragraph is editable');
verify_case(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<!-- wp:heading --><h2>Intertitre</h2><!-- /wp:heading -->'), 'Simple Gutenberg heading is editable');
verify_case(strpos(Dendrila_Simple_Edit_Block_Editor::gutenberg_editor_content('<!-- wp:paragraph --><p>Gutenberg</p><!-- /wp:paragraph -->'), 'data-dendrila-simple-edit-block="text"') !== false, 'Simple Gutenberg paragraph is converted into a visual editor block');
verify_case(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<!-- wp:paragraph --><p><strong>Texte riche</strong></p><!-- /wp:paragraph -->'), 'Rich Gutenberg paragraph can be edited without rewriting unchanged markup');
verify_case(!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<!-- wp:columns --><div class="wp-block-columns"></div><!-- /wp:columns -->'), 'Complex Gutenberg layout stays protected');
verify_case(!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<script>evil()</script>'), 'Unsafe markup protected');
echo "OK — per-type editorial permissions, administrator bypass, old editor block support\n";
