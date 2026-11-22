<?php
/** Isolated regression test for type-level scopes and legacy block safety. */
define('ABSPATH', __DIR__);
$GLOBALS['test_options'] = array();
$GLOBALS['test_admin'] = false;
$GLOBALS['test_event_grant'] = false;
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['test_options']) ? $GLOBALS['test_options'][$key] : $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['test_options'][$key] = $value; return true; }
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['test_admin']; }
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
    '<div data-dse-block="text"><p data-dse-color="blue">Texte existant</p></div>';
verify_case(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($old), 'Old Simple Edit blocks retain editability');
verify_case(Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<p>Ordinary paragraph</p>'), 'Simple HTML remains editable');
verify_case(!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<!-- wp:paragraph --><p>Gutenberg</p><!-- /wp:paragraph -->'), 'Gutenberg block protected');
verify_case(!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor('<script>evil()</script>'), 'Unsafe markup protected');
echo "OK — per-type editorial permissions, administrator bypass, old editor block support\n";
