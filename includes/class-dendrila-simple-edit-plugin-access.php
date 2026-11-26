<?php
/** Request-local permission adapters for explicitly selected active plugins. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Plugin_Access
{
    private static $checking = false;
    private static $post_types = array();
    private static $taxonomies = array();
    private static $settings = array();
    private static $settings_hooks = array();
    private static $rest_context = array();

    public static function boot()
    {
        add_filter('user_has_cap', array(__CLASS__, 'filter_capabilities'), 30, 4);
        add_filter('register_post_type_args', array(__CLASS__, 'post_type_args'), 100, 2);
        add_filter('register_taxonomy_args', array(__CLASS__, 'taxonomy_args'), 100, 3);
        add_filter('register_setting_args', array(__CLASS__, 'setting_args'), 100, 4);
        add_filter('allowed_options', array(__CLASS__, 'allowed_options'), PHP_INT_MAX);
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'rest_before_callbacks'), PHP_INT_MIN, 3);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'rest_after_callbacks'), PHP_INT_MAX, 3);
    }

    /** All observed active-plugin registrations, including unselected plugins. */
    public static function owned_post_types() { return self::$post_types; }
    public static function owned_taxonomies() { return self::$taxonomies; }

    private static function file_owner($file)
    {
        return is_string($file) && $file !== '' ?
            Dendrila_Simple_Edit_Admin_Access::plugin_for_file($file) : '';
    }

    /**
     * Identify the immediate source of a permission check or named registration.
     * Only capability wrappers and the two menu registration APIs are traversed.
     * A selected plugin anywhere deeper in the stack is never sufficient: core
     * business handlers and callbacks belonging to another plugin stay isolated.
     */
    public static function origin_plugin($registration = '')
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);
        if ($registration !== '') {
            if (!in_array($registration, array('register_post_type', 'register_taxonomy',
                'register_setting', 'add_menu_page', 'add_submenu_page'), true)) { return ''; }
            foreach ($trace as $frame) {
                if (isset($frame['function']) && $frame['function'] === $registration) {
                    return self::file_owner(isset($frame['file']) ? $frame['file'] : '');
                }
            }
            return '';
        }

        $wrappers = array('user_can', 'current_user_can', 'current_user_can_for_site',
            'current_user_can_for_blog');
        $found = false;
        $origin = '';
        $next_index = null;
        foreach ($trace as $index => $frame) {
            $function = isset($frame['function']) ? $frame['function'] : '';
            $is_user_method = $function === 'has_cap' && isset($frame['class']) &&
                $frame['class'] === 'WP_User';
            if (!$found) {
                if (!$is_user_method && !in_array($function, $wrappers, true)) { continue; }
                $found = true;
            } elseif (!$is_user_method && !in_array($function, $wrappers, true)) {
                $next_index = $index;
                break;
            }
            $origin = isset($frame['file']) ? $frame['file'] : '';
        }
        if ($found) {
            $owner = self::file_owner($origin);
            if ($owner !== '') { return $owner; }
            // add_*_page checks capabilities inside core before registering the
            // callback. Its immediate caller is the trusted menu registration.
            $menu_wrappers = array('add_menu_page', 'add_submenu_page', 'add_options_page',
                'add_management_page', 'add_dashboard_page', 'add_posts_page', 'add_media_page',
                'add_links_page', 'add_pages_page', 'add_comments_page', 'add_theme_page',
                'add_plugins_page', 'add_users_page');
            if ($next_index !== null) {
                for ($index = $next_index; isset($trace[$index]); $index++) {
                    $frame = $trace[$index];
                    if (!isset($frame['function']) ||
                        !in_array($frame['function'], $menu_wrappers, true)) { break; }
                    $file = isset($frame['file']) ? $frame['file'] : '';
                    $owner = self::file_owner($file);
                    if ($owner !== '') { return $owner; }
                    // Follow add_options_page -> add_submenu_page only through
                    // the real core wrappers, never an unrelated plugin helper.
                    $core_file = realpath(ABSPATH . 'wp-admin/includes/plugin.php');
                    if (!$file || !$core_file || realpath($file) !== $core_file) { break; }
                }
            }
            return '';
        }
        // Also usable directly by plugin registration adapters and fixtures.
        return isset($trace[0]['file']) ? self::file_owner($trace[0]['file']) : '';
    }

    /** A different plugin cannot borrow a selected plugin's permission helper. */
    private static function has_unselected_plugin_caller()
    {
        // Use the complete stack: a wrapper depth must not conceal the actual
        // unselected callback. Core files and Simple Edit have no plugin owner.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $owner = self::file_owner(isset($frame['file']) ? $frame['file'] : '');
            if ($owner !== '' && !Dendrila_Simple_Edit_Admin_Access::is_selected($owner)) {
                return true;
            }
        }
        return false;
    }

    /** Bind REST permission checks to the actual business callback, not its alias. */
    public static function rest_before_callbacks($response, $handler, $request)
    {
        $owner = isset($handler['callback']) && is_callable($handler['callback']) ?
            Dendrila_Simple_Edit_Admin_Access::callback_owner($handler['callback']) : '';
        self::$rest_context[] = array('request' => $request, 'plugin' => $owner);
        return $response;
    }

    /** Nested rest_do_request() calls restore the enclosing callback's owner. */
    public static function rest_after_callbacks($response, $handler, $request)
    {
        for ($index = count(self::$rest_context) - 1; $index >= 0; $index--) {
            if (self::$rest_context[$index]['request'] === $request) {
                self::$rest_context = array_slice(self::$rest_context, 0, $index);
                break;
            }
        }
        return $response;
    }

    private static function allows_rest_plugin_permission()
    {
        if (!self::$rest_context) { return true; }
        $context = end(self::$rest_context);
        return $context['plugin'] !== '' &&
            Dendrila_Simple_Edit_Admin_Access::is_selected($context['plugin']);
    }

    /** Never modify role/user records or answer a capability check for another user. */
    public static function filter_capabilities($allcaps, $caps, $args, $user)
    {
        if (self::$checking || !is_object($user) || empty($user->ID) ||
            (int) $user->ID !== (int) get_current_user_id() ||
            !Dendrila_Simple_Edit_Admin_Access::is_restricted_user($user) ||
            in_array('do_not_allow', (array) $caps, true)) { return $allcaps; }
        self::$checking = true;
        try {
            if (self::has_unselected_plugin_caller()) { return $allcaps; }
            foreach (Dendrila_Simple_Edit_Admin_Access::selected_plugins() as $plugin) {
                $synthetic = Dendrila_Simple_Edit_Admin_Access::plugin_capability($plugin);
                if (in_array($synthetic, $caps, true)) { $allcaps[$synthetic] = true; }
            }
            $plugin = self::origin_plugin();
            if ($plugin === '' || !Dendrila_Simple_Edit_Admin_Access::is_selected($plugin) ||
                !self::allows_rest_plugin_permission()) {
                return $allcaps;
            }
            $trusted = Dendrila_Simple_Edit_Admin_Access::plugin_permissions($plugin);
            foreach ($caps as $cap) {
                if ($cap !== 'do_not_allow' && in_array($cap, $trusted, true)) {
                    $allcaps[$cap] = true;
                }
            }
            return $allcaps;
        } finally {
            self::$checking = false;
        }
    }

    private static function selected_for_current_user($plugin)
    {
        return $plugin !== '' && Dendrila_Simple_Edit_Admin_Access::is_selected($plugin) &&
            Dendrila_Simple_Edit_Admin_Access::is_restricted_user();
    }

    public static function post_type_args($args, $post_type)
    {
        $reserved = array('post', 'page', 'attachment', 'revision', 'nav_menu_item',
            'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
            'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles',
            'wp_navigation', 'wp_font_family', 'wp_font_face');
        if (!is_array($args) || !empty($args['_builtin']) ||
            in_array($post_type, $reserved, true)) { return $args; }
        $plugin = self::origin_plugin('register_post_type');
        if ($plugin === '') { return $args; }
        self::$post_types[$post_type] = $plugin;
        if (!self::selected_for_current_user($plugin)) { return $args; }

        $caps = isset($args['capabilities']) && is_array($args['capabilities']) ?
            $args['capabilities'] : array();
        $capability_type = isset($args['capability_type']) ? $args['capability_type'] : 'post';
        // Match WP_Post_Type's defaults before adding explicit primitive caps.
        $map = isset($args['map_meta_cap']) ? (bool) $args['map_meta_cap'] :
            (!$caps && in_array($capability_type, array('post', 'page'), true));
        $args['map_meta_cap'] = $map;
        $keys = array('edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts',
            'read_private_posts', 'create_posts', 'read', 'delete_private_posts',
            'delete_published_posts', 'delete_others_posts', 'edit_private_posts',
            'edit_published_posts');
        if (!$map) {
            // With mapping disabled, these three fields are primitives as well.
            $keys = array_merge($keys, array('edit_post', 'read_post', 'delete_post'));
        }
        $synthetic = Dendrila_Simple_Edit_Admin_Access::plugin_capability($plugin);
        foreach ($keys as $key) {
            if (isset($caps[$key]) && $caps[$key] === 'do_not_allow') { continue; }
            $caps[$key] = $synthetic;
        }
        $args['capabilities'] = $caps;
        return $args;
    }

    public static function taxonomy_args($args, $taxonomy, $object_types)
    {
        if (!is_array($args) || !empty($args['_builtin']) ||
            in_array($taxonomy, array('category', 'post_tag', 'nav_menu', 'link_category',
                'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category'), true)) {
            return $args;
        }
        $plugin = self::origin_plugin('register_taxonomy');
        if ($plugin === '') { return $args; }
        self::$taxonomies[$taxonomy] = $plugin;
        if (!self::selected_for_current_user($plugin)) { return $args; }
        $caps = isset($args['capabilities']) && is_array($args['capabilities']) ?
            $args['capabilities'] : array();
        foreach (array('manage_terms', 'edit_terms', 'delete_terms', 'assign_terms') as $key) {
            if (isset($caps[$key]) && $caps[$key] === 'do_not_allow') { continue; }
            $caps[$key] = Dendrila_Simple_Edit_Admin_Access::plugin_capability($plugin);
        }
        $args['capabilities'] = $caps;
        return $args;
    }

    private static function reserved_group($group)
    {
        return !is_string($group) || $group === '' ||
            in_array($group, array('general', 'writing', 'reading', 'discussion', 'media',
                'permalink', 'permalinks', 'privacy', 'misc', 'options'), true);
    }

    private static function protected_option($option)
    {
        return !is_string($option) || $option === '' ||
            strpos($option, 'dendrila_simple_edit') === 0 ||
            substr($option, -11) === '_user_roles' ||
            in_array($option, array('siteurl', 'home', 'admin_email', 'new_admin_email',
                'adminhash', 'users_can_register', 'default_role', 'active_plugins',
                'active_sitewide_plugins', 'uninstall_plugins', 'template', 'stylesheet'), true);
    }

    /** Remember exclusive ownership; a mixed/unknown group fails closed. */
    public static function setting_args($args, $defaults, $group, $option)
    {
        if (self::reserved_group($group)) { return $args; }
        $plugin = self::origin_plugin('register_setting');
        if (!isset(self::$settings[$group])) {
            self::$settings[$group] = array('plugin' => $plugin, 'options' => array());
        } elseif (self::$settings[$group]['plugin'] !== $plugin) {
            self::$settings[$group]['plugin'] = '';
        }
        if ($plugin === '' || self::protected_option($option)) {
            self::$settings[$group]['plugin'] = '';
        }
        self::$settings[$group]['options'][$option] = $plugin;
        if (!isset(self::$settings_hooks[$group])) {
            self::$settings_hooks[$group] = true;
            add_filter('option_page_capability_' . $group, static function ($cap) use ($group) {
                if (!self::allows_settings_request() || self::requested_group() !== $group) { return $cap; }
                return Dendrila_Simple_Edit_Admin_Access::plugin_capability(self::$settings[$group]['plugin']);
            }, PHP_INT_MAX);
        }
        return $args;
    }

    private static function requested_group()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Settings group read to verify its own native nonce before granting rights.
        if (!isset($_POST['option_page']) || !is_string($_POST['option_page'])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Settings group read to verify its own native nonce before granting rights.
        $raw_group = wp_unslash($_POST['option_page']);
        $group = sanitize_text_field($raw_group);
        // Keep the exact registered key; reject manipulated names before
        // looking up capabilities or verifying the group-specific nonce.
        return $raw_group === $group ? $group : '';
    }

    public static function allows_settings_request()
    {
        global $pagenow;
        if ($pagenow !== 'options.php' ||
            !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ||
            !isset($_POST['action']) || $_POST['action'] !== 'update' ||
            isset($_GET['adminhash']) || isset($_GET['dismiss']) ||
            !Dendrila_Simple_Edit_Admin_Access::is_restricted_user()) { return false; }
        $group = self::requested_group();
        if (self::reserved_group($group) || !isset(self::$settings[$group]) ||
            !self::$settings[$group]['options'] ||
            !self::selected_for_current_user(self::$settings[$group]['plugin'])) { return false; }
        // Check Settings API's native nonce *before* temporarily granting the
        // selected plugin's capability. Core options.php validates it again
        // before saving, but this adapter must fail closed independently.
        if (!isset($_POST['_wpnonce']) || !is_string($_POST['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), $group . '-options')) {
            return false;
        }
        // options.php reads $_REQUEST. Conflicting GET/cookie values must not
        // make its selected group/action differ from our validated POST request.
        if ((isset($_REQUEST['option_page']) &&
                (!is_string($_REQUEST['option_page']) ||
                    sanitize_text_field(wp_unslash($_REQUEST['option_page'])) !== $group)) ||
            (isset($_REQUEST['action']) &&
                (!is_string($_REQUEST['action']) ||
                    sanitize_text_field(wp_unslash($_REQUEST['action'])) !== 'update'))) { return false; }
        return true;
    }

    /** Limit the native Settings API writer to the verified plugin's own keys. */
    public static function allowed_options($allowed)
    {
        if (!is_array($allowed) || !self::allows_settings_request()) { return $allowed; }
        $group = self::requested_group();
        $owned = array_keys(self::$settings[$group]['options']);
        if (isset($allowed[$group]) && is_array($allowed[$group])) {
            $allowed[$group] = array_values(array_intersect($allowed[$group], $owned));
        }
        return $allowed;
    }
}
