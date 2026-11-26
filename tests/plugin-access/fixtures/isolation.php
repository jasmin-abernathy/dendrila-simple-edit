<?php
/** Local lab has no outbound email or HTTP. */
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static function () {
    return new WP_Error('isolated_lab', 'External HTTP is disabled in the menu-access test lab.');
}, 1);
// CLI requests use real WP users before init, when CPT capabilities register.
add_filter('determine_current_user',static function($id) {
    $login=getenv('DSE_LAB_USER');
    if (!$login) { return $id; }
    $user=get_user_by('login',$login);
    return $user ? $user->ID : $id;
},100);
