<?php
/** Explicit access to installed plugin administrations for the restricted editor. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Admin_Access
{
    const OPTION = 'dendrila_simple_edit_extra_admin_menus';
    const CATALOG_OPTION = 'dendrila_simple_edit_admin_menu_catalog';
    private static $routes = array();

    public static function boot()
    {
        Dendrila_Simple_Edit_Plugin_Access::boot();
        add_action('admin_menu', array(__CLASS__, 'capture_menu'), PHP_INT_MAX - 2);
        add_action('admin_menu', array(__CLASS__, 'maybe_save'), PHP_INT_MAX - 1);
    }

    /** Never use a filtered capability to decide whether restrictions apply. */
    public static function is_restricted_user($user = null)
    {
        if (!$user) { $user = wp_get_current_user(); }
        return $user instanceof WP_User && $user->ID &&
            in_array(Dendrila_Simple_Edit_Plugin::ROLE, (array) $user->roles, true) &&
            empty($user->allcaps['manage_options']) &&
            !(is_multisite() && is_super_admin($user->ID));
    }

    private static function active_plugins()
    {
        $active = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $result = array();
        foreach ($active as $plugin) {
            if (!is_string($plugin) || validate_file($plugin) !== 0 ||
                substr($plugin, -4) !== '.php' || !is_file(WP_PLUGIN_DIR . '/' . $plugin)) { continue; }
            if (realpath(WP_PLUGIN_DIR . '/' . $plugin) === realpath(DENDRILA_SIMPLE_EDIT_PLUGIN_FILE)) { continue; }
            $result[$plugin] = true;
        }
        return $result;
    }

    public static function selected_plugins()
    {
        $saved = get_option(self::OPTION, array());
        if (!is_array($saved)) { return array(); }
        $active = self::active_plugins();
        return array_values(array_unique(array_filter($saved, static function ($plugin) use ($active) {
            return is_string($plugin) && isset($active[$plugin]);
        })));
    }

    public static function is_selected($plugin)
    {
        return is_string($plugin) && $plugin !== '' && in_array($plugin, self::selected_plugins(), true);
    }

    public static function plugin_capability($plugin)
    {
        return 'dendrila_simple_edit_plugin_' . substr(hash('sha256', $plugin), 0, 24);
    }

    /** Resolve real code ownership, including plugins installed through symlinks. */
    public static function plugin_for_file($file)
    {
        if (!is_string($file) || $file === '') { return ''; }
        $real = realpath($file);
        if (!$real) { return ''; }
        $owner = ''; $length = 0;
        foreach (self::active_plugins() as $plugin => $unused) {
            $main = realpath(WP_PLUGIN_DIR . '/' . $plugin);
            if ($real === $main) { return $plugin; }
            if (dirname($plugin) === '.') { continue; }
            $dir = dirname($main) . DIRECTORY_SEPARATOR;
            if (strpos($real, $dir) === 0 && strlen($dir) > $length) {
                $owner = $plugin; $length = strlen($dir);
            }
        }
        return $owner;
    }

    public static function plugin_permissions($plugin)
    {
        $catalog = get_option(self::CATALOG_OPTION, array());
        if (!is_array($catalog) || !isset($catalog[$plugin]['capabilities']) ||
            !is_array($catalog[$plugin]['capabilities'])) { return array(); }
        return array_values(array_filter($catalog[$plugin]['capabilities'], 'is_string'));
    }

    public static function callback_owner($callback)
    {
        try {
            if (is_array($callback) && count($callback) === 2) {
                $reflection = new ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                $reflection = new ReflectionMethod($callback);
            } elseif (is_object($callback) && !$callback instanceof Closure) {
                $reflection = new ReflectionMethod($callback, '__invoke');
            } else { $reflection = new ReflectionFunction($callback); }
            return self::plugin_for_file($reflection->getFileName());
        } catch (ReflectionException $e) { return ''; }
    }

    private static function route_owner($slug, $parent)
    {
        global $wp_filter;
        $query = array();
        if (strpos($slug, '?') !== false) { parse_str(substr($slug, strpos($slug, '?') + 1), $query); }
        $types = Dendrila_Simple_Edit_Plugin_Access::owned_post_types();
        $taxonomies = Dendrila_Simple_Edit_Plugin_Access::owned_taxonomies();
        if (isset($query['taxonomy']) && is_string($query['taxonomy']) && isset($taxonomies[$query['taxonomy']])) {
            return $taxonomies[$query['taxonomy']];
        }
        if (isset($query['post_type']) && is_string($query['post_type']) && isset($types[$query['post_type']]) &&
            empty($query['page'])) { return $types[$query['post_type']]; }
        $page = isset($query['page']) && is_string($query['page']) ? $query['page'] : $slug;
        $hook = get_plugin_page_hookname($page, $parent);
        $owners = array();
        if (isset($wp_filter[$hook]) && $wp_filter[$hook] instanceof WP_Hook) {
            foreach ($wp_filter[$hook]->callbacks as $callbacks) {
                foreach ($callbacks as $item) {
                    $owner = self::callback_owner($item['function']);
                    if ($owner !== '') { $owners[$owner] = true; }
                }
            }
        }
        if (count($owners) === 1) { return (string) key($owners); }
        if ($owners) { return ''; } // A shared screen cannot be attributed safely.
        if (validate_file($page) === 0 && is_file(WP_PLUGIN_DIR . '/' . $page)) {
            return self::plugin_for_file(WP_PLUGIN_DIR . '/' . $page);
        }
        if ($slug === $parent && isset(self::$routes[''][$slug])) { return self::$routes[''][$slug]['plugin']; }
        return '';
    }

    private static function record_route($item, $parent)
    {
        if (!isset($item[0], $item[1], $item[2]) || !is_string($item[2]) ||
            !is_string($item[1]) || $item[2] === 'dendrila-simple-edit') { return; }
        $owner = self::route_owner($item[2], $parent);
        if ($owner === '') { return; }
        self::$routes[$parent][$item[2]] = array('plugin' => $owner, 'slug' => $item[2],
            'parent' => $parent, 'capability' => $item[1], 'label' => trim(wp_strip_all_tags($item[0])));
    }

    public static function capture_menu()
    {
        global $menu, $submenu;
        self::$routes = array();
        foreach ((array) $menu as $item) { self::record_route($item, ''); }
        foreach ((array) $submenu as $parent => $items) {
            foreach ((array) $items as $item) { self::record_route($item, $parent); }
        }
        // Only an actual administrator may refresh the trusted capability inventory.
        if (self::is_restricted_user() || !current_user_can('manage_options')) { return; }
        $catalog = array();
        foreach (self::$routes as $routes) {
            foreach ($routes as $route) {
                $plugin = $route['plugin'];
                if (!isset($catalog[$plugin])) { $catalog[$plugin] = array('menus' => array(), 'capabilities' => array()); }
                $catalog[$plugin]['menus'][$route['slug']] = $route['label'];
                if (!in_array($route['capability'], $catalog[$plugin]['capabilities'], true)) {
                    $catalog[$plugin]['capabilities'][] = $route['capability'];
                }
            }
        }
        update_option(self::CATALOG_OPTION, $catalog, false);
    }

    public static function limit_admin_menu()
    {
        if (!self::is_restricted_user()) { return; }
        global $menu, $submenu;
        foreach ((array) $submenu as $parent => $items) {
            if ($parent === 'dendrila-simple-edit') { continue; }
            foreach ($items as $index => $item) {
                $route = isset($item[2], self::$routes[$parent][$item[2]]) ? self::$routes[$parent][$item[2]] : null;
                if (!$route || !self::is_selected($route['plugin'])) { unset($submenu[$parent][$index]); continue; }
                $submenu[$parent][$index][1] = self::plugin_capability($route['plugin']);
            }
            if (empty($submenu[$parent])) { unset($submenu[$parent]); }
            else { $submenu[$parent] = array_values($submenu[$parent]); }
        }
        foreach ((array) $menu as $index => $item) {
            if (isset($item[2]) && $item[2] === 'dendrila-simple-edit') { continue; }
            $route = isset($item[2], self::$routes[''][$item[2]]) ? self::$routes[''][$item[2]] : null;
            if ($route && self::is_selected($route['plugin'])) {
                $menu[$index][1] = self::plugin_capability($route['plugin']);
            } elseif (isset($item[2]) && !empty($submenu[$item[2]])) {
                // WordPress links a parent to its first accessible child.
                $menu[$index][1] = $submenu[$item[2]][0][1];
            } else { unset($menu[$index]); }
        }
    }

    private static function request_string($data, $key, $default = '')
    {
        return isset($data[$key]) && is_string($data[$key]) ? wp_unslash($data[$key]) : $default;
    }

    private static function owns_type($type)
    {
        $types = Dendrila_Simple_Edit_Plugin_Access::owned_post_types();
        return is_string($type) && isset($types[$type]) && self::is_selected($types[$type]);
    }

    public static function allows_current_page()
    {
        global $pagenow;
        foreach (array('page', 'post', 'post_type', 'taxonomy', 'revision', 'tag_ID') as $key) {
            if (isset($_GET[$key]) && !is_string($_GET[$key])) { return false; }
        }
        if (isset($_POST['post_ID']) && !is_string($_POST['post_ID'])) { return false; }
        if ($pagenow === 'options.php') { return Dendrila_Simple_Edit_Plugin_Access::allows_settings_request(); }
        $page = self::request_string($_GET, 'page');
        if ($page !== '') {
            foreach (self::$routes as $routes) {
                foreach ($routes as $route) {
                    if (!self::is_selected($route['plugin'])) { continue; }
                    $slug = $route['slug']; $query = array();
                    if (strpos($slug, '?') !== false) { parse_str(substr($slug, strpos($slug, '?') + 1), $query); }
                    $target = isset($query['page']) && is_string($query['page']) ? $query['page'] : $slug;
                    $parent_file = strtok($route['parent'], '?');
                    $slug_file = isset($query['page']) ? strtok($slug, '?') : '';
                    if ($target === $page && ($pagenow === 'admin.php' ||
                        ($parent_file && $pagenow === $parent_file) || ($slug_file && $pagenow === $slug_file))) { return true; }
                }
            }
            return false;
        }
        if (in_array($pagenow, array('edit.php', 'post-new.php'), true)) {
            return self::owns_type(self::request_string($_GET, 'post_type', 'post'));
        }
        if ($pagenow === 'post.php') {
            $get_id = self::request_string($_GET, 'post');
            $post_id = self::request_string($_POST, 'post_ID');
            if (($get_id !== '' && !ctype_digit($get_id)) || ($post_id !== '' && !ctype_digit($post_id)) ||
                ($get_id !== '' && $post_id !== '' && $get_id !== $post_id)) { return false; }
            $id = (int) ($post_id !== '' ? $post_id : $get_id);
            $post = $id > 0 ? get_post($id) : null;
            return $post && self::owns_type($post->post_type);
        }
        if ($pagenow === 'revision.php') {
            $id = self::request_string($_GET, 'revision');
            if (!ctype_digit($id) || (int) $id < 1) { return false; }
            $revision = get_post((int) $id);
            $post = $revision && $revision->post_type === 'revision' ? get_post($revision->post_parent) : null;
            return $post && self::owns_type($post->post_type);
        }
        if (in_array($pagenow, array('edit-tags.php', 'term.php'), true)) {
            $taxonomy = self::request_string($_GET, 'taxonomy');
            $taxonomies = Dendrila_Simple_Edit_Plugin_Access::owned_taxonomies();
            if ($pagenow === 'term.php') {
                $id = self::request_string($_GET, 'tag_ID');
                if (!ctype_digit($id) || (int) $id < 1) { return false; }
                $term = get_term((int) $id);
                if (!$term || is_wp_error($term) || $term->taxonomy !== $taxonomy) { return false; }
            }
            return isset($taxonomies[$taxonomy]) && self::is_selected($taxonomies[$taxonomy]);
        }
        return false;
    }

    private static function settings_url()
    {
        return add_query_arg(array('page' => 'dendrila-simple-edit', 'dendrila_simple_edit_tab' => 'reglages',
            'dendrila_simple_edit_settings_section' => 'plugins'), admin_url('admin.php'));
    }

    public static function maybe_save()
    {
        if (self::request_string($_SERVER, 'REQUEST_METHOD') !== 'POST' ||
            self::request_string($_POST, 'dendrila_simple_edit_plugins_action') !== 'save') { return; }
        if (self::is_restricted_user() || !current_user_can('manage_options')) {
            wp_die(esc_html__('Accès refusé.', 'dendrila-simple-edit'), '', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_plugins');
        $submitted = isset($_POST['dendrila_simple_edit_plugins']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_plugins']), 'sanitize_text_field') : array();
        $catalog = get_option(self::CATALOG_OPTION, array());
        $active = self::active_plugins(); $selected = array();
        if (!is_array($submitted) || count($submitted) > count($active)) {
            wp_die(esc_html__('Sélection de plugins invalide.', 'dendrila-simple-edit'), '', array('response' => 400));
        }
        foreach ($submitted as $plugin) {
            if (!is_string($plugin) || !isset($active[$plugin], $catalog[$plugin]) || empty($catalog[$plugin]['menus'])) {
                wp_die(esc_html__('Ce plugin ne possède plus de menu disponible. Rechargez la page.', 'dendrila-simple-edit'), '', array('response' => 400));
            }
            $selected[] = $plugin;
        }
        update_option(self::OPTION, array_values(array_unique($selected)), false);
        wp_safe_redirect(add_query_arg('dendrila_simple_edit_plugins_saved', '1', self::settings_url()));
        exit;
    }

    public static function render_settings()
    {
        if (self::is_restricted_user() || !current_user_can('manage_options')) { return; }
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $plugins = get_plugins(); $catalog = get_option(self::CATALOG_OPTION, array());
        $active = self::active_plugins(); $selected = self::selected_plugins();
        echo '<section class="dendrila-simple-edit-card"><h2>' . esc_html__('Accès aux plugins', 'dendrila-simple-edit') . '</h2>';
        echo '<p>' . esc_html__('Choisissez les extensions que les comptes « Édition simple » pourront utiliser depuis leur administration, en plus de Simple Edit.', 'dendrila-simple-edit') . '</p>';
        echo '<p class="description">' . esc_html__('L’accès concerne les écrans et les actions du plugin choisi. Les extensions de gestion des utilisateurs, de sauvegarde ou de code peuvent donner des pouvoirs importants sur le site.', 'dendrila-simple-edit') . '</p>';
        if (self::request_string($_GET, 'dendrila_simple_edit_plugins_saved') === '1') {
            echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__('Les accès aux plugins ont été enregistrés.', 'dendrila-simple-edit') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(self::settings_url()) . '">';
        wp_nonce_field('dendrila_simple_edit_plugins');
        echo '<input type="hidden" name="dendrila_simple_edit_plugins_action" value="save">';
        if (!$active) { echo '<p>' . esc_html__('Aucune autre extension active.', 'dendrila-simple-edit') . '</p>'; }
        foreach ($active as $plugin => $unused) {
            $name = isset($plugins[$plugin]['Name']) ? wp_strip_all_tags($plugins[$plugin]['Name']) : $plugin;
            $available = !empty($catalog[$plugin]['menus']);
            $id = 'dse-plugin-' . substr(hash('sha256', $plugin), 0, 12);
            echo '<div class="dendrila-simple-edit-plugin-access-item"><label class="dendrila-simple-edit-check" for="' . esc_attr($id) . '">';
            echo '<input type="checkbox" id="' . esc_attr($id) . '" name="dendrila_simple_edit_plugins[]" value="' . esc_attr($plugin) . '" ' .
                checked(in_array($plugin, $selected, true), true, false) . disabled(!$available, true, false) .
                ' aria-describedby="' . esc_attr($id . '-description') . '"><strong>' . esc_html($name) . '</strong></label>';
            echo '<p class="description" id="' . esc_attr($id . '-description') . '">';
            echo $available ? esc_html(implode(' · ', array_unique($catalog[$plugin]['menus']))) :
                esc_html__('Aucun menu d’administration détecté pour cette extension.', 'dendrila-simple-edit');
            echo '</p></div>';
        }
        echo '<p><button class="button button-primary" type="submit">' . esc_html__('Enregistrer les accès aux plugins', 'dendrila-simple-edit') . '</button></p>';
        echo '<p class="description">' . esc_html__('Décochez une extension pour retirer son accès. Les changements s’appliquent dès la prochaine action. Aucun rôle administrateur n’est attribué.', 'dendrila-simple-edit') . '</p>';
        echo '<details><summary>' . esc_html__('Compatibilité des extensions', 'dendrila-simple-edit') . '</summary><p>' .
            esc_html__('Les pages, formulaires, contenus et actions qui peuvent être attribués au plugin sont pris en charge. Certaines extensions contrôlent directement le rôle administrateur, partagent des réglages WordPress ou passent par un service externe : ces écrans peuvent nécessiter une adaptation du plugin.', 'dendrila-simple-edit') . '</p></details>';
        echo '</form></section>';
    }
}
