<?php
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
function __($text,$domain=''){return $text;}
function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function esc_html__($text,$domain=''){return esc_html($text);}
function esc_attr($text){return esc_html($text);}
function esc_url($text){return esc_html($text);}
function checked($value,$expected,$echo=false){return $value==$expected?' checked':'';}
function selected($value,$expected,$echo=false){return $value==$expected?' selected':'';}
function translate_user_role($text){return $text;}
function wp_nonce_field($name){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
function admin_url($path=''){return '/wp-admin/'.$path;}
function current_user_can($cap){return $cap==='manage_options';}
function get_option($key,$default=false){if($key==='dendrila_simple_edit_modules')return [
'editorial'=>['enabled'=>true,'roles'=>['editor'=>['edit_pages','edit_articles'],'author'=>['create_articles']]],
'newsletters'=>['enabled'=>true,'roles'=>['editor'=>['create','edit'],'author'=>['create']]],
'products'=>['enabled'=>false,'roles'=>[]]];return $default;}
class FakeRole {public $roles=['administrator'=>['name'=>'Administrator'],'editor'=>['name'=>'Éditeur'],'author'=>['name'=>'Auteur'],'subscriber'=>['name'=>'Abonné'],'contributor'=>['name'=>'Contributeur'],'dendrila_simple_editor'=>['name'=>'Édition simple']];}
function wp_roles(){return new FakeRole();}
class Dendrila_Simple_Edit_Mailing{public static function transport_ready(){return true;}}
require_once dirname(__DIR__).'/includes/class-dendrila-simple-edit-modules.php';
$section=$_GET['section']??'general';$css=file_get_contents(dirname(__DIR__).'/assets/admin.css');
echo '<!DOCTYPE html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px system-ui;background:#f0f0f1;padding:18px;margin:0;box-sizing:border-box}*{box-sizing:border-box}</style><style>'.$css.'</style>';
echo '<div class="wrap dendrila-simple-edit-wrap"><div class="dendrila-simple-edit-intro"><h1>Modifier mon site</h1></div><div class="dendrila-simple-edit-tab-content"><header class="dendrila-simple-edit-tab-heading"><h2>Réglages</h2><p>Activez les outils et attribuez les permissions par rôle.</p></header><div class="dendrila-simple-edit-panel-grid dendrila-simple-edit-panel-reglages">';
echo '<nav class="dendrila-simple-edit-newsletter-subnav dendrila-simple-edit-settings-subnav"><a class="dendrila-simple-edit-newsletter-subtab" href="?section=general">Modules</a><a class="dendrila-simple-edit-newsletter-subtab" href="?section=contenu">Contenu</a><a class="dendrila-simple-edit-newsletter-subtab" href="?section=roles">Rôles et permissions</a><a class="dendrila-simple-edit-newsletter-subtab">Validation</a></nav>';
if($section==='roles'){echo '<section class="dendrila-simple-edit-card"><h2>Permissions par rôle</h2><p>Ouvrez uniquement les modules à configurer.</p></section>';Dendrila_Simple_Edit_Modules::render_admin();}
elseif($section==='contenu'){echo '<nav class="dendrila-simple-edit-newsletter-subnav dendrila-simple-edit-content-subnav"><a class="dendrila-simple-edit-newsletter-subtab">Pages, articles et événements</a><a class="dendrila-simple-edit-newsletter-subtab">Images</a><a class="dendrila-simple-edit-newsletter-subtab">Champs complémentaires</a><a class="dendrila-simple-edit-newsletter-subtab">Textes et coordonnées</a></nav><section class="dendrila-simple-edit-card"><h2>Choisir les contenus modifiables</h2></section>';}
else{echo '<section class="dendrila-simple-edit-card dendrila-simple-edit-setup-relaunch"><h2>Assistant de configuration</h2><p>Reprendre manuellement le parcours, sans perdre les contacts ni la configuration.</p><button class="button button-secondary">Relancer l’assistant de configuration</button></section>';Dendrila_Simple_Edit_Modules::render_modules();}
echo '</div></div></div>';
