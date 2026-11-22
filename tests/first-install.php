<?php
/** Regression: first install shows wizard; update/reactivation does not reset it. */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$GLOBALS['dse_first_install_options'] = array();
function get_option($name, $fallback = false) {
    return array_key_exists($name, $GLOBALS['dse_first_install_options']) ? $GLOBALS['dse_first_install_options'][$name] : $fallback;
}
function add_option($name, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['dse_first_install_options'])) { return false; }
    $GLOBALS['dse_first_install_options'][$name] = $value;
    return true;
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['dse_first_install_options'][$name] = $value;
    return true;
}
function delete_option($name) { unset($GLOBALS['dse_first_install_options'][$name]); return true; }
function dse_first_install_assert($pass, $message) { if (!$pass) {fwrite(STDERR,"FAIL: $message\n");exit(1);} }
require_once dirname(__DIR__).'/includes/class-dendrila-simple-edit-setup.php';
$setup='Dendrila_Simple_Edit_Setup';
$setup::on_activate();
dse_first_install_assert(!$setup::completed(), 'fresh install must begin unconfigured');
dse_first_install_assert($setup::needs_wizard(), 'fresh install must show guided setup');
dse_first_install_assert(get_option($setup::REDIRECT_OPTION)==='1', 'first installation must set pending redirect');
dse_first_install_assert(get_option($setup::STEP_OPTION)===1, 'first installation starts on step 1');
update_option($setup::OPTION,'1');
update_option($setup::STEP_OPTION,5);
delete_option($setup::REDIRECT_OPTION);
update_option('dendrila_simple_edit_modules',array('newsletters'=>array('enabled'=>true,'roles'=>array('editor'=>array('send')))));
$before=$GLOBALS['dse_first_install_options'];
$setup::on_activate();
dse_first_install_assert($GLOBALS['dse_first_install_options']===$before,'reactivation must leave every setting unchanged');
dse_first_install_assert(!$setup::needs_wizard(),'reactivation must not reopen wizard');
update_option($setup::REVIEW_OPTION,'1');
dse_first_install_assert($setup::needs_wizard(),'manual review should open wizard without changing completed flag');
dse_first_install_assert($setup::completed(),'manual review must preserve completed state');
$setup::on_activate();
dse_first_install_assert(get_option($setup::STEP_OPTION)===5,'activation while manual review must not reset progress');
delete_option($setup::REVIEW_OPTION);
dse_first_install_assert(!$setup::needs_wizard(),'leaving manual review must return to administration');
// Even an unfinished previous install must not be reset during reactivation.
update_option($setup::OPTION,'0');
update_option($setup::STEP_OPTION,4);
$setup::on_activate();
dse_first_install_assert(get_option($setup::STEP_OPTION)===4,'incomplete previously started setup keeps current step');
echo "OK: first install, reactivation, update and manual review semantics\n";
