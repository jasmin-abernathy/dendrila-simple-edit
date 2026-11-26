<?php
/** Contract for explicit newsletter import consent (standalone PHP). */
define('ABSPATH', __DIR__);
function wp_unslash($value) { return stripslashes($value); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-mailing.php';
$method = new ReflectionMethod('Dendrila_Simple_Edit_Mailing', 'explicit_optin');
$method->setAccessible(true);
$values = array(
    array(array(), false, 'missing'),
    array(array('consent_confirmed' => '0'), false, 'unchecked'),
    array(array('consent_confirmed' => 'yes'), false, 'noncanonical'),
    array(array('consent_confirmed' => array('1')), false, 'array'),
    array(array('consent_confirmed' => '1'), true, 'explicit opt-in'),
);
foreach ($values as $item) {
    $_POST = $item[0];
    if ($method->invoke(null, 'consent_confirmed') !== $item[1]) {
        fwrite(STDERR, 'FAIL: ' . $item[2] . "\n");
        exit(1);
    }
}
echo "OK - explicit import consent is mandatory; missing, forged or array values rejected\n";
