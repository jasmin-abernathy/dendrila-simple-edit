<?php
/** Install fixtures ONLY in a disposable WordPress database and content folder. */
$root=getenv('DSE_WP_ROOT');
if (getenv('DSE_PLUGIN_ACCESS_ALLOW_INSTALL')!=='1' || !$root || !is_file($root.'/wp-load.php')) {
    fwrite(STDERR,"Set DSE_WP_ROOT and DSE_PLUGIN_ACCESS_ALLOW_INSTALL=1 for a disposable WordPress test installation.\n");
    exit(2);
}
$content=rtrim($root,'/').'/wp-content';
foreach(array('plugins/dse-access-booking','plugins/dse-access-reports','mu-plugins') as $path) {
    if (!is_dir($content.'/'.$path)) { mkdir($content.'/'.$path,0755,true); }
}
foreach(array('dse-access-booking','dse-access-reports') as $slug) {
    copy(__DIR__.'/fixtures/'.$slug.'/'.$slug.'.php',$content.'/plugins/'.$slug.'/'.$slug.'.php');
}
copy(__DIR__.'/fixtures/isolation.php',$content.'/mu-plugins/dse-plugin-access-isolation.php');
$_SERVER['HTTP_HOST']='127.0.0.1:8098'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='/wp-admin/admin.php';
require rtrim($root,'/').'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$plugins=array('dendrila-simple-edit/dendrila-simple-edit.php','dse-access-booking/dse-access-booking.php','dse-access-reports/dse-access-reports.php');
if (getenv('DSE_TEST_CALENDAR')==='1') { $plugins[]='dendrila-calendar/dendrila-calendar.php'; }
foreach($plugins as $plugin) {
    $result=activate_plugin($plugin);
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
}
foreach(array('menu_admin'=>'administrator','menu_simple'=>'dendrila_simple_editor','menu_subscriber'=>'subscriber') as $login=>$role) {
    if (!username_exists($login)) {
        $id=wp_insert_user(array('user_login'=>$login,'user_pass'=>wp_generate_password(32,true),'user_email'=>$login.'@example.invalid','role'=>$role));
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    }
}
update_option('dendrila_simple_edit_setup_completed_v1','1');
delete_option('dendrila_simple_edit_setup_redirect_v1');
delete_option('dendrila_calendar_setup_redirect');
update_option('dendrila_calendar_setup',array('completed'=>true,'sources'=>array('dendrila'),'mode'=>'local'));
update_option('dse_plugin_access_test_fixture','1',false);
update_option('blog_public',0);
echo "Installed isolated plugin access fixtures. Outbound WordPress HTTP and email are disabled by their MU plugin.\n";
