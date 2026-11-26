<?php
/** SQL integration for mailing counters; only the disposable GitHub WordPress lab. */
$root = getenv('DSE_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "Missing disposable WordPress lab.\n");
    exit(2);
}
define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8098';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
require $root . '/wp-load.php';
if (get_option('dse_plugin_access_test_fixture') !== '1') {
    fwrite(STDERR, "Refusing to run outside the isolated test fixture.\n");
    exit(2);
}
function mailing_sql_assert($condition, $label) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
}
$admin = get_user_by('login', 'menu_admin');
$editor = get_user_by('login', 'menu_simple');
mailing_sql_assert($admin && $editor, 'Fixture users exist');
wp_set_current_user($admin->ID);
Dendrila_Simple_Edit_Mailing::install();
mailing_sql_assert(get_option(Dendrila_Simple_Edit_Mailing::DB_OPTION) === Dendrila_Simple_Edit_Mailing::DB_VERSION,
    'Mailing tables ready');
global $wpdb;
$prefix = $wpdb->prefix . 'dendrila_simple_edit_ml_';
$now = current_time('mysql', true);
$make_list = function ($owner) use ($wpdb, $prefix, $now) {
    mailing_sql_assert($wpdb->insert($prefix . 'lists', array(
        'name' => 'SQL fixture ' . wp_generate_password(10, false),
        'owner_user_id' => $owner, 'created_at' => $now)) === 1, 'Fixture list created');
    return (int) $wpdb->insert_id;
};
$make_contact = function ($status) use ($wpdb, $prefix, $now) {
    mailing_sql_assert($wpdb->insert($prefix . 'contacts', array(
        'email' => 'sql-' . wp_generate_password(14, false) . '@example.test',
        'status' => $status, 'created_at' => $now)) === 1, 'Fixture contact created');
    return (int) $wpdb->insert_id;
};
$add_membership = function ($list, $contact, $status) use ($wpdb, $prefix) {
    mailing_sql_assert($wpdb->insert($prefix . 'members', array(
        'list_id' => $list, 'contact_id' => $contact, 'status' => $status)) === 1,
        'Fixture membership created');
};
$other = $make_list(0);
$mine = $make_list((int) $editor->ID);
$confirmed = $make_contact('confirmed');
$unsubscribed = $make_contact('unsubscribed');
$pending = $make_contact('confirmed');
$add_membership($other, $confirmed, 'confirmed');
$add_membership($mine, $confirmed, 'confirmed');
$add_membership($mine, $unsubscribed, 'confirmed');
$add_membership($mine, $pending, 'pending');
$method = new ReflectionMethod('Dendrila_Simple_Edit_Mailing', 'confirmed_list_counts');
$method->setAccessible(true);
$all = $method->invoke(null, true);
mailing_sql_assert(isset($all[$other], $all[$mine]) && $all[$other] === 1 && $all[$mine] === 1,
    'Admin sees accurate counts, excluding non-confirmed statuses');
wp_set_current_user($editor->ID);
$restricted = $method->invoke(null, false);
mailing_sql_assert(!isset($restricted[$other]) && isset($restricted[$mine]) && $restricted[$mine] === 1,
    'Restricted editor only sees own list counts');
echo "PASS SQL: fixed prepared aggregates, confirmed status and per-owner scope\n";
