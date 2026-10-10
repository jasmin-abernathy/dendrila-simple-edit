<?php
/**
 * Plugin Name: Dendrila Simple Edit
 * Description: Édition guidée de contenus explicitement autorisés, sans accès à la mise en page.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: dendrila-simple-edit
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DSE_VERSION', '0.1.0');
define('DSE_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/includes/class-dse-plugin.php';
require_once __DIR__ . '/includes/class-dse-acf.php';
require_once __DIR__ . '/includes/class-dse-builders.php';

register_activation_hook(__FILE__, array('DSE_Plugin', 'activate'));
add_action('plugins_loaded', array('DSE_Plugin', 'boot'));
