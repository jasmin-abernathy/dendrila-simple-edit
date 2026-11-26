<?php
/**
 * Plugin Name: Menu Access Fixture — Booking
 * Version: 1.0
 */
function dse_lab_booking_can_manage() { return current_user_can('manage_options'); }
function dse_lab_booking_user_can_manage($user) { return user_can($user, 'manage_options'); }
function dse_lab_booking_calls_reports() { return dse_lab_reports_can_manage(); }
function dse_lab_booking_calls_core_user_edit($user) { return current_user_can('edit_user',$user); }
add_filter('dse_lab_shared_manage','dse_lab_booking_can_manage');
function dse_lab_booking_render() {
    if (!current_user_can('manage_options')) { wp_die('Booking denied', '', array('response'=>403)); }
    echo '<h1>Booking fixture</h1>';
    echo '<form method="post" action="'.esc_url(admin_url('options.php')).'">';
    settings_fields('dse_lab_booking_group');
    echo '<input name="dse_lab_booking_title" value="'.esc_attr(get_option('dse_lab_booking_title','')).'"><button>Save booking settings</button></form>';
}
add_action('admin_menu', static function () {
    add_menu_page('Booking fixture', 'Booking fixture', 'manage_options', 'dse-access-booking', 'dse_lab_booking_render');
    add_submenu_page('dse-access-booking', 'Booking settings', 'Settings', 'manage_options', 'dse-access-booking-settings', 'dse_lab_booking_render');
    add_options_page('Booking options','Booking options','manage_options','dse-access-booking-options','dse_lab_booking_render');
});
function dse_lab_booking_register_types() {
    register_post_type('dse_lab_slot',array('label'=>'Booking slots','public'=>false,'show_ui'=>true,'show_in_menu'=>'dse-access-booking','supports'=>array('title'),'map_meta_cap'=>true));
    register_post_type('dse_lab_reservation',array('label'=>'Reservations','public'=>false,'show_ui'=>true,'show_in_menu'=>'dse-access-booking','supports'=>array('title'),'map_meta_cap'=>false,'capabilities'=>array('edit_post'=>'manage_options','read_post'=>'manage_options','delete_post'=>'manage_options','edit_posts'=>'manage_options','publish_posts'=>'manage_options','create_posts'=>'manage_options')));
    register_post_type('dse_lab_locked',array('label'=>'Locked booking data','public'=>false,'show_ui'=>true,'show_in_menu'=>false,'supports'=>array('title'),'capabilities'=>array('create_posts'=>'do_not_allow')));
    register_taxonomy('dse_lab_booking_cat','dse_lab_slot',array('label'=>'Booking categories','show_ui'=>true));
}
add_action('init','dse_lab_booking_register_types');
add_action('admin_init', static function () {
    register_setting('dse_lab_booking_group', 'dse_lab_booking_title', array('sanitize_callback'=>'sanitize_text_field'));
    register_setting('general', 'dse_lab_booking_shared_option', array('sanitize_callback'=>'sanitize_text_field'));
    register_setting('dse_lab_mixed_group','dse_lab_booking_mixed',array('sanitize_callback'=>'sanitize_text_field'));
    register_setting('dse_lab_protected_group','adminhash',array('sanitize_callback'=>'sanitize_text_field'));
});
function dse_lab_booking_ajax() {
    if (!current_user_can('manage_options')) { wp_send_json_error(array('message'=>'Booking denied'),403); }
    check_ajax_referer('dse_lab_booking_ajax','nonce');
    wp_send_json_success(array('plugin'=>'booking'));
}
add_action('wp_ajax_dse_lab_booking_action','dse_lab_booking_ajax');
function dse_lab_booking_post() {
    if (!current_user_can('manage_options')) { wp_die('Booking denied','',array('response'=>403)); }
    check_admin_referer('dse_lab_booking_post');
    update_option('dse_lab_booking_post_saved','yes',false);
    wp_die('Booking saved','',array('response'=>200));
}
add_action('admin_post_dse_lab_booking_save','dse_lab_booking_post');
add_action('rest_api_init',static function () {
    register_rest_route('dse-lab-booking/v1','/permission',array('methods'=>'GET','permission_callback'=>'dse_lab_booking_can_manage','callback'=>static function(){return array('plugin'=>'booking');}));
    register_rest_route('dse-lab-booking/v1','/nested',array('methods'=>'GET','permission_callback'=>'dse_lab_booking_can_manage','callback'=>static function(){
        $inner=rest_do_request(new WP_REST_Request('GET','/dse-lab-reports/v1/alias'));
        return array('inner_status'=>$inner->get_status(),'still_allowed'=>dse_lab_booking_can_manage());
    }));
});
