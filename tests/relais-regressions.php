<?php
/** Standalone regression for delegated content discovery, campaign isolation, and opt-in nonce. */
error_reporting(E_ALL);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$GLOBALS['dse_admin'] = false;
$GLOBALS['dse_id'] = 7;
$GLOBALS['dse_types'] = array('post');
$GLOBALS['dse_posts'] = array();
$GLOBALS['dse_newsletters'] = array();
$GLOBALS['dse_mails'] = array();
$GLOBALS['dse_queries'] = array();
function dse_assert($ok, $why) { if (!$ok) { fwrite(STDERR,"FAIL: $why\n"); exit(1); } }
function current_user_can($cap) { return $cap === 'manage_options' ? $GLOBALS['dse_admin'] : true; }
function get_current_user_id() { return $GLOBALS['dse_id']; }
function get_option($key, $default = false) {
    if ($key === 'dendrila_simple_edit_editable_types') return $GLOBALS['dse_types'];
    if ($key === 'dendrila_simple_edit_mailing_schema_version') return '5';
    if ($key === 'dendrila_simple_edit_wc_mailing') return array('enabled'=>true,'list_id'=>2);
    if ($key === 'fluentmail-settings') return array('connections'=>array(array('name'=>'lab')));
    return $default;
}
function get_post($id) { return $GLOBALS['dse_posts'][(int)$id] ?? $GLOBALS['dse_newsletters'][(int)$id] ?? null; }
function get_posts($args) {
    $GLOBALS['dse_queries'][] = $args;
    $items = array_values(array_filter($GLOBALS['dse_posts'], static function ($p) use ($args) {
        if ($p->post_type !== ($args['post_type'] ?? 'post')) return false;
        if (isset($args['author']) && (int)$args['author'] !== (int)$p->post_author) return false;
        if (isset($args['has_password']) && $args['has_password'] === false && $p->post_password !== '') return false;
        if (isset($args['post_status']) && !in_array($p->post_status, (array)$args['post_status'], true)) return false;
        return true;
    }));
    $limit = (int)($args['posts_per_page'] ?? 5);
    return $limit === -1 ? $items : array_slice($items, 0, $limit);
}
function get_post_meta($id, $key, $single = true) {
    if ($key === '_dendrila_simple_edit_created_by_simple_edit') return $id <= 125 ? '1' : '';
    return '';
}
function get_edit_post_link($id, $context = '') { return '/wp-admin/post.php?post=' . (int)$id; }
function esc_html($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function esc_attr($x) { return esc_html($x); }
function esc_url($x) { return esc_html($x); }
function esc_textarea($x) { return esc_html($x); }
function esc_html__($x, $domain = null) { return esc_html($x); }
function __($x, $domain = null) { return $x; }
function wp_nonce_field($action, $name = '_wpnonce', $referer = true, $display = true) {
    $s='<input type="hidden" name="'.esc_attr($name).'" value="good">';
    if ($display) echo $s;
    return $s;
}
function wp_verify_nonce($nonce, $action) { return $nonce === 'good' ? 1 : false; }
function wp_kses($value, $tags) { return $value; }
function wp_unslash($value) { return $value; }
function absint($value) { return abs((int)$value); }
function sanitize_text_field($value) { return is_string($value) ? trim(strip_tags($value)) : $value; }
function get_permalink($p) { return '/?p=' . (int)$p->ID; }
function selected($a, $b, $echo=false) { return $a == $b ? ' selected' : ''; }
function wp_get_post_revisions($id, $args = array()) { return array(); }
function admin_url($p='') { return '/wp-admin/'.$p; }
function get_the_date($format, $post=null) { return 'today'; }
function get_the_author_meta($field, $id) { return ''; }
function is_plugin_active($slug) { return $slug === 'fluent-smtp/fluent-smtp.php'; }
function wp_mail($to,$subject,$body) { $GLOBALS['dse_mails'][] = array($to,$subject,$body); return true; }
class Dendrila_Simple_Edit_Modules {
    public static function enabled($module) { return true; }
    public static function allowed($module,$action) {
        if ($module === 'editorial') return in_array($action, array('create_pages','create_articles'), true);
        if ($module === 'newsletters') return $action === 'send';
        return false;
    }
}
class Dendrila_Simple_Edit_Approvals { public static function required($type) { return false; } }
class Dendrila_Simple_Edit_Block_Editor {
    public static function is_safe_for_editor($body) { return strpos($body, '<!-- wp:') === false; }
}
class Dendrila_Simple_Edit_Newsletters {
    const TYPE='dendrila_simple_edit';
    public static function can_send($post=null) {
        return $post === null ? true :
            $post->post_type === self::TYPE && (int)$post->post_author === get_current_user_id();
    }
}
class Dendrila_Simple_Edit_Suppressions { public static function ready() { return true; } }
class DSE_Test_DB {
    public $prefix='wp_';
    public $posts='wp_posts';
    public function prepare($sql,...$args) {
        $i=0;
        $sql=preg_replace_callback('/%[dis]/', static function($m) use (&$i,$args) {
            $v=$args[$i++];
            return $m[0] === '%d' ? (string)(int)$v : (string)$v;
        },$sql);
        $GLOBALS['dse_queries'][] = $sql;
        return $sql;
    }
    public function get_results($query) {
        if (strpos($query,'dendrila_simple_edit_ml_lists') !== false && strpos($query,'campaigns') === false) {
            return array((object)array('id'=>2,'name'=>'Test','owner_user_id'=>7));
        }
        if (strpos($query,'dendrila_simple_edit_ml_campaigns') !== false) {
            // 150 newer campaigns by a different author; old LIMIT 100 filtered after SQL would return none.
            $campaigns=[];
            for($id=300;$id>=151;$id--) $campaigns[]=(object)array('id'=>$id,'newsletter_id'=>$id+1000,'subject'=>'Other author','status'=>'completed','list_name'=>'Test');
            $campaigns[]=(object)array('id'=>4,'newsletter_id'=>2000,'subject'=>'My older campaign','status'=>'completed','list_name'=>'Test');
            if (strpos($query,'post_author=7')!==false && strpos($query,'INNER JOIN')!==false) {
                $campaigns=array_values(array_filter($campaigns,static function($c){
                    return isset($GLOBALS['dse_newsletters'][$c->newsletter_id]) &&
                        (int)$GLOBALS['dse_newsletters'][$c->newsletter_id]->post_author===7;
                }));
            }
            return array_slice($campaigns,0,strpos($query,'LIMIT 15')!==false ? 15 : 100);
        }
        return array();
    }
}
$GLOBALS['wpdb'] = new DSE_Test_DB();
$repo_root = dirname(__DIR__);
$root = is_dir($repo_root . '/updated/dendrila-simple-edit/includes') ?
    $repo_root . '/updated/dendrila-simple-edit/includes/' : $repo_root . '/includes/';
require_once $root.'class-dendrila-simple-edit-post-editor.php';
require_once $root.'class-dendrila-simple-edit-mailing.php';
require_once $root.'class-dendrila-simple-edit-woo-mailing.php';
// Global type selection must constrain creation by delegated user, but never administrator.
dse_assert(!Dendrila_Simple_Edit_Post_Editor::can_create_type('page'), 'delegated page creation bypasses global type choice');
dse_assert(Dendrila_Simple_Edit_Post_Editor::can_create_type('post'), 'delegated post creation unexpectedly denied');
$GLOBALS['dse_admin'] = true;
dse_assert(Dendrila_Simple_Edit_Post_Editor::can_create_type('page'), 'administrator is incorrectly restricted');
$GLOBALS['dse_admin'] = false;
$GLOBALS['dse_types'] = array('page');
for($i=1;$i<=125;$i++) $GLOBALS['dse_posts'][$i] = (object)array('ID'=>$i,'post_type'=>'page','post_author'=>7,'post_status'=>'draft','post_password'=>'','post_title'=>'Draft '.$i,'post_content'=>'<p>Safe</p>','post_modified_gmt'=>'2026-11-22 00:00:00');
$GLOBALS['dse_posts'][999]=(object)array('ID'=>999,'post_type'=>'page','post_author'=>9,'post_status'=>'publish','post_password'=>'','post_title'=>'Protected Gutenberg','post_content'=>'<!-- wp:columns --><div></div><!-- /wp:columns -->','post_modified_gmt'=>'2026-11-22 00:00:00');
ob_start();Dendrila_Simple_Edit_Post_Editor::render('page');$html=ob_get_clean();
dse_assert(substr_count($html,'class="dendrila-simple-edit-card dendrila-simple-edit-post-item"')===125,'125 delegated drafts not displayed');
dse_assert(strpos($html,'Protected Gutenberg')===false,'delegated user sees protected foreign page');
dse_assert((bool)array_filter($GLOBALS['dse_queries'],static function($q){return is_array($q)&&($q['posts_per_page']??0)===-1&&($q['author']??0)===7;}),'delegated draft discovery retains a hard limit');
$GLOBALS['dse_admin']=true;
$GLOBALS['dse_types']=array();
ob_start();Dendrila_Simple_Edit_Post_Editor::render('page');$admin_html=ob_get_clean();
dse_assert(strpos($admin_html,'Protected Gutenberg')!==false && strpos($admin_html,'Modifier dans WordPress')!==false,'administrator cannot see protected content');
dse_assert(substr_count($admin_html,'class="dendrila-simple-edit-card dendrila-simple-edit-post-item"')===125,'administrator cannot access all compatible drafts');
$GLOBALS['dse_admin']=false;
$GLOBALS['dse_newsletters'][2000]=(object)array('ID'=>2000,'post_type'=>'dendrila_simple_edit','post_author'=>7);
for($i=451;$i<=600;$i++) $GLOBALS['dse_newsletters'][$i+1000]=(object)array('ID'=>$i+1000,'post_type'=>'dendrila_simple_edit','post_author'=>9);
ob_start();Dendrila_Simple_Edit_Mailing::render_admin('suivi');$campaign_html=ob_get_clean();
dse_assert(strpos($campaign_html,'My older campaign')!==false,'own campaign hidden by more recent campaigns from others');
dse_assert(strpos($campaign_html,'Other author')===false,'foreign campaigns visible to delegated user');
$reflection=new ReflectionMethod('Dendrila_Simple_Edit_Mailing','can_manage_campaign');
$reflection->setAccessible(true);
dse_assert(!$reflection->invoke(null,(object)array('newsletter_id'=>99999)),'deleted newsletter does not deny delegated campaign access');
dse_assert($reflection->invoke(null,(object)array('newsletter_id'=>2000)),'own campaign incorrectly denied');
// The opt-in is optional and unchecked; a checkout nonce is present in the classic form.
ob_start(); Dendrila_Simple_Edit_Woo_Mailing::classic_checkbox(); $checkout_html = ob_get_clean();
dse_assert(strpos($checkout_html, 'name="dendrila_simple_edit_optin_nonce"') !== false, 'classic checkout missing opt-in nonce');
dse_assert(!preg_match('/name="dendrila_simple_edit_wc_optin"[^>]*checked/', $checkout_html), 'classic checkout opt-in must be unchecked by default');
// Classic WooCommerce opt-in must only write order metadata after verified nonce.
class DSE_Fake_Order {public $meta=[];public function update_meta_data($key,$value){$this->meta[$key]=$value;}}
$_POST=array('dendrila_simple_edit_wc_optin'=>'1');
$order=new DSE_Fake_Order(); Dendrila_Simple_Edit_Woo_Mailing::classic_save($order,array());
dse_assert(!$order->meta,'checkout opt-in accepted without nonce');
$_POST['dendrila_simple_edit_optin_nonce']='good';
Dendrila_Simple_Edit_Woo_Mailing::classic_save($order,array());
dse_assert(($order->meta['_dendrila_simple_edit_wc_optin']??'')==='1','checkout opt-in refused with valid nonce');
dse_assert(!$GLOBALS['dse_mails'],'test unexpectedly sent real or mocked emails');
echo "OK: per-type creation, 125 drafts, admin protected content, author-scoped campaigns, deleted newsletter, Woo nonce, no email sent\n";
