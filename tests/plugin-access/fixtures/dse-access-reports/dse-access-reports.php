<?php
/**
 * Plugin Name: Menu Access Fixture — Reports
 * Version: 1.0
 */
function dse_lab_reports_can_manage() { return current_user_can('manage_options'); }
function dse_lab_reports_calls_booking() { return dse_lab_booking_can_manage(); }
function dse_lab_reports_filtered_can_manage() { return apply_filters('dse_lab_shared_manage',false); }
function dse_lab_reports_borrow_ajax() {
    check_ajax_referer('dse_lab_reports_borrow_ajax','nonce');
    if (!dse_lab_booking_can_manage()) { wp_send_json_error(array('message'=>'Reports denied'),403); }
    wp_send_json_success(array('plugin'=>'reports'));
}
add_action('wp_ajax_dse_lab_reports_borrow_ajax','dse_lab_reports_borrow_ajax');
function dse_lab_reports_render() {
    if (!current_user_can('manage_options')) { wp_die('Reports denied','',array('response'=>403)); }
    echo '<h1>Reports fixture</h1>';
}
add_action('admin_menu',static function () {
    add_menu_page('Reports fixture','Reports fixture','manage_options','dse-access-reports','dse_lab_reports_render');
    add_submenu_page('dse-access-reports','Reports settings','Settings','manage_options','dse-access-reports-settings','dse_lab_reports_render');
});
add_action('admin_init',static function () {
    register_setting('dse_lab_reports_group','dse_lab_reports_title',array('sanitize_callback'=>'sanitize_text_field'));
    register_setting('general','dse_lab_reports_shared_option',array('sanitize_callback'=>'sanitize_text_field'));
    register_setting('dse_lab_mixed_group','dse_lab_reports_mixed',array('sanitize_callback'=>'sanitize_text_field'));
});
add_action('init',static function() {
    register_post_type('dse_lab_report',array('label'=>'Private reports','public'=>false,'show_ui'=>true,'show_in_menu'=>'dse-access-reports','supports'=>array('title'),'capabilities'=>array('edit_posts'=>'manage_options','edit_post'=>'manage_options','read_post'=>'manage_options','delete_post'=>'manage_options'),'map_meta_cap'=>false));
});
function dse_lab_reports_ajax() {
    if (!current_user_can('manage_options')) { wp_send_json_error(array('message'=>'Reports denied'),403); }
    check_ajax_referer('dse_lab_reports_ajax','nonce');
    wp_send_json_success(array('plugin'=>'reports'));
}
add_action('wp_ajax_dse_lab_reports_action','dse_lab_reports_ajax');
add_action('rest_api_init',static function () {
    register_rest_route('dse-lab-reports/v1','/permission',array('methods'=>'GET','permission_callback'=>'dse_lab_reports_can_manage','callback'=>static function(){return array('plugin'=>'reports');}));
    register_rest_route('dse-lab-reports/v1','/borrow',array('methods'=>'GET','permission_callback'=>'dse_lab_reports_calls_booking','callback'=>static function(){return array('plugin'=>'reports');}));
    register_rest_route('dse-lab-reports/v1','/filtered',array('methods'=>'GET','permission_callback'=>'dse_lab_reports_filtered_can_manage','callback'=>static function(){return array('plugin'=>'reports');}));
    register_rest_route('dse-lab-reports/v1','/alias',array('methods'=>'GET','permission_callback'=>'dse_lab_booking_can_manage','callback'=>static function(){return array('plugin'=>'reports');}));
});
