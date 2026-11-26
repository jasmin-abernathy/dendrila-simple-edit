<?php
/** Gutenberg text-only editing regression, without WordPress bootstrap. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
function sanitize_text_field($s) { return preg_replace('/[\r\n\t]+/', ' ', strip_tags((string)$s)); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-gutenberg.php';
function verify($ok,$message){ if(!$ok){ fwrite(STDERR,"FAIL: ".$message."\n");exit(1); } }
$source = <<<'HTML'
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p>Bonjour tout le monde !</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Titre original</h2>
<!-- /wp:heading -->
<!-- wp:woocommerce/checkout -->
<div class="woocommerce-checkout-block"><p>Ne touchez jamais ce texte</p></div>
<!-- /wp:woocommerce/checkout -->
<!-- wp:paragraph -->
<p>Paragraphe <strong>formaté</strong> à préserver.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
HTML;
$blocks=Dendrila_Simple_Edit_Gutenberg::editable_blocks($source);
verify(count($blocks)===2,'Plain Gutenberg text eligible; formatted and Woo blocks protected');
verify(Dendrila_Simple_Edit_Gutenberg::supports($source),'Existing Gutenberg content recognized');
$updated=Dendrila_Simple_Edit_Gutenberg::apply_edits($source,array(
    'Bonjour & bienvenue','Un nouveau titre'));
verify(is_string($updated),'Gutenberg edit accepted');
verify(strpos($updated,'<p>Bonjour &amp; bienvenue</p>')!==false,'HTML escaped');
verify(strpos($updated,'<h2 class="wp-block-heading">Un nouveau titre</h2>')!==false,'Wrapper retained');
verify(strpos($updated,'<div class="woocommerce-checkout-block"><p>Ne touchez jamais ce texte</p></div>')!==false,'Woo markup preserved');
verify(Dendrila_Simple_Edit_Gutenberg::is_safe_transition($source,$updated),'Text-only proposal accepted');
verify(!Dendrila_Simple_Edit_Gutenberg::is_safe_transition($source,str_replace('woocommerce-checkout-block','tampered',$updated)),'Immutable third-party block rejected');
verify(!Dendrila_Simple_Edit_Gutenberg::is_safe_transition($source,str_replace('wp-block-group','broken',$updated)),'Layout change rejected');
verify(Dendrila_Simple_Edit_Gutenberg::apply_edits($source,array(0=>'missing another'))===false,'Missing value rejected');
verify(Dendrila_Simple_Edit_Gutenberg::apply_edits($source,array(0=>'a',1=>array('b')))===false,'Array injection rejected');
verify(Dendrila_Simple_Edit_Gutenberg::apply_edits($source,array(0=>'a',1=>'b',2=>'x'))===false,'Extra value rejected');
verify(Dendrila_Simple_Edit_Gutenberg::apply_edits($source,array('Bonjour tout le monde !','Titre original'))===$source,'Unchanged bytes retained');
verify(!Dendrila_Simple_Edit_Gutenberg::supports('<!-- wp:woocommerce/checkout --><div>Cart</div><!-- /wp:woocommerce/checkout -->'),'Layout-only page protected');
$listing='<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Premier point</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
verify(count(Dendrila_Simple_Edit_Gutenberg::editable_blocks($listing))===1,'List-item text supported');
echo "OK: Gutenberg simple text edits preserve surrounding blocks and reject manipulation\n";
