<?php
/**
 * Plugin Name: Dendrila Simple Edit
 * Description: Édition guidée de contenus explicitement autorisés, sans accès à la mise en page.
 * Version: 0.0.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: dendrila-simple-edit
 * Domain Path: /languages
 * Author: Le Potager du Web
 * Author URI: https://lepotager.org
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DENDRILA_SIMPLE_EDIT_VERSION', '0.0.1');
define('DENDRILA_SIMPLE_EDIT_PLUGIN_FILE', __FILE__);

$dependencies = array(
    '/includes/class-dendrila-simple-edit-plugin.php',
    '/includes/class-dendrila-simple-edit-approvals.php',
    '/includes/class-dendrila-simple-edit-acf.php',
    '/includes/class-dendrila-simple-edit-builders.php',
    '/includes/class-dendrila-simple-edit-media.php',
    '/includes/class-dendrila-simple-edit-modules.php',
    '/includes/class-dendrila-simple-edit-block-editor.php',
    '/includes/class-dendrila-simple-edit-post-editor.php',
    '/includes/class-dendrila-simple-edit-navigation.php',
    '/includes/class-dendrila-simple-edit-event-editor.php',
    '/includes/class-dendrila-simple-edit-mailing.php',
    '/includes/class-dendrila-simple-edit-newsletters.php',
    '/includes/class-dendrila-simple-edit-woo.php',
);
foreach ($dependencies as $dependency) {
    if (!is_readable(__DIR__ . $dependency)) {
        add_action('admin_notices', static function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>Dendrila Simple Edit est incomplet. Réinstallez le ZIP complet du plugin.</p></div>';
            }
        });
        return;
    }
}
foreach ($dependencies as $dependency) {
    require_once __DIR__ . $dependency;
}

register_activation_hook(__FILE__, array('Dendrila_Simple_Edit_Plugin', 'activate'));
register_activation_hook(__FILE__, array('Dendrila_Simple_Edit_Mailing', 'install'));
add_action('plugins_loaded', array('Dendrila_Simple_Edit_Plugin', 'boot'));
add_action('init', static function () {
    load_plugin_textdomain('dendrila-simple-edit', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 0);
