<?php
/**
 * Plugin Name: Dendrila Simple Edit
 * Description: Édition guidée de contenus explicitement autorisés, sans accès à la mise en page.
 * Version: 0.0.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: dendrila-simple-edit
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DSE_VERSION', '0.0.1');
define('DSE_PLUGIN_FILE', __FILE__);

$dependencies = array(
    '/includes/class-dse-plugin.php',
    '/includes/class-dse-acf.php',
    '/includes/class-dse-builders.php',
    '/includes/class-dse-media.php',
    '/includes/class-dse-modules.php',
    '/includes/class-dse-block-editor.php',
    '/includes/class-dse-post-editor.php',
    '/includes/class-dse-event-editor.php',
    '/includes/class-dse-mailing.php',
    '/includes/class-dse-newsletters.php',
    '/includes/class-dse-woo.php',
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

register_activation_hook(__FILE__, array('DSE_Plugin', 'activate'));
register_activation_hook(__FILE__, array('DSE_Mailing', 'install'));
add_action('plugins_loaded', array('DSE_Plugin', 'boot'));
