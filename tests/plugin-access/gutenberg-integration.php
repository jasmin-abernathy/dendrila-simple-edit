<?php
/** Gutenberg and navigation regression in disposable WordPress MariaDB fixture. */
$root = getenv('DSE_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { exit(2); }
define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8098';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
require $root . '/wp-load.php';
if (get_option('dse_plugin_access_test_fixture') !== '1') { exit(2); }
function gt_assert($ok,$message) { if (!$ok) { throw new RuntimeException('FAIL Gutenberg: ' . $message); } }
$admin = get_user_by('login', 'menu_admin');
$editor = get_user_by('login', 'menu_simple');
gt_assert($admin && $editor, 'Fixture users');
wp_set_current_user($admin->ID);
$source = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n" .
    '<div class="wp-block-group">' . "\n" .
    '<!-- wp:paragraph --><p>Bienvenue sur le site</p><!-- /wp:paragraph -->' . "\n" .
    '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading"><strong>Nos services</strong></h2><!-- /wp:heading -->' . "\n" .
    '<!-- wp:woocommerce/checkout --><div class="checkout-protected">Contenu Woo préservé</div><!-- /wp:woocommerce/checkout -->' . "\n" .
    '</div><!-- /wp:group -->';
$id = wp_insert_post(array('post_type'=>'page','post_title'=>'Gutenberg test',
    'post_status'=>'publish','post_content'=>wp_slash($source)),true);
gt_assert(!is_wp_error($id) && (int)$id>0,'Gutenberg post created');
$post = get_post($id);
gt_assert($post->post_content === $source,'Initial Gutenberg content stored intact');
gt_assert(Dendrila_Simple_Edit_Post_Editor::is_layout_safe($post),'Mixed Gutenberg/Woo page is now editable');
ob_start();
Dendrila_Simple_Edit_Post_Editor::render('page');
$editor_html = ob_get_clean();
gt_assert(strpos($editor_html, 'id="dendrila-simple-edit-item-' . (int) $id . '"') !== false,
    'Gutenberg page shown as editable, not restricted');
gt_assert(strpos($editor_html, 'name="dendrila_simple_edit_gutenberg_text[0]"') !== false,
    'Gutenberg text fields are visible and submitted by the page editor');
$updated = Dendrila_Simple_Edit_Gutenberg::apply_edits($source,
    array('Bonjour & bienvenue','Nos nouveaux services'));
gt_assert($updated!==false && strpos($updated,'<div class="checkout-protected">')!==false,
    'Surgical text change');
wp_set_current_user($editor->ID);
$update = array('ID'=>$id,'post_content'=>wp_slash($updated));
$done = Dendrila_Simple_Edit_Gutenberg::update_post($update);
gt_assert(!is_wp_error($done) && $done,'Native write as delegated account');
$post = get_post($id);
gt_assert($post->post_content === $updated,'WordPress persists the exact Gutenberg markup');
gt_assert(in_array($source, wp_list_pluck(wp_get_post_revisions($id),'post_content'), true), 'Original revision available');
gt_assert(serialize_blocks(parse_blocks($updated)) === serialize_blocks(parse_blocks($post->post_content)), 'Native block structure persisted');
$rewrite = static function ($data) { $data['post_content'] .= ' changed'; return $data; };
add_filter('wp_insert_post_data', $rewrite);
$refused = Dendrila_Simple_Edit_Gutenberg::update_post(array('ID'=>$id,'post_content'=>wp_slash($source)));
remove_filter('wp_insert_post_data', $rewrite);
gt_assert(is_wp_error($refused) && get_post($id)->post_content === $updated, 'Filter rewrite aborted before storage');
wp_set_current_user($admin->ID);
update_option(Dendrila_Simple_Edit_Approvals::OPTION,array('require_page'=>true),false);
$proposal = Dendrila_Simple_Edit_Gutenberg::apply_edits($updated,
    array('Bienvenue avec édition','Nos services 2027'));
gt_assert($proposal!==false,'Review proposal');
gt_assert(Dendrila_Simple_Edit_Approvals::submit($post,$post->post_title,$proposal),
    'Review proposal saved');
$pending = get_post_meta($id,Dendrila_Simple_Edit_Approvals::PENDING,true);
gt_assert(isset($pending['body']) && $pending['body']===$proposal,
    'Pending review retains Gutenberg JSON verbatim');
gt_assert(Dendrila_Simple_Edit_Gutenberg::is_safe_transition($post->post_content,$pending['body']),
    'Reviewer checks only changed text');
delete_post_meta($id,Dendrila_Simple_Edit_Approvals::PENDING);
update_option(Dendrila_Simple_Edit_Approvals::OPTION,array(),false);
wp_delete_post($id,true);
// Render a disposable block menu and verify its per-card controls.
foreach (get_posts(array('post_type'=>'wp_navigation','post_status'=>'any','posts_per_page'=>-1)) as $nav) {
    wp_delete_post($nav->ID,true);
}
$nav_id=wp_insert_post(array('post_type'=>'wp_navigation','post_title'=>'Main navigation fixture',
    'post_status'=>'publish','post_content'=>wp_slash(
        '<!-- wp:navigation-link {"label":"Accueil","type":"custom","url":"https://example.test/"} /-->' . "\n" .
        '<!-- wp:navigation-link {"label":"Contact","type":"custom","url":"https://example.test/contact"} /-->'
    )),true);
gt_assert(!is_wp_error($nav_id) && $nav_id,'Navigation created');
ob_start();
Dendrila_Simple_Edit_Navigation::render();
$html=ob_get_clean();
gt_assert(strpos($html,'data-dendrila-simple-edit-nav-remove')!==false,
    'Card has an immediate remove action');
gt_assert(strpos($html,'form="dendrila-simple-edit-nav-remove-1"')!==false &&
    strpos($html,'id="dendrila-simple-edit-nav-remove-1"')!==false,
    'Per-card control targets a separate nonce-protected form');
gt_assert(strpos($html,'<details class="dendrila-simple-edit-nav-customization">')!==false,
    'Customization accordion initially closed');
gt_assert(strpos($html,'name="dendrila_simple_edit_nav_operation" value="remove"')!==false,
    'Removal uses existing safe menu-link operation');
wp_delete_post($nav_id,true);
echo "PASS Gutenberg: WordPress persistence, review-safe text, and nav card controls\n";
