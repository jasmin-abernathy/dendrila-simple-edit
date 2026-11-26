<?php
/** Runs each scenario in a fresh, real WordPress process against this lab only. */
$scenario = isset($argv[1]) ? $argv[1] : '';
if ($scenario === '') {
    $failures = 0;
    $cases = array('catalog','none','booking','reports','both','admin','subscriber','revoke','settings','handlers','content');
    if (getenv('DSE_TEST_CALENDAR') === '1') { $cases[] = 'calendar'; }
    foreach ($cases as $case) {
        $prepare = escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(php_ini_loaded_file()) . ' ' . escapeshellarg(__FILE__) . ' prepare ' . escapeshellarg($case);
        passthru($prepare, $prepareStatus);
        if ($prepareStatus!==0) { ++$failures; continue; }
        $command = escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(php_ini_loaded_file()) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case);
        passthru($command, $status);
        if ($status !== 0) { ++$failures; }
    }
    exit($failures ? 1 : 0);
}
putenv('DSE_LAB_USER='.(in_array($scenario,array('prepare','catalog','admin'),true)?'menu_admin':($scenario==='subscriber'?'menu_subscriber':'menu_simple')));
define('WP_ADMIN', true);
define('DOING_AJAX', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8098';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
$wpRoot=getenv('DSE_WP_ROOT');
if (!$wpRoot || !is_file($wpRoot.'/wp-load.php')) { fwrite(STDERR, "Set DSE_WP_ROOT to an isolated installed WordPress.\n"); exit(2); }
require rtrim($wpRoot,'/').'/wp-load.php';
if (get_option('dse_plugin_access_test_fixture') !== '1') { fwrite(STDERR, "Run install-fixtures.php on an isolated test site first.\n"); exit(2); }
require_once ABSPATH . 'wp-admin/includes/admin.php';
if (!class_exists('Dendrila_Simple_Edit_Admin_Access') || !class_exists('Dendrila_Simple_Edit_Plugin_Access')) {
    fwrite(STDERR, "BLOCKED: new access classes are not yet integrated.\n");
    exit(2);
}
class DseMenuLabExit extends RuntimeException {}
add_filter('wp_die_handler',static function(){return static function($message='',$title='',$args=array()){throw new DseMenuLabExit(is_string($message)?$message:'wp_die');};});
add_filter('wp_die_ajax_handler',static function(){return static function($message='',$title='',$args=array()){throw new DseMenuLabExit(is_string($message)?$message:'wp_die');};});
$checks = 0;
function lab_check($condition,$message) {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    ++$checks;
}
function lab_user($login) {
    $user = get_user_by('login',$login);
    if (!$user) { throw new RuntimeException('Missing isolated fixture user.'); }
    wp_set_current_user($user->ID);
    return $user;
}
function lab_select($plugins) {
    update_option(Dendrila_Simple_Edit_Admin_Access::OPTION,$plugins,false);
}
function lab_menu() {
    global $menu,$submenu,$admin_page_hooks,$_registered_pages,$_parent_pages,$_wp_real_parent_file,
           $_wp_menu_nopriv,$_wp_submenu_nopriv,$pagenow,$plugin_page,$typenow,$taxnow,$parent_file;
    $menu=$submenu=$admin_page_hooks=$_registered_pages=$_parent_pages=$_wp_real_parent_file=array();
    $_wp_menu_nopriv=$_wp_submenu_nopriv=array();
    $pagenow='admin.php'; $plugin_page='dendrila-simple-edit'; $typenow=''; $taxnow=''; $parent_file='';
    $_GET=array('page'=>'dendrila-simple-edit'); $_POST=array(); $_REQUEST=$_GET;
    include ABSPATH . 'wp-admin/menu.php';
    return array_column($menu,2);
}
function lab_route($script,$query=array(),$post=array()) {
    global $pagenow;
    $pagenow=$script;
    // PHP's real query/form parser supplies strings for scalar request values.
    $query=array_map(static function($value){return is_scalar($value)?(string)$value:$value;},$query);
    $post=array_map(static function($value){return is_scalar($value)?(string)$value:$value;},$post);
    $_GET=$query; $_POST=$post; $_REQUEST=array_merge($query,$post);
    return Dendrila_Simple_Edit_Admin_Access::allows_current_page();
}
function lab_no_core_admin() {
    foreach (array('manage_options','activate_plugins','install_plugins','edit_plugins','edit_themes','edit_theme_options','edit_users','create_users','delete_users','promote_users','edit_posts','edit_pages') as $cap) {
        lab_check(!current_user_can($cap),'Direct core permission remains denied: '.$cap);
    }
    lab_check(!lab_route('options-general.php'),'Core General settings route denied');
    lab_check(!lab_route('plugins.php'),'Core plugin list denied');
    lab_check(!lab_route('users.php'),'Core user management denied');
}
function lab_ajax($callback,$action) {
    $_GET=array('nonce'=>wp_create_nonce($action)); $_POST=array(); $_REQUEST=$_GET;
    ob_start();
    try { call_user_func($callback); }
    catch (DseMenuLabExit $e) { /* Expected WordPress JSON termination. */ }
    $body=ob_get_clean();
    $json=json_decode($body,true);
    lab_check(is_array($json),'AJAX response is valid JSON');
    return $json;
}
try {
    $booking='dse-access-booking/dse-access-booking.php';
    $reports='dse-access-reports/dse-access-reports.php';
    if ($scenario==='prepare') {
        $next=$argv[2];
        $selected=in_array($next,array('catalog','none','admin'),true)?array():($next==='calendar'?array('dendrila-calendar/dendrila-calendar.php'):($next==='reports'?array($reports):(in_array($next,array('both','subscriber'),true)?array($booking,$reports):array($booking))));
        lab_select($selected);
        foreach(array('page','dse_lab_slot','dse_lab_reservation','dse_lab_report','dendrila_event','dendrila_profile','dendrila_service','dendrila_booking') as $type) {
            if (!post_type_exists($type)) { continue; }
            $key='dse_lab_fixture_'.$type;
            if (!get_option($key)) {
                $id=wp_insert_post(array('post_type'=>$type,'post_title'=>'Isolated '.$type,'post_status'=>'publish','post_author'=>get_current_user_id()),true);
                if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
                update_option($key,$id,false);
            }
        }
        exit(0);
    }
    if ($scenario==='catalog') {
        lab_user('menu_admin'); lab_select(array()); lab_menu(); do_action('admin_init');
        lab_check(Dendrila_Simple_Edit_Admin_Access::plugin_for_file(WP_PLUGIN_DIR.'/'.$booking)===$booking,'Booking file resolves to active plugin');
        lab_check(Dendrila_Simple_Edit_Admin_Access::plugin_for_file(WP_PLUGIN_DIR.'/'.$reports)===$reports,'Reports file resolves independently');
        lab_check(in_array('manage_options',Dendrila_Simple_Edit_Admin_Access::plugin_permissions($booking),true),'Booking records its actual menu capability');
        lab_check(in_array('manage_options',Dendrila_Simple_Edit_Admin_Access::plugin_permissions($reports),true),'Reports records its actual menu capability');
    } elseif ($scenario==='admin') {
        lab_user('menu_admin'); lab_select(array()); $slugs=lab_menu();
        lab_check(current_user_can('manage_options'),'Administrator retains core manage_options');
        lab_check(dse_lab_booking_can_manage() && dse_lab_reports_can_manage(),'Administrator retains both plugins');
        lab_check(in_array('dse-access-booking',$slugs,true) && in_array('dse-access-reports',$slugs,true),'Administrator sees both plugins');
    } elseif ($scenario==='subscriber') {
        lab_user('menu_subscriber'); lab_select(array($booking,$reports));
        lab_check(!current_user_can('manage_options'),'Subscriber has no core admin permission');
        lab_check(!dse_lab_booking_can_manage() && !dse_lab_reports_can_manage(),'Subscriber does not inherit Simple Edit delegation');
        lab_check(!current_user_can(Dendrila_Simple_Edit_Admin_Access::plugin_capability($booking)),'Subscriber cannot use synthetic plugin permission');
    } elseif ($scenario==='settings') {
        lab_user('menu_simple'); lab_select(array($booking)); lab_menu(); do_action('admin_init');
        $_POST=array('option_page'=>'dse_lab_booking_group','action'=>'update'); $_REQUEST=$_POST; $GLOBALS['pagenow']='options.php';
        $_GET=array(); $_SERVER['REQUEST_METHOD']='POST';
        $bookingCap=apply_filters('option_page_capability_dse_lab_booking_group','manage_options');
        lab_check(current_user_can($bookingCap),'Selected own Settings API group accepts synthetic cap');
        lab_check(Dendrila_Simple_Edit_Plugin_Access::allows_settings_request(),'Selected own settings save is routed');
        $_POST['option_page']='dse_lab_reports_group'; $_REQUEST=$_POST;
        $reportsCap=apply_filters('option_page_capability_dse_lab_reports_group','manage_options');
        lab_check(!current_user_can($reportsCap),'Unselected Settings API group denied');
        lab_check(!Dendrila_Simple_Edit_Plugin_Access::allows_settings_request(),'Unselected settings save not routed');
        $_POST['option_page']='general'; $_REQUEST=$_POST;
        lab_check(!current_user_can(apply_filters('option_page_capability_general','manage_options')),'Shared core General settings group stays denied');
        lab_check(!Dendrila_Simple_Edit_Plugin_Access::allows_settings_request(),'Shared core General settings route stays denied');
        foreach(array('dse_lab_mixed_group','dse_lab_protected_group') as $group) {
            $_POST['option_page']=$group; $_REQUEST=$_POST;
            lab_check(!Dendrila_Simple_Edit_Plugin_Access::allows_settings_request(),'Mixed/protected settings group denied: '.$group);
        }
        $_POST['option_page']='dse_lab_booking_group'; $_REQUEST=$_POST; $_GET['adminhash']='forged';
        lab_check(!Dendrila_Simple_Edit_Plugin_Access::allows_settings_request(),'options.php adminhash special action denied');
        lab_no_core_admin();
    } elseif ($scenario==='handlers') {
        lab_user('menu_simple'); lab_select(array($booking)); lab_menu(); do_action('admin_init');
        $result=lab_ajax('dse_lab_booking_ajax','dse_lab_booking_ajax');
        lab_check($result['success']===true && $result['data']['plugin']==='booking','Selected plugin AJAX succeeds with its nonce');
        $result=lab_ajax('dse_lab_reports_ajax','dse_lab_reports_ajax');
        lab_check($result['success']===false,'Unselected plugin AJAX denied with a valid nonce');
        $_POST=array('_wpnonce'=>wp_create_nonce('dse_lab_booking_post')); $_GET=array(); $_REQUEST=$_POST;
        try { dse_lab_booking_post(); } catch (DseMenuLabExit $e) {}
        lab_check(get_option('dse_lab_booking_post_saved')==='yes','Selected plugin admin-post saves its own option');
        do_action('rest_api_init');
        $bookingResponse=rest_do_request(new WP_REST_Request('GET','/dse-lab-booking/v1/permission'));
        $reportsResponse=rest_do_request(new WP_REST_Request('GET','/dse-lab-reports/v1/permission'));
        lab_check($bookingResponse->get_status()===200,'Selected plugin REST permission callback succeeds');
        lab_check($reportsResponse->get_status()===403,'Unselected plugin REST permission callback denied');
        lab_check(!dse_lab_booking_calls_reports(),'Selected plugin cannot confer permission on a nested unselected plugin callback');
        lab_check(!dse_lab_reports_calls_booking(),'Unselected plugin cannot borrow a selected plugin permission helper');
        lab_check(!dse_lab_reports_filtered_can_manage(),'Unselected plugin cannot borrow selected permission through a core filter');
        $borrowedAjax=lab_ajax('dse_lab_reports_borrow_ajax','dse_lab_reports_borrow_ajax');
        lab_check($borrowedAjax['success']===false,'Unselected AJAX cannot borrow selected plugin permission helper');
        $borrowedRest=rest_do_request(new WP_REST_Request('GET','/dse-lab-reports/v1/borrow'));
        $filteredRest=rest_do_request(new WP_REST_Request('GET','/dse-lab-reports/v1/filtered'));
        lab_check($borrowedRest->get_status()===403,'Unselected REST cannot borrow selected plugin permission helper');
        lab_check($filteredRest->get_status()===403,'Unselected REST cannot borrow selected permission through core filter');
        $aliasedRest=rest_do_request(new WP_REST_Request('GET','/dse-lab-reports/v1/alias'));
        lab_check($aliasedRest->get_status()===403,'Unselected REST business handler cannot alias selected permission callback');
        $nestedRest=rest_do_request(new WP_REST_Request('GET','/dse-lab-booking/v1/nested'));
        $nestedData=$nestedRest->get_data();
        lab_check($nestedRest->get_status()===200 && $nestedData['inner_status']===403 && $nestedData['still_allowed']===true,'Nested REST request restores selected outer plugin context');
        lab_check(!dse_lab_booking_user_can_manage(get_user_by('login','menu_subscriber')),'Selected plugin cannot confer permission on another user');
        lab_check(!dse_lab_booking_calls_core_user_edit(get_user_by('login','menu_admin')->ID),'Selected plugin cannot confer core user edit capability');
        lab_no_core_admin();
    } elseif ($scenario==='calendar') {
        lab_user('menu_simple'); $slugs=lab_menu();
        lab_check(in_array('dendrila-calendar',$slugs,true),'Unmodified Calendar menu is visible');
        foreach(array('dendrila-calendar','dendrila-calendar-sync','dendrila-calendar-profiles','dendrila-calendar-services','dendrila-calendar-bookings','dendrila-calendar-setup') as $slug) {
            lab_check(lab_route('admin.php',array('page'=>$slug)),'Unmodified Calendar page allowed: '.$slug);
        }
        foreach(array('dendrila_event','dendrila_profile','dendrila_service','dendrila_booking') as $type) {
            $id=(int)get_option('dse_lab_fixture_'.$type);
            lab_check(current_user_can('edit_post',$id),'Unmodified Calendar CPT edit permission: '.$type);
            lab_check(lab_route('post.php',array('post'=>$id,'action'=>'edit')),'Unmodified Calendar CPT editor route: '.$type);
            lab_check(lab_route('post-new.php',array('post_type'=>$type)),'Unmodified Calendar CPT create route: '.$type);
        }
        $_GET=array('nonce'=>wp_create_nonce('dendrila_calendar_events'),'start'=>'2026-11-01','end'=>'2026-12-01'); $_POST=array(); $_REQUEST=$_GET;
        ob_start(); try { Dendrila_Calendar::ajax_events(); } catch(DseMenuLabExit $e) {}
        $json=json_decode(ob_get_clean(),true);
        lab_check(is_array($json) && $json['success']===true,'Unmodified Calendar events AJAX succeeds');
        lab_no_core_admin();
        lab_select(array());
        $_GET=array('nonce'=>wp_create_nonce('dendrila_calendar_events'),'start'=>'2026-11-01','end'=>'2026-12-01'); $_REQUEST=$_GET;
        ob_start(); try { Dendrila_Calendar::ajax_events(); } catch(DseMenuLabExit $e) {}
        $json=json_decode(ob_get_clean(),true);
        lab_check(is_array($json) && $json['success']===false,'Calendar events AJAX revoked immediately');
        lab_check(!current_user_can('edit_post',(int)get_option('dse_lab_fixture_dendrila_event')),'Calendar native edit permission revoked immediately');
    } elseif ($scenario==='content') {
        lab_user('menu_simple'); lab_select(array($booking)); lab_menu();
        $owned=Dendrila_Simple_Edit_Plugin_Access::owned_post_types();
        lab_check(isset($owned['dse_lab_slot']) && $owned['dse_lab_slot']===$booking,'Mapped CPT ownership captured from actual registration');
        lab_check(isset($owned['dse_lab_reservation']) && $owned['dse_lab_reservation']===$booking,'Unmapped CPT ownership captured');
        foreach(array('dse_lab_slot','dse_lab_reservation') as $type) {
            $id=(int)get_option('dse_lab_fixture_'.$type);
            $object=get_post_type_object($type);
            lab_check(current_user_can($object->cap->edit_posts),'Selected CPT edit_posts allowed for '.$type);
            lab_check(current_user_can('edit_post',$id),'Selected CPT edit_post allowed for '.$type);
            lab_check(lab_route('edit.php',array('post_type'=>$type)),'Selected CPT list route allowed for '.$type);
            lab_check(lab_route('post-new.php',array('post_type'=>$type)),'Selected CPT creation route allowed for '.$type);
            lab_check(lab_route('post.php',array('post'=>$id,'action'=>'edit')),'Selected actual CPT edit route allowed for '.$type);
            lab_check(lab_route('post.php',array(),array('post_ID'=>$id,'action'=>'editpost')),'Selected actual CPT save route allowed for '.$type);
        }
        $pageId=(int)get_option('dse_lab_fixture_page');
        $reportId=(int)get_option('dse_lab_fixture_dse_lab_report');
        lab_check(!current_user_can('edit_post',$pageId),'Core page remains protected');
        lab_check(!current_user_can('edit_post',$reportId),'Unselected plugin CPT remains protected');
        lab_check(!lab_route('post.php',array('post'=>$pageId,'post_type'=>'dse_lab_slot','action'=>'edit')),'Forged post_type cannot open core page');
        lab_check(!lab_route('post.php',array(),array('post_ID'=>$pageId,'post_type'=>'dse_lab_slot','action'=>'editpost')),'Forged posted post_type cannot save core page');
        lab_check(!lab_route('post.php',array('post'=>$reportId,'post_type'=>'dse_lab_slot','action'=>'edit')),'Forged post_type cannot open another plugin record');
        lab_check(!current_user_can(get_post_type_object('dse_lab_locked')->cap->create_posts),'Explicit do_not_allow preserved');
        $tax=get_taxonomy('dse_lab_booking_cat');
        lab_check(current_user_can($tax->cap->manage_terms),'Selected plugin taxonomy permission allowed');
        lab_check(!current_user_can(get_taxonomy('category')->cap->manage_terms),'Core taxonomy permission remains denied');
        lab_no_core_admin();
    } else {
        lab_user('menu_simple');
        $selected=$scenario==='none'?array():($scenario==='reports'?array($reports):($scenario==='both'?array($booking,$reports):array($booking)));
        lab_select($selected); $slugs=lab_menu();
        $bookingOn=in_array($booking,$selected,true); $reportsOn=in_array($reports,$selected,true);
        lab_check(in_array('dendrila-simple-edit',$slugs,true),'Simple Edit remains visible');
        lab_check(in_array('dse-access-booking',$slugs,true)===$bookingOn,'Booking visibility follows selection');
        lab_check(in_array('dse-access-reports',$slugs,true)===$reportsOn,'Reports visibility follows independent selection');
        lab_check(dse_lab_booking_can_manage()===$bookingOn,'Booking internal permission follows selection');
        lab_check(dse_lab_reports_can_manage()===$reportsOn,'Reports internal permission follows selection');
        lab_check(lab_route('admin.php',array('page'=>'dse-access-booking'))===$bookingOn,'Booking main route follows selection');
        lab_check(lab_route('admin.php',array('page'=>'dse-access-booking-settings'))===$bookingOn,'Booking submenu route follows selection');
        lab_check(lab_route('options-general.php',array('page'=>'dse-access-booking-options'))===$bookingOn,'add_options_page wrapper route follows selection');
        lab_check(lab_route('admin.php',array('page'=>'dse-access-reports'))===$reportsOn,'Reports main route follows selection');
        lab_check(lab_route('admin.php',array('page'=>'dse-access-reports-settings'))===$reportsOn,'Reports submenu route follows selection');
        lab_check(!lab_route('admin.php',array('page'=>'dse-access-booking-forged')),'Slug prefixes cannot authorize unrelated pages');
        lab_check(!lab_route('admin.php',array('page'=>array('dse-access-booking'))),'Array page payload rejected');
        lab_no_core_admin();
        if ($scenario==='both') {
            lab_check(dse_lab_reports_calls_booking(),'Two selected plugins can share the Booking authorization helper');
            lab_check(dse_lab_booking_calls_reports(),'Two selected plugins can share the Reports authorization helper');
            do_action('rest_api_init');
            $bothAlias=rest_do_request(new WP_REST_Request('GET','/dse-lab-reports/v1/alias'));
            lab_check($bothAlias->get_status()===200,'REST callback alias is allowed when both owning plugins are selected');
        }
        if ($scenario==='revoke') {
            lab_select(array());
            lab_check(!dse_lab_booking_can_manage(),'Revocation withdraws plugin callback permission in same request');
            lab_check(!current_user_can(Dendrila_Simple_Edit_Admin_Access::plugin_capability($booking)),'Revocation withdraws synthetic cap in same request');
            lab_check(!lab_route('admin.php',array('page'=>'dse-access-booking')),'Revocation withdraws direct route in same request');
            $role=get_role('dendrila_simple_editor');
            lab_check(empty($role->capabilities['manage_options']),'No permanent global administrator capability was added to role');
        }
    }
    echo 'PASS '.$scenario.': '.$checks." real WordPress checks\n";
} catch (Throwable $e) {
    fwrite(STDERR,'FAIL '.$scenario.': '.$e->getMessage()."\n");
    exit(1);
}
