<?php
/**
 * Plugin Name: Dendrila Simple Edit
 * Description: Édition guidée de contenus explicitement autorisés, sans accès à la mise en page.
 * Version: 0.0.2
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

define('DENDRILA_SIMPLE_EDIT_VERSION', '0.0.2');
define('DENDRILA_SIMPLE_EDIT_PLUGIN_FILE', __FILE__);

$dendrila_simple_edit_dependencies = array(
    '/includes/class-dendrila-simple-edit-plugin.php',
    '/includes/class-dendrila-simple-edit-admin-access.php',
    '/includes/class-dendrila-simple-edit-plugin-access.php',
    '/includes/class-dendrila-simple-edit-setup.php',
    '/includes/class-dendrila-simple-edit-approvals.php',
    '/includes/class-dendrila-simple-edit-acf.php',
    '/includes/class-dendrila-simple-edit-builders.php',
    '/includes/class-dendrila-simple-edit-media.php',
    '/includes/class-dendrila-simple-edit-modules.php',
    '/includes/class-dendrila-simple-edit-block-editor.php',
    '/includes/class-dendrila-simple-edit-gutenberg.php',
    '/includes/class-dendrila-simple-edit-post-editor.php',
    '/includes/class-dendrila-simple-edit-navigation.php',
    '/includes/class-dendrila-simple-edit-event-editor.php',
    '/includes/class-dendrila-simple-edit-suppressions.php',
    '/includes/class-dendrila-simple-edit-mailing.php',
    '/includes/class-dendrila-simple-edit-newsletter-templates.php',
    '/includes/class-dendrila-simple-edit-newsletters.php',
    '/includes/class-dendrila-simple-edit-woo-mailing.php',
    '/includes/class-dendrila-simple-edit-woo.php',
);
foreach ($dendrila_simple_edit_dependencies as $dendrila_simple_edit_dependency) {
    if (!is_readable(__DIR__ . $dendrila_simple_edit_dependency)) {
        add_action('admin_notices', static function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Dendrila Simple Edit est incomplet. Réinstallez le ZIP complet du plugin.', 'dendrila-simple-edit') . '</p></div>';
            }
        });
        return;
    }
}
foreach ($dendrila_simple_edit_dependencies as $dendrila_simple_edit_dependency) {
    require_once __DIR__ . $dendrila_simple_edit_dependency;
}

register_activation_hook(__FILE__, array('Dendrila_Simple_Edit_Plugin', 'activate'));
register_activation_hook(__FILE__, array('Dendrila_Simple_Edit_Mailing', 'install'));
add_action('plugins_loaded', array('Dendrila_Simple_Edit_Plugin', 'boot'));
// WordPress 6.8+ registers the Domain Path header during plugin bootstrap.
if (version_compare($GLOBALS['wp_version'], '6.8', '<')) {
    add_action('init', static function () {
        $GLOBALS['wp_textdomain_registry']->set_custom_path('dendrila-simple-edit', __DIR__ . '/languages');
    }, 0);
}
