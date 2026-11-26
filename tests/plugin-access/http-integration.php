<?php
/** Real HTTP requests, cookies, nonces and native options.php saves. Local lab only. */
$_SERVER['HTTP_HOST']='127.0.0.1:8098';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['PHP_SELF']='/wp-admin/admin.php';
putenv('DSE_LAB_USER=menu_admin');
$wpRoot=getenv('DSE_WP_ROOT');
if (!$wpRoot || !is_file($wpRoot.'/wp-load.php')) { fwrite(STDERR, "Set DSE_WP_ROOT to an isolated installed WordPress.\n"); exit(2); }
require rtrim($wpRoot,'/').'/wp-load.php';
if (get_option('dse_plugin_access_test_fixture') !== '1') { fwrite(STDERR, "Run install-fixtures.php on an isolated test site first.\n"); exit(2); }
$labHttpOrigin=rtrim(home_url(),'/');
$labHost=parse_url($labHttpOrigin,PHP_URL_HOST);
$labPort=(int)(parse_url($labHttpOrigin,PHP_URL_PORT) ?: 80);
if (!in_array($labHost,array('127.0.0.1','localhost'),true) || parse_url($labHttpOrigin,PHP_URL_SCHEME)!=='http') { throw new RuntimeException('HTTP fixture must use a local http://127.0.0.1 site URL.'); }
$outputDir=getenv('DSE_TEST_OUTPUT') ?: sys_get_temp_dir().'/dse-plugin-access';
if (!is_dir($outputDir)) { mkdir($outputDir,0700,true); }
require_once ABSPATH.'wp-admin/includes/admin.php';
$checks=0;
function http_check($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);}++$checks;}
function http_cookies($login) {
    $user=get_user_by('login',$login); $expiration=time()+3600;
    $token=WP_Session_Tokens::get_instance($user->ID)->create($expiration);
    return AUTH_COOKIE.'='.rawurlencode(wp_generate_auth_cookie($user->ID,$expiration,'auth',$token)).'; '.
        LOGGED_IN_COOKIE.'='.rawurlencode(wp_generate_auth_cookie($user->ID,$expiration,'logged_in',$token));
}
function lab_http($path,$cookies,$data=null) {
    global $labHttpOrigin;
    $headers="Cookie: ".$cookies."\r\n";
    $opts=array('method'=>$data===null?'GET':'POST','header'=>$headers,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15);
    if($data!==null){$opts['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$opts['content']=http_build_query($data);}
    $body=file_get_contents($labHttpOrigin.$path,false,stream_context_create(array('http'=>$opts)));
    $status=0; $location='';
    foreach($http_response_header as $header){
        if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m)){$status=(int)$m[1];}
        if(stripos($header,'Location: ')===0){$location=substr($header,10);}
    }
    return array('status'=>$status,'location'=>$location,'body'=>$body);
}
function html_nonce($body){if(!preg_match('/name="_wpnonce" value="([^"]+)"/',$body,$m)){throw new RuntimeException('Nonce missing from real form.');}return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');}
$booking='dse-access-booking/dse-access-booking.php';
$reports='dse-access-reports/dse-access-reports.php';
update_option(Dendrila_Simple_Edit_Admin_Access::OPTION,array($booking),false);
$adminCookies=http_cookies('menu_admin'); $simpleCookies=http_cookies('menu_simple');
$log=fopen($outputDir.'/php-http.log','ab');
$environment=getenv(); unset($environment['DSE_LAB_USER']);
$server=proc_open(array(PHP_BINARY,'-c',php_ini_loaded_file(),'-S','127.0.0.1:'.$labPort,'-t',$wpRoot),array(0=>array('pipe','r'),1=>$log,2=>$log),$pipes,__DIR__,$environment);
if(!is_resource($server)){throw new RuntimeException('Cannot start isolated PHP server.');}
$exitStatus=0;
try {
    $ready=false;
    for($i=0;$i<100;$i++){$socket=@fsockopen('127.0.0.1',$labPort,$errno,$error,.1);if($socket){fclose($socket);$ready=true;break;}usleep(50000);}
    http_check($ready,'PHP HTTP server ready');
    $settingsPath='/wp-admin/admin.php?page=dendrila-simple-edit&dendrila_simple_edit_tab=reglages&dendrila_simple_edit_settings_section=plugins';
    $adminPage=lab_http($settingsPath,$adminCookies);
    http_check($adminPage['status']===200 && strpos($adminPage['body'],'name="dendrila_simple_edit_plugins_action"')!==false,'Admin sees real plugin access form');
    file_put_contents($outputDir.'/admin-settings-render.html',$adminPage['body']);
    $adminNonce=html_nonce($adminPage['body']);
    $valid=lab_http($settingsPath,$adminCookies,array('_wpnonce'=>$adminNonce,'dendrila_simple_edit_plugins_action'=>'save','dendrila_simple_edit_plugins'=>array($booking)));
    http_check($valid['status']===302 && strpos($valid['location'],'dendrila_simple_edit_plugins_saved=1')!==false,'Valid administrator selection saves and redirects');
    wp_cache_delete(Dendrila_Simple_Edit_Admin_Access::OPTION,'options');
    http_check(get_option(Dendrila_Simple_Edit_Admin_Access::OPTION)===array($booking),'Valid selection persisted exactly');
    $invalidNonce=lab_http($settingsPath,$adminCookies,array('_wpnonce'=>'invalid','dendrila_simple_edit_plugins_action'=>'save','dendrila_simple_edit_plugins'=>array($reports)));
    http_check($invalidNonce['status']===403,'Invalid selection nonce rejected');
    $unknown=lab_http($settingsPath,$adminCookies,array('_wpnonce'=>$adminNonce,'dendrila_simple_edit_plugins_action'=>'save','dendrila_simple_edit_plugins'=>array('unknown-plugin/unknown.php')));
    http_check($unknown['status']===400,'Unknown plugin submission rejected');
    $nested=lab_http($settingsPath,$adminCookies,array('_wpnonce'=>$adminNonce,'dendrila_simple_edit_plugins_action'=>'save','dendrila_simple_edit_plugins'=>array(array($reports))));
    http_check($nested['status']===400,'Nested plugin submission rejected');
    $unauthorized=lab_http($settingsPath,$simpleCookies,array('_wpnonce'=>$adminNonce,'dendrila_simple_edit_plugins_action'=>'save','dendrila_simple_edit_plugins'=>array($reports)));
    http_check($unauthorized['status']===403,'Simple editor cannot change its plugin selection');
    wp_cache_delete(Dendrila_Simple_Edit_Admin_Access::OPTION,'options');
    http_check(get_option(Dendrila_Simple_Edit_Admin_Access::OPTION)===array($booking),'Rejected requests leave selection untouched');
    $pluginPage=lab_http('/wp-admin/options-general.php?page=dse-access-booking-options',$simpleCookies);
    http_check($pluginPage['status']===200 && strpos($pluginPage['body'],'Booking fixture')!==false,'Selected add_options_page loads through real WP core routing');
    $pluginNonce=html_nonce($pluginPage['body']);
    $saved=lab_http('/wp-admin/options.php',$simpleCookies,array('option_page'=>'dse_lab_booking_group','action'=>'update','_wpnonce'=>$pluginNonce,'_wp_http_referer'=>'/wp-admin/options-general.php?page=dse-access-booking-options','dse_lab_booking_title'=>'<b>Native save</b>'));
    http_check($saved['status']===302 && strpos($saved['location'],'settings-updated=true')!==false,'Native selected Settings API form reaches options.php and redirects');
    wp_cache_delete('dse_lab_booking_title','options');
    http_check(get_option('dse_lab_booking_title')==='Native save','Native Settings API sanitizer ran and selected option saved');
    $noNonce=lab_http('/wp-admin/options.php',$simpleCookies,array('option_page'=>'dse_lab_booking_group','action'=>'update','_wpnonce'=>'invalid','dse_lab_booking_title'=>'Forbidden write'));
    http_check($noNonce['status']===403 ||
        ($noNonce['status']===302 && strpos($noNonce['location'],'page=dendrila-simple-edit')!==false),
        'Invalid Settings API nonce is rejected before saving');
    wp_cache_delete('dse_lab_booking_title','options');
    http_check(get_option('dse_lab_booking_title')==='Native save',
        'Rejected settings nonce cannot overwrite saved option');
    $reportsSave=lab_http('/wp-admin/options.php',$simpleCookies,array('option_page'=>'dse_lab_reports_group','action'=>'update','_wpnonce'=>$pluginNonce,'dse_lab_reports_title'=>'Forbidden write'));
    http_check($reportsSave['status']===302 && strpos($reportsSave['location'],'page=dendrila-simple-edit')!==false,'Unselected native options group is redirected before mutation');
    wp_cache_delete('dse_lab_reports_title','options');
    http_check(get_option('dse_lab_reports_title',null)===null,'Unselected Settings API option unchanged');
    $coreSave=lab_http('/wp-admin/options.php',$simpleCookies,array('option_page'=>'general','action'=>'update','_wpnonce'=>$pluginNonce,'admin_email'=>'attacker@example.invalid'));
    http_check($coreSave['status']===302 && strpos($coreSave['location'],'page=dendrila-simple-edit')!==false,'Core general settings writer stays outside delegation');
    $unselectedPage=lab_http('/wp-admin/admin.php?page=dse-access-reports',$simpleCookies);
    http_check($unselectedPage['status']===403 || ($unselectedPage['status']===302 && strpos($unselectedPage['location'],'page=dendrila-simple-edit')!==false),'Unselected plugin page inaccessible by URL');
    $clear=lab_http($settingsPath,$adminCookies,array('_wpnonce'=>$adminNonce,'dendrila_simple_edit_plugins_action'=>'save'));
    http_check($clear['status']===302,'Administrator can clear all selected plugins');
    $after=lab_http('/wp-admin/options-general.php?page=dse-access-booking-options',$simpleCookies);
    http_check($after['status']===403 || ($after['status']===302 && strpos($after['location'],'page=dendrila-simple-edit')!==false),'Previously selected plugin is inaccessible after revocation');
    echo 'PASS HTTP: '.$checks." native WordPress request checks\n";
} catch(Throwable $e) {
    fwrite(STDERR,'FAIL HTTP: '.$e->getMessage()."\n");
    $exitStatus=1;
} finally {
    proc_terminate($server); proc_close($server); fclose($log);
}

exit($exitStatus);
