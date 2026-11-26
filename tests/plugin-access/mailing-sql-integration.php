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
// Campaign aggregation must match the latest 15 visible campaigns, not
// expose another editor's campaign or miscount queue statuses.
wp_set_current_user($admin->ID);
$make_newsletter = function ($owner) {
    $id = wp_insert_post(array('post_type' => Dendrila_Simple_Edit_Newsletters::TYPE,
        'post_title' => 'SQL fixture newsletter', 'post_content' => 'Diagnostic only',
        'post_status' => 'draft', 'post_author' => (int) $owner), true);
    mailing_sql_assert(!is_wp_error($id) && (int) $id > 0, 'Fixture newsletter created');
    return (int) $id;
};
$make_campaign = function ($newsletter, $list) use ($wpdb, $prefix, $now) {
    mailing_sql_assert($wpdb->insert($prefix . 'campaigns', array(
        'newsletter_id' => $newsletter, 'list_id' => $list,
        'subject' => 'SQL test campaign', 'html_body' => '<p>Test</p>',
        'status' => 'prepared', 'created_at' => $now)) === 1, 'Fixture campaign created');
    return (int) $wpdb->insert_id;
};
$admin_campaign = $make_campaign($make_newsletter($admin->ID), $other);
$editor_campaign = $make_campaign($make_newsletter($editor->ID), $mine);
foreach (array(
    array($admin_campaign, $confirmed, 'failed'),
    array($editor_campaign, $confirmed, 'queued'),
    array($editor_campaign, $pending, 'accepted'),
) as $entry) {
    mailing_sql_assert($wpdb->insert($prefix . 'queue', array('campaign_id' => $entry[0],
        'contact_id' => $entry[1], 'status' => $entry[2])) === 1, 'Fixture queue row');
}
$campaign_method = new ReflectionMethod('Dendrila_Simple_Edit_Mailing', 'campaign_status_counts');
$campaign_method->setAccessible(true);
$admin_counts = $campaign_method->invoke(null, true);
mailing_sql_assert(count($admin_counts[$editor_campaign] ?? array()) === 2 &&
    count($admin_counts[$admin_campaign] ?? array()) === 1, 'Admin sees grouped campaign statuses');
wp_set_current_user($editor->ID);
$editor_counts = $campaign_method->invoke(null, false);
mailing_sql_assert(!isset($editor_counts[$admin_campaign]) &&
    count($editor_counts[$editor_campaign] ?? array()) === 2,
    'Editor campaign aggregation respects post author');
echo "PASS SQL: fixed prepared aggregates, queue statuses and author/list isolation\n";
