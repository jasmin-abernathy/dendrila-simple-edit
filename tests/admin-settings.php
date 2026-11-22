<?php
/** Non-browser rendering checks for the compact module/role administration. */
$_GET['section']='general';
ob_start(); require __DIR__.'/admin-visual-fixture.php'; $general=ob_get_clean();
function dse_settings_assert($ok,$reason){if(!$ok){fwrite(STDERR,"FAIL: $reason\n");exit(1);}}
dse_settings_assert(substr_count($general,'value="dendrila_simple_edit_module_toggle"')===3,'one independent toggle form per module');
dse_settings_assert(strpos($general,'name="dendrila_simple_edit_module_enabled"')!==false,'module enabled checkbox exists');
dse_settings_assert(strpos($general,'FluentSMTP est requis pour les newsletters')!==false,'newsletter dependency visible at point of choice');
dse_settings_assert(strpos($general,'Rôles et permissions')!==false,'compact roles tab visible');
$_GET['section']='roles';
ob_start(); Dendrila_Simple_Edit_Modules::render_admin(); $roles=ob_get_clean();
dse_settings_assert(substr_count($roles,'class="dendrila-simple-edit-card dendrila-simple-edit-module-permissions"')===3,'roles grouped by 3 closed-by-default module accordions');
dse_settings_assert(strpos($roles,'dendrila-simple-edit-role-permissions')!==false,'roles grouped into accordions');
dse_settings_assert(strpos($roles,'<summary>Administrator')===false,'administrator not asked for individual grants');
dse_settings_assert(strpos($roles,'name="dendrila_simple_edit_module_enabled"')===false,'roles cannot inadvertently change module activation');
echo "OK: module toggles and compact role accordions with independent activation\n";
