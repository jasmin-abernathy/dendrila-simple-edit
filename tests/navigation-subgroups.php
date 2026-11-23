<?php
/** Pure-PHP contract tests for Dendrila Simple Edit navigation; no WordPress database required. */
define('ABSPATH', '/fake/');
define('DENDRILA_SIMPLE_EDIT_PLUGIN_FILE', __FILE__);
define('DENDRILA_SIMPLE_EDIT_VERSION', '0.0.1');
$GLOBALS['nav_post']=(object)array('ID'=>4,'post_type'=>'wp_navigation','post_content'=>'<!-- wp:page-list /-->','post_modified_gmt'=>'2026-11-22 20:00:00');
$GLOBALS['pages']=array();
foreach (array(11=>'Accueil',22=>'À propos',33=>'Contact') as $id=>$title) {
    $GLOBALS['pages'][$id]=(object)array('ID'=>$id,'post_type'=>'page','post_status'=>'publish',
        'post_title'=>$title,'post_password'=>'','post_content'=>'','menu_order'=>0);
}
$GLOBALS['last_saved']=null;
function nav_assert($value, $reason) { if (!$value) { fwrite(STDERR, "FAIL: $reason\n"); exit(1); } }
function add_action() {}
function add_filter() {}
function current_user_can($cap) { return $cap === 'manage_options'; }
function get_posts($args) { return $args['post_type']==='wp_navigation' ? array($GLOBALS['nav_post']) : array(); }
function parse_blocks($html) { return strpos($html, '<!-- wp:page-list') !== false ? array(array('blockName'=>'core/page-list','attrs'=>array(),'innerBlocks'=>array())) : json_decode($html,true); }
function get_pages($args) { return array_values($GLOBALS['pages']); }
function get_post($id) { return (int)$id===4 ? $GLOBALS['nav_post'] : ($GLOBALS['pages'][(int)$id] ?? null); }
function get_permalink($page) { return 'https://example.test/p/'.(int)$page->ID.'/'; }
function wp_json_encode($a) { return json_encode($a); }
function wp_slash($a) { return $a; }
function serialize_blocks($blocks) { return json_encode($blocks); }
function wp_update_post($data,$error=false) {
    if ((int)$data['ID']===4) { $GLOBALS['nav_post']->post_content=$data['post_content']; $GLOBALS['last_saved']=$data; }
    else { $GLOBALS['pages'][$data['ID']]->menu_order=$data['menu_order']; }
    return $data['ID'];
}
function is_wp_error($e) { return false; }
function check_admin_referer($a) { return true; }
function wp_unslash($a) { return $a; }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/','',strtolower($s)); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function __($s,$domain='') { return $s; }
function esc_html__($s,$domain='') { return esc_html($s); }
function esc_url($s) { return htmlspecialchars($s, ENT_QUOTES); }
function selected($a,$b,$echo=false) { return (string)$a===(string)$b ? ' selected="selected"' : ''; }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce">'; }
function admin_url($a) { return 'https://example.test/wp-admin/'.$a; }
function add_query_arg($a,$b) { return $b; }
function wp_safe_redirect($url) { $GLOBALS['redirect_url']=$url; }
function wp_die($message) { throw new Exception((string)$message); }
function checked($actual,$expected,$echo=false) { return $actual === $expected ? ' checked="checked"' : ''; }
function get_option($k,$default=false) { return $default; }
function plugin_dir_path($path) { return '/fake/'; }
function plugin_dir_url($path) { return 'https://example.test/plugins/'; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function get_nav_menu_locations() { return array(); }
class Dendrila_Simple_Edit_Plugin { public static function can_access() { return true; } }
class Dendrila_Simple_Edit_Modules { public static function allowed() { return true; } }
require_once __DIR__.'/../includes/class-dendrila-simple-edit-navigation.php';

$menu=Dendrila_Simple_Edit_Navigation::detect();
nav_assert($menu['type']==='page-list' && count($menu['items'])===3, 'page-list is detected as three links');
$reflection=new ReflectionMethod('Dendrila_Simple_Edit_Navigation','block_tree');
$reflection->setAccessible(true);
$order=array(33,11,22);
$desired=array(11=>array('parent'=>0,'mobile'=>'open'),22=>array('parent'=>11,'mobile'=>'closed'),33=>array('parent'=>0,'mobile'=>'closed'));
$tree=$reflection->invoke(null,$order,$desired,array());
nav_assert(count($tree)===2,'two top level groups');
nav_assert($tree[0]['attrs']['id']===33 && $tree[0]['attrs']['url']==='https://example.test/p/33/', 'page permalinks unchanged');
nav_assert($tree[1]['blockName']==='core/navigation-submenu','a grouped page becomes navigation-submenu');
nav_assert($tree[1]['attrs']['id']===11 && $tree[1]['attrs']['className']==='dendrila-simple-edit-mobile-open', 'mobile open state stored on parent');
nav_assert($tree[1]['innerBlocks'][0]['attrs']['id']===22 &&
    $tree[1]['innerBlocks'][0]['attrs']['url']==='https://example.test/p/22/', 'child retains its permalink');

// Editor renders independent parent and mobile selects and an explicit conversion warning.
ob_start();Dendrila_Simple_Edit_Navigation::render();$html=ob_get_clean();
nav_assert(substr_count($html,'name="dendrila_simple_edit_nav_ids[]"')===3,'all items have hidden IDs');
nav_assert(substr_count($html,'name="dendrila_simple_edit_nav_parents[]"')===3,'all items have parent selectors');
nav_assert(strpos($html,'dendrila_simple_edit_nav_convert_confirm')!==false,'page-list conversion requires confirmation');
nav_assert(substr_count($html,'data-dendrila-simple-edit-nav-move="up"')===3,'keyboard/touch fallback buttons');

// Check that tampered selections are rejected before touching content.
$base=array('dendrila_simple_edit_nav_version'=>Dendrila_Simple_Edit_Navigation::fingerprint($menu),
    'dendrila_simple_edit_nav_ids'=>array('11','22','33'),
    'dendrila_simple_edit_nav_parents'=>array('0','0','0'),
    'dendrila_simple_edit_nav_mobile'=>array('closed','closed','closed'));
$_POST=$base; $_POST['dendrila_simple_edit_nav_parents']=array('22','11','0');
try { Dendrila_Simple_Edit_Navigation::save(); nav_assert(false,'cycle must be rejected'); }
catch (Exception $e) { nav_assert(strpos($e->getMessage(),'sous-menus')!==false,'cycle reports invalid hierarchy'); }
nav_assert($GLOBALS['last_saved']===null,'invalid hierarchy cannot write navigation');

// Remaining cases invoke save() in a child PHP process; wp_safe_redirect exits by design.
if (!isset($argv[1])) { echo "OK: detected page-list, safe submenu blocks, mobile defaults and invalid cycles\n"; exit; }
if ($argv[1]==='missing-confirmation') {
    $_POST=$base;
    $_POST['dendrila_simple_edit_nav_parents']=array('0','11','0');
    try { Dendrila_Simple_Edit_Navigation::save(); nav_assert(false,'conversion needs confirmation'); }
    catch (Exception $e) { nav_assert(strpos($e->getMessage(),'Confirmez')!==false,'explicit confirmation message'); }
    echo "OK: missing consent rejected\n"; exit;
}
if ($argv[1]==='convert') {
    $_POST=$base;
    $_POST['dendrila_simple_edit_nav_parents']=array('0','11','0');
    $_POST['dendrila_simple_edit_nav_mobile']=array('open','closed','closed');
    $_POST['dendrila_simple_edit_nav_convert_confirm']='1';
    register_shutdown_function(function () {
        $saved=$GLOBALS['last_saved'];
        if (!$saved) { fwrite(STDERR,"FAIL: no navigation updated\n"); exit(1); }
        $blocks=json_decode($saved['post_content'],true);
        nav_assert($blocks[0]['blockName']==='core/navigation-submenu','converted nav has submenu');
        nav_assert($blocks[0]['innerBlocks'][0]['attrs']['url']==='https://example.test/p/22/','converted URL preserved');
        nav_assert($GLOBALS['pages'][11]->menu_order===0 && $GLOBALS['pages'][22]->menu_order===0,
            'conversion does not modify page menu_order');
        echo "OK: converted grouped menu, protected page metadata and URLs\n";
    });
    Dendrila_Simple_Edit_Navigation::save();
}
