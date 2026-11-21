<?php
/**
 * WordPress-owned, explicitly mapped editing surface.
 *
 * Unlike Dendrila CMS, this adapter never owns a second content database.
 * Site fields are stored as one revisioned WordPress option; shortcodes are
 * opt-in placements by a site administrator.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class DSE_Plugin
{
    const ROLE = 'dendrila_simple_editor';
    const LEGACY_ROLE = 'dendrila_site_manager';
    const ROLE_SCHEMA_OPTION = 'dse_simple_editor_role_v1';
    const STATE_OPTION = 'dse_state';
    const ENABLED_OPTION = 'dse_enabled_fields';
    const CUSTOM_OPTION = 'dse_custom_fields';

    public static function definitions()
    {
        $definitions = array(
            'phone' => array(
                'label' => 'Téléphone',
                'help' => 'Numéro de téléphone public, avec indicatif si nécessaire.',
                'type' => 'tel',
                'max' => 80,
            ),
            'hours' => array(
                'label' => 'Horaires',
                'help' => 'Un jour ou une plage d’ouverture par ligne.',
                'type' => 'textarea',
                'max' => 1200,
            ),
            'address' => array(
                'label' => 'Adresse',
                'help' => 'Adresse postale à afficher sur le site.',
                'type' => 'textarea',
                'max' => 600,
            ),
            'presentation' => array(
                'label' => 'Présentation',
                'help' => 'Un court texte de présentation, sans code HTML.',
                'type' => 'textarea',
                'max' => 3000,
            ),
            'photo' => array(
                'label' => 'Photo principale',
                'help' => 'Choisissez une image du site ou importez-en une nouvelle. Vous pouvez toujours réutiliser vos propres images.',
                'type' => 'image',
                'max' => 15,
            ),
        );
        $custom = get_option(self::CUSTOM_OPTION, array());
        if (is_array($custom)) {
            foreach ($custom as $key => $item) {
                if (!is_string($key) || preg_match('/^zone_[a-z0-9_]{1,30}$/', $key) !== 1 ||
                    !is_array($item) || !isset($item['label'], $item['type']) ||
                    !is_string($item['label']) || $item['label'] === '' ||
                    !in_array($item['type'], array('text', 'textarea'), true)) {
                    continue;
                }
                $definitions[$key] = array(
                    'label' => $item['label'],
                    'help' => 'Zone de contenu définie par l’administration.',
                    'type' => $item['type'],
                    'max' => $item['type'] === 'text' ? 160 : 3000,
                );
            }
        }
        return $definitions;
    }

    public static function empty_state()
    {
        return array('revision' => 0, 'values' => array(), 'history' => array());
    }

    public static function activate()
    {
        self::upgrade_editor_role();
        add_option(self::STATE_OPTION, self::empty_state(), '', false);
        add_option(self::ENABLED_OPTION, array(), '', false);
        add_option(self::CUSTOM_OPTION, array(), '', false);
        add_option(DSE_Media::OPTION, array(), '', false);
        add_option(DSE_Media::ALL_OPTION, '0', '', false);
    }

    /**
     * Upgrades must also work when an administrator replaces the plugin ZIP:
     * WordPress doesn't run the activation hook for a normal plugin update.
     */
    public static function upgrade_editor_role()
    {
        if (get_option(self::ROLE_SCHEMA_OPTION, false) === '1') { return; }
        // No edit_posts, edit_pages, edit_others_posts, manage_options or delete_posts.
        // upload_files is needed for the native WordPress media uploader.
        $caps = array('read' => true, 'dse_access' => true,
            'dse_edit' => true, 'dse_publish' => true, 'upload_files' => true);
        add_role(self::ROLE, 'Édition simple', $caps);
        $role = get_role(self::ROLE);
        if (!$role) { return; }
        foreach ($caps as $cap => $granted) { $role->add_cap($cap, $granted); }
        // Migrate only users with the obsolete dedicated role; preserve other roles.
        $old = get_role(self::LEGACY_ROLE);
        if ($old) {
            $users = get_users(array('role' => self::LEGACY_ROLE, 'fields' => 'all'));
            foreach ($users as $user) {
                if (!is_a($user, 'WP_User')) { continue; }
                $user->add_role(self::ROLE);
                $user->remove_role(self::LEGACY_ROLE);
            }
            remove_role(self::LEGACY_ROLE);
        }
        update_option(self::ROLE_SCHEMA_OPTION, '1', false);
    }

    public static function is_simple_editor()
    {
        $user = wp_get_current_user();
        return $user && in_array(self::ROLE, (array) $user->roles, true) &&
            !current_user_can('manage_options');
    }

    /**
     * WooCommerce normally sends users without edit_posts to "My Account".
     * Delegated editors deliberately lack that capability, but must still reach
     * their own restricted Simple Edit administration page.
     */
    public static function allow_delegated_admin_access($blocked)
    {
        if (self::is_simple_editor() && self::can_access()) { return false; }
        return $blocked;
    }

    private static function is_delegated_login($user)
    {
        return $user instanceof WP_User &&
            in_array(self::ROLE, (array) $user->roles, true) &&
            user_can($user, 'dse_access') && !user_can($user, 'manage_options');
    }

    /** Always land on Simple Edit after a normal WordPress login. */
    public static function redirect_wp_login($redirect, $requested_redirect, $user)
    {
        return self::is_delegated_login($user) ?
            admin_url('admin.php?page=dendrila-simple-edit') : $redirect;
    }

    /** WooCommerce's My Account login uses a separate redirect hook. */
    public static function redirect_woocommerce_login($redirect, $user)
    {
        return self::is_delegated_login($user) ?
            admin_url('admin.php?page=dendrila-simple-edit') : $redirect;
    }

    /** Keep the admin interface restricted without altering core role permissions. */
    public static function limit_admin_menu()
    {
        if (!self::is_simple_editor()) { return; }
        global $menu, $submenu;
        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (!isset($item[2]) || $item[2] !== 'dendrila-simple-edit') {
                    unset($menu[$index]);
                }
            }
        }
        if (is_array($submenu)) {
            foreach (array_keys($submenu) as $key) {
                if ($key !== 'dendrila-simple-edit') { unset($submenu[$key]); }
            }
        }
    }

    public static function restrict_admin_pages()
    {
        if (!self::is_simple_editor()) { return; }
        global $pagenow;
        // Core AJAX/media upload handlers need to remain reachable. These enforce
        // their own nonces and capability checks. Profile allows password changes.
        if (in_array($pagenow, array('admin-ajax.php', 'admin-post.php',
            'async-upload.php', 'profile.php'), true)) { return; }
        if ($pagenow === 'admin.php' && isset($_GET['page']) &&
            $_GET['page'] === 'dendrila-simple-edit') { return; }
        wp_safe_redirect(admin_url('admin.php?page=dendrila-simple-edit'));
        exit;
    }

    public static function boot()
    {
        add_action('init', array(__CLASS__, 'upgrade_editor_role'), 5);
        add_filter('woocommerce_prevent_admin_access',
            array(__CLASS__, 'allow_delegated_admin_access'), 999);
        add_filter('login_redirect', array(__CLASS__, 'redirect_wp_login'), 999, 3);
        add_filter('woocommerce_login_redirect',
            array(__CLASS__, 'redirect_woocommerce_login'), 999, 2);
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_menu', array(__CLASS__, 'limit_admin_menu'), 999);
        // The final menu hook catches menu items added by other extensions later.
        add_action('adminmenu', array(__CLASS__, 'limit_admin_menu'), 999);
        add_action('admin_init', array(__CLASS__, 'restrict_admin_pages'), 99);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_post_dse_save', array(__CLASS__, 'save'));
        add_action('admin_post_dse_restore', array(__CLASS__, 'restore'));
        add_action('admin_post_dse_configure', array(__CLASS__, 'configure'));
        add_action('admin_post_dse_add_field', array(__CLASS__, 'add_field'));
        add_shortcode('dendrila_simple_edit', array(__CLASS__, 'shortcode'));
        DSE_ACF::boot();
        DSE_Media::boot();
        DSE_Modules::boot();
        DSE_Newsletters::boot();
        DSE_Post_Editor::boot();
        DSE_Navigation::boot();
        DSE_Event_Editor::boot();
        DSE_Mailing::boot();
        DSE_Woo::boot();
    }

    public static function can_access()
    {
        return current_user_can('manage_options') || current_user_can('dse_access');
    }

    public static function can_edit()
    {
        return current_user_can('manage_options') ||
            (current_user_can('dse_access') && current_user_can('dse_edit') && current_user_can('dse_publish'));
    }

    public static function enabled_fields()
    {
        $enabled = get_option(self::ENABLED_OPTION, array());
        if (!is_array($enabled)) {
            return array();
        }
        $known = array_keys(self::definitions());
        // Whitelist: never trust saved settings as arbitrary option/post keys.
        return array_values(array_intersect($known, $enabled));
    }

    public static function state()
    {
        $state = get_option(self::STATE_OPTION, self::empty_state());
        if (!is_array($state) || !isset($state['revision'], $state['values'], $state['history']) ||
            !is_int($state['revision']) || !is_array($state['values']) || !is_array($state['history'])) {
            return self::empty_state();
        }
        return $state;
    }

    public static function register_menu()
    {
        if (!self::can_access()) {
            return;
        }
        add_menu_page(
            'Modifier mon site',
            'Modifier mon site',
            'read',
            'dendrila-simple-edit',
            array(__CLASS__, 'render'),
            'dashicons-edit-page',
            3
        );
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_dendrila-simple-edit' || !self::can_access()) {
            return;
        }
        wp_enqueue_style(
            'dse-admin',
            plugins_url('assets/admin.css', DSE_PLUGIN_FILE),
            array(),
            DSE_VERSION . "." . filemtime(dirname(DSE_PLUGIN_FILE) . "/assets/admin.css")
        );
        $script_dependencies = array();
        $tab = self::active_tab();
        if ((current_user_can('manage_options') && $tab === 'reglages') ||
            ($tab === 'contenus' && self::can_edit() &&
                ((self::is_simple_editor() && current_user_can('upload_files')) ||
                 in_array('photo', self::enabled_fields(), true))) ||
            ($tab === 'boutique' && self::can_edit() && self::module_visible('products'))) {
            // Also enable uploads from the editor's own panel, even when the
            // administrator hasn't enabled any editable photo shortcode.
            wp_enqueue_media();
            $script_dependencies[] = 'media-views';
        }
        if (in_array($tab, array('newsletters', 'pages', 'articles', 'evenements'), true)) {
            wp_enqueue_media();
            wp_enqueue_script('dse-block-editor', plugins_url('assets/block-editor.js', DSE_PLUGIN_FILE),
                array('media-views'), DSE_VERSION . '.' . filemtime(dirname(DSE_PLUGIN_FILE) . '/assets/block-editor.js'), true);
        }
        wp_enqueue_script(
            'dse-admin',
            plugins_url('assets/admin.js', DSE_PLUGIN_FILE),
            $script_dependencies,
            DSE_VERSION . "." . filemtime(dirname(DSE_PLUGIN_FILE) . "/assets/admin.js"),
            true
        );
    }

    public static function sanitize_field($key, $value)
    {
        $definitions = self::definitions();
        if (!isset($definitions[$key]) || !is_string($value)) {
            throw new InvalidArgumentException('Champ inconnu ou valeur invalide.');
        }
        $value = wp_unslash($value);
        if ($definitions[$key]['type'] === 'image') {
            return DSE_Media::sanitize_selection($value);
        }
        $value = $definitions[$key]['type'] === 'textarea' ?
            sanitize_textarea_field($value) : sanitize_text_field($value);
        if (strlen($value) > $definitions[$key]['max'] * 4) {
            // Bound bytes as well as characters; avoid unbounded option payloads.
            throw new InvalidArgumentException('La valeur est trop longue.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $definitions[$key]['max']) {
            throw new InvalidArgumentException('La valeur est trop longue.');
        }
        return $value;
    }

    public static function validate_changes($input, $enabled)
    {
        if (!is_array($input) || !$input) {
            throw new InvalidArgumentException('Aucun champ à enregistrer.');
        }
        $allowed = array_fill_keys($enabled, true);
        $changes = array();
        foreach ($input as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Ce champ n’est pas autorisé.');
            }
            $changes[$key] = self::sanitize_field($key, $value);
        }
        return $changes;
    }

    private static function require_editor($nonce_action)
    {
        if (!self::can_edit()) {
            wp_die('Vous ne pouvez pas modifier ces contenus.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer($nonce_action);
    }

    private static function submitted_revision()
    {
        $raw = isset($_POST['dse_revision']) ? wp_unslash($_POST['dse_revision']) : null;
        if (!is_string($raw) || !ctype_digit($raw)) {
            wp_die('Version de contenu manquante.', 'Requête incorrecte', array('response' => 400));
        }
        return (int) $raw;
    }

    /**
     * Compare-and-swap performed as one SQL statement. WordPress's update_option
     * alone cannot reject two concurrent writes that saw the same old revision.
     */
    private static function compare_and_swap($old, $new)
    {
        global $wpdb;
        $old_raw = maybe_serialize($old);
        $new_raw = maybe_serialize($new);
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %s
             WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $new_raw,
            self::STATE_OPTION,
            $old_raw
        ));
        if ($affected === false) {
            return 'error';
        }
        if ($affected !== 1) {
            return 'conflict';
        }
        wp_cache_delete(self::STATE_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        return 'saved';
    }

    private static function commit_change($expected, $changes, $restore_revision = null)
    {
        $old = get_option(self::STATE_OPTION, null);
        if (!is_array($old) || !isset($old['revision'], $old['values'], $old['history']) ||
            !is_int($old['revision']) || !is_array($old['values']) || !is_array($old['history'])) {
            return 'error';
        }
        if ($expected !== $old['revision']) {
            return 'conflict';
        }

        $enabled = self::enabled_fields();
        if (!$enabled) {
            return 'unconfigured';
        }
        if ($restore_revision !== null) {
            $snapshot = null;
            foreach ($old['history'] as $entry) {
                if (is_array($entry) && isset($entry['revision']) &&
                    (int) $entry['revision'] === $restore_revision &&
                    isset($entry['values']) && is_array($entry['values'])) {
                    $snapshot = $entry['values'];
                    break;
                }
            }
            if ($snapshot === null) {
                return 'missing_revision';
            }
            $changes = array();
            // A later change to the allowlist must never unlock hidden fields.
            foreach ($enabled as $key) {
                $previous = isset($snapshot[$key]) && is_string($snapshot[$key]) ?
                    $snapshot[$key] : '';
                // An image removed from the admin-approved pool cannot be
                // reselected by restoring an older version. Preserve the live
                // image while restoring other still-authorized fields.
                if ($key === 'photo') {
                    $current_image = isset($old['values']['photo']) ? (string) $old['values']['photo'] : '';
                    if (($current_image !== '' && !DSE_Media::allowed_image((int) $current_image)) ||
                        ($previous !== '' && !DSE_Media::allowed_image((int) $previous))) {
                        continue;
                    }
                }
                $changes[$key] = self::sanitize_field($key, $previous);
            }
        } else {
            // A photo may remain published after its selection permission is
            // withdrawn. Keeping that same value must not block unrelated edits.
            if (isset($changes['photo']) && is_string($changes['photo']) &&
                $changes['photo'] === (isset($old['values']['photo']) ?
                    (string) $old['values']['photo'] : '') &&
                $changes['photo'] !== '' &&
                !DSE_Media::allowed_image((int) $changes['photo'])) {
                unset($changes['photo']);
            }
            if (!$changes) { return 'unchanged'; }
            $changes = self::validate_changes($changes, $enabled);
        }

        $updated_values = array_replace($old['values'], $changes);
        if ($updated_values === $old['values']) {
            return 'unchanged';
        }
        $changed_keys = array();
        foreach ($changes as $key => $value) {
            if (!isset($old['values'][$key]) || $old['values'][$key] !== $value) {
                $changed_keys[] = $key;
            }
        }
        $history = $old['history'];
        array_unshift($history, array(
            'revision' => $old['revision'],
            'values' => $old['values'],
            'changed' => $changed_keys,
            'timestamp' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
        ));

        $new = array(
            'revision' => $old['revision'] + 1,
            'values' => $updated_values,
            'history' => array_slice($history, 0, 15),
        );
        $result = self::compare_and_swap($old, $new);
        if ($result === 'saved') {
            // Cache plugins can listen to this event; WP cannot invalidate every
            // reverse proxy / external full-page cache by itself.
            do_action('dse_fields_updated', $changed_keys, $new['revision']);
        }
        return $result;
    }

    public static function save()
    {
        self::require_editor('dse_save');
        $expected = self::submitted_revision();
        $input = isset($_POST['dse_fields']) ? $_POST['dse_fields'] : array();
        try {
            $result = self::commit_change($expected, $input);
        } catch (InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), 'Champ non autorisé', array('response' => 400));
        }
        self::redirect($result);
    }

    public static function restore()
    {
        self::require_editor('dse_restore');
        $expected = self::submitted_revision();
        $raw = isset($_POST['dse_restore_revision']) ? wp_unslash($_POST['dse_restore_revision']) : null;
        if (!is_string($raw) || !ctype_digit($raw)) {
            wp_die('Révision incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        try {
            $result = self::commit_change($expected, array(), (int) $raw);
        } catch (InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), 'Restauration impossible', array('response' => 400));
        }
        self::redirect($result);
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_configure');
        $input = isset($_POST['dse_enabled']) ? wp_unslash($_POST['dse_enabled']) : array();
        if (!is_array($input)) {
            wp_die('Configuration incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        foreach ($input as $key) {
            if (!is_string($key) || !array_key_exists($key, self::definitions())) {
                wp_die('Champ de configuration inconnu.', 'Requête incorrecte', array('response' => 400));
            }
        }
        // Saving this option never changes any public content.
        update_option(self::ENABLED_OPTION, array_values(array_intersect(array_keys(self::definitions()), $input)), false);
        self::redirect('configured');
    }

    public static function add_field()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_add_field');
        $label_input = isset($_POST['dse_label']) ? wp_unslash($_POST['dse_label']) : null;
        $type_input = isset($_POST['dse_field_type']) ? wp_unslash($_POST['dse_field_type']) : null;
        if (!is_string($label_input) || !is_string($type_input) ||
            !in_array($type_input, array('text', 'textarea'), true)) {
            wp_die('Zone de contenu incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        $label = sanitize_text_field($label_input);
        $length = function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label);
        if ($label === '' || $length > 80) {
            wp_die('Le libellé est obligatoire (80 caractères maximum).', 'Requête incorrecte', array('response' => 400));
        }
        $slug_base = substr(trim(str_replace('-', '_', sanitize_title($label)), '_'), 0, 26);
        if ($slug_base === '' || preg_match('/^[a-z0-9_]+$/', $slug_base) !== 1) {
            wp_die('Le nom ne peut pas servir à identifier une zone.', 'Requête incorrecte', array('response' => 400));
        }
        $slug_base = 'zone_' . $slug_base;
        $custom = get_option(self::CUSTOM_OPTION, array());
        if (!is_array($custom) || count($custom) >= 20) {
            wp_die('Limite de 20 zones atteinte ou configuration incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        $slug = $slug_base;
        $suffix = 2;
        while (isset($custom[$slug])) {
            $slug = $slug_base . '_' . $suffix++;
        }
        $custom[$slug] = array('label' => $label, 'type' => $type_input);
        if (!update_option(self::CUSTOM_OPTION, $custom, false)) {
            wp_die('La zone n’a pas pu être enregistrée.', 'Erreur', array('response' => 500));
        }
        $enabled = self::enabled_fields();
        if (!in_array($slug, $enabled, true)) {
            $enabled[] = $slug;
            update_option(self::ENABLED_OPTION, $enabled, false);
        }
        wp_safe_redirect(add_query_arg(array('dse_notice' => 'field_added', 'dse_created' => $slug),
            self::tab_link('reglages')) . '#dse-shared-text');
        exit;
    }

    public static function shortcode($atts)
    {
        $attributes = shortcode_atts(array('field' => ''), $atts, 'dendrila_simple_edit');
        $key = $attributes['field'];
        if (!is_string($key) || !array_key_exists($key, self::definitions())) {
            return '';
        }
        $state = self::state();
        $value = isset($state['values'][$key]) && is_string($state['values'][$key]) ?
            $state['values'][$key] : '';
        if ($key === 'photo') {
            return DSE_Media::render_image($value);
        }
        return nl2br(esc_html($value), false);
    }

    private static function redirect($notice)
    {
        $allowed = array('saved', 'unchanged', 'conflict', 'unconfigured', 'missing_revision', 'error', 'configured', 'field_added', 'acf_added', 'acf_removed', 'acf_duplicate', 'acf_saved', 'acf_unchanged', 'acf_conflict', 'acf_error', 'media_configured', 'module_configured');
        if (!in_array($notice, $allowed, true)) {
            $notice = 'error';
        }
        wp_safe_redirect(add_query_arg(array(
            'dse_notice' => $notice,
            'dse_tab' => in_array($notice, array('configured', 'field_added'), true) ? 'reglages' :
                ($notice === 'restored' ? 'historique' : 'contenus'),
        ), admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    private static function notices()
    {
        $notice = isset($_GET['dse_notice']) && is_string($_GET['dse_notice']) ?
            sanitize_key(wp_unslash($_GET['dse_notice'])) : '';
        $messages = array(
            'saved' => array('updated', 'Modifications enregistrées. Vérifiez le site public : un cache peut retarder l’affichage.'),
            'unchanged' => array('updated', 'Aucune modification à enregistrer.'),
            'configured' => array('updated', 'Champs autorisés mis à jour.'),
            'field_added' => array('updated', 'Texte partagé créé. Suivez les étapes dans la rubrique « Textes partagés ».'),
            'acf_added' => array('updated', 'Champ ACF autorisé.'),
            'acf_removed' => array('updated', 'Autorisation ACF retirée. La valeur publique a été conservée.'),
            'acf_duplicate' => array('updated', 'Ce champ ACF est déjà autorisé.'),
            'acf_saved' => array('updated', 'Champ ACF mis à jour. Le cache public peut nécessiter une purge.'),
            'acf_unchanged' => array('updated', 'Ce champ ACF est déjà à jour.'),
            'acf_conflict' => array('error', 'Le champ ACF a changé entre-temps. Rechargez la page avant de réessayer.'),
            'acf_error' => array('error', 'Champ ACF non enregistré. Aucun succès ne peut être confirmé.'),
            'media_configured' => array('updated', 'La sélection des images autorisées a été mise à jour.'),
            'module_configured' => array('updated', 'Autorisations du module mises à jour.'),
            'conflict' => array('error', 'Une autre modification a eu lieu. Rechargez la page avant de réessayer.'),
            'unconfigured' => array('error', 'Aucun champ n’a été autorisé par l’administration.'),
            'missing_revision' => array('error', 'Cette révision n’est plus disponible.'),
            'error' => array('error', 'Enregistrement impossible. Aucun contenu n’a été changé.'),
        );
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-' . esc_attr($messages[$notice][0]) .
                '"><p>' . esc_html($messages[$notice][1]) . '</p></div>';
        }
    }

    /**
     * Advisory discovery only: published WordPress pages/posts whose raw body
     * mentions the exact, canonical field shortcode. Theme templates and page
     * builder metadata are intentionally not claimed as detected.
     */
    public static function public_locations($field)
    {
        if (!in_array($field, self::enabled_fields(), true)) {
            return array();
        }
        global $wpdb;
        $needle = '%' . $wpdb->esc_like('[dendrila_simple_edit') . '%';
        $field_needle = '%' . $wpdb->esc_like('field="' . $field . '"') . '%';
        $query = $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('page', 'post')
               AND post_content LIKE %s
               AND post_content LIKE %s
             ORDER BY post_title ASC
             LIMIT 30",
            $needle,
            $field_needle
        );
        $pages = $wpdb->get_results($query);
        return is_array($pages) ? $pages : array();
    }

    /** Hide tools the current delegated account cannot actually use. */
    private static function module_visible($module)
    {
        if (current_user_can('manage_options')) { return true; }
        if (!DSE_Modules::enabled($module)) { return false; }
        $definitions = DSE_Modules::definitions();
        if (!isset($definitions[$module]['actions'])) { return false; }
        foreach (array_keys($definitions[$module]['actions']) as $action) {
            if (DSE_Modules::allowed($module, $action)) { return true; }
        }
        return false;
    }

    /** Simple navigation: each tab is its own URL, so it also works without JavaScript. */
    private static function active_tab()
    {
        $raw = isset($_GET['dse_tab']) && is_string($_GET['dse_tab']) ?
            sanitize_key(wp_unslash($_GET['dse_tab'])) : '';
        if ($raw === '') {
            // Preserve the right tab after actions performed by older plugin versions.
            if (isset($_GET['dse_nnotice'])) { $raw = 'newsletters'; }
            elseif (isset($_GET['dse_pnotice'])) { $raw = 'boutique'; }
            elseif (isset($_GET['dse_notice']) && is_string($_GET['dse_notice']) &&
                in_array(sanitize_key(wp_unslash($_GET['dse_notice'])),
                    array('configured', 'field_added', 'media_configured', 'module_configured'), true)) {
                $raw = 'reglages';
            } else { $raw = isset($_GET['dse_notice']) ? 'pages' : 'accueil'; }
        }
        if (!in_array($raw, array('accueil', 'contenus', 'pages', 'articles', 'evenements', 'menu', 'newsletters', 'boutique', 'historique', 'reglages', 'aide'), true)) {
            return 'pages';
        }
        if ($raw === 'contenus' && !current_user_can('manage_options')) { return 'pages'; }
        if ($raw === 'menu' && !DSE_Navigation::allowed()) { return 'pages'; }
        if ($raw === 'reglages' && !current_user_can('manage_options')) { return 'pages'; }
        if ($raw === 'newsletters' && !self::module_visible('newsletters')) {
            return 'pages';
        }
        if ($raw === 'boutique' && !self::module_visible('products')) {
            return 'pages';
        }
        return $raw;
    }

    private static function tab_link($tab)
    {
        return add_query_arg('dse_tab', $tab, admin_url('admin.php?page=dendrila-simple-edit'));
    }

    public static function render()
    {
        if (!self::can_access()) {
            wp_die('Accès refusé.', 'Accès refusé', array('response' => 403));
        }
        $definitions = self::definitions();
        $enabled = self::enabled_fields();
        $state = self::state();
        $is_admin = current_user_can('manage_options');
        $tab = self::active_tab();
        $tabs = array(
            'accueil' => array('label' => 'Accueil', 'icon' => 'dashboard',
                'title' => 'Que voulez-vous faire ?', 'intro' => ''),
            'pages' => array('label' => 'Pages', 'icon' => 'admin-page',
                'title' => 'Modifier une page',
                'intro' => 'Choisissez la page à modifier et personnalisez son titre, ses textes et ses images.'),
            'articles' => array('label' => 'Articles', 'icon' => 'admin-post',
                'title' => 'Modifier un article',
                'intro' => 'Choisissez un article autorisé et modifiez son titre et son contenu.'),
            'menu' => array('label' => 'Menu principal', 'icon' => 'menu',
                'title' => 'Réorganiser le menu principal',
                'intro' => 'Changez l’ordre des liens, sans toucher à leur destination.'),
            'evenements' => array('label' => 'Événements', 'icon' => 'calendar-alt',
                'title' => 'Modifier un événement',
                'intro' => 'Modifiez le titre, la description, les dates, les horaires et le lieu des événements autorisés.'),
            'newsletters' => array('label' => 'Newsletters', 'icon' => 'email-alt',
                'title' => 'Préparer une newsletter',
                'intro' => 'Préparez vos messages. Seule l’administration peut choisir les abonnés et lancer les envois.'),
            'boutique' => array('label' => 'Boutique', 'icon' => 'cart',
                'title' => 'Modifier les produits',
                'intro' => 'Mettez à jour uniquement les informations de produits qui vous ont été confiées.'),
            'historique' => array('label' => 'Historique', 'icon' => 'backup',
                'title' => 'Retrouver une ancienne version',
                'intro' => 'Consultez les changements précédents et les pages qui utilisent vos textes.'),
            'reglages' => array('label' => 'Autorisations', 'icon' => 'admin-users',
                'title' => 'Choisir ce que chacun peut modifier',
                'intro' => 'Choisissez les outils et les images accessibles aux comptes ayant le rôle « Édition simple ».'),
            'aide' => array('label' => 'Aide', 'icon' => 'editor-help',
                'title' => 'Comprendre comment ça marche',
                'intro' => 'Quelques repères pour modifier le site sans risque.'),
            // A legacy admin-only screen keeps older shared texts and ACF forms
            // functional without showing a redundant editor in the main navigation.
            'contenus' => array('label' => 'Textes partagés', 'icon' => 'edit-page',
                'title' => 'Anciens textes et informations complémentaires',
                'intro' => 'Réglages avancés des anciens textes partagés et champs ACF.'),
        );
        if (!$is_admin) { unset($tabs['reglages'], $tabs['contenus']); }
        if (!DSE_Navigation::allowed()) { unset($tabs['menu']); }
        if (!self::module_visible('newsletters')) { unset($tabs['newsletters']); }
        if (!self::module_visible('products')) { unset($tabs['boutique']); }

        echo '<div class="wrap dse-wrap"><header class="dse-intro">';
        echo '<div class="dse-brand"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> Dendrila Simple Edit <span class="dse-version">' . esc_html(DSE_VERSION) . '</span></div>';
        echo '<h1>Modifier mon site</h1></header>';
        self::notices();
        echo '<nav class="dse-nav-tabs" aria-label="Rubriques de Modifier mon site">';
        foreach ($tabs as $key => $item) {
            if ($key === 'contenus') { continue; }
            echo '<a class="dse-tab' . ($tab === $key ? ' is-active' : '') . '" href="' .
                esc_url(self::tab_link($key)) . '"' . ($tab === $key ? ' aria-current="page"' : '') . '>' .
                '<span class="dashicons dashicons-' . esc_attr($item['icon']) . '" aria-hidden="true"></span>' .
                '<span>' . esc_html($item['label']) . '</span></a>';
        }
        echo '</nav><main id="dse-tab-content" class="dse-tab-content" tabindex="-1">';
        echo '<header class="dse-tab-heading"><h2>' . esc_html($tabs[$tab]['title']) . '</h2>';
        if ($tabs[$tab]['intro'] !== '') { echo '<p>' . esc_html($tabs[$tab]['intro']) . '</p>'; }
        echo '</header>';
        echo '<div class="dse-panel-grid dse-panel-' . esc_attr($tab) . '">';
        if ($tab === 'accueil') {
            $actions = array(
                array('pages', 'Modifier une page', 'Changer le titre et le contenu d’une page.'),
                array('articles', 'Modifier un article', 'Changer le titre et le contenu d’un article.'),
                array('evenements', 'Modifier un événement', 'Mettre à jour le titre, les horaires, le lieu et la description.'),
                array('menu', 'Réorganiser le menu', 'Changer la position des liens du menu principal.'),
                array('newsletters', 'Préparer une newsletter', 'Rédiger un message ou reprendre un brouillon.'),
                array('boutique', 'Modifier un produit', 'Mettre à jour les produits de la boutique.'),
                array('historique', 'Historique', 'Retrouver une version précédente des textes du site.'),
                array('reglages', 'Gérer les autorisations', 'Choisir ce que l’équipe peut modifier.'),
            );
            echo '<ul class="dse-dashboard-actions">';
            foreach ($actions as list($destination, $label, $description)) {
                if (!isset($tabs[$destination])) { continue; }
                echo '<li><a href="' . esc_url(self::tab_link($destination)) . '">' .
                    '<span class="dashicons dashicons-' . esc_attr($tabs[$destination]['icon']) . '" aria-hidden="true"></span>' .
                    '<span><strong>' . esc_html($label) . '</strong><span class="dse-action-description">' .
                    esc_html($description) . '</span></span></a></li>';
            }
            echo '</ul>';
        } elseif ($tab === 'pages') {
            DSE_Post_Editor::render('page');
        } elseif ($tab === 'articles') {
            DSE_Post_Editor::render('post');
        } elseif ($tab === 'evenements') {
            DSE_Event_Editor::render();
        } elseif ($tab === 'menu') {
            DSE_Navigation::render();
        } elseif ($tab === 'contenus' && $is_admin) {
            DSE_ACF::render();
        if (!$enabled) {
            echo '<div class="dse-card dse-empty"><h2>Aucun texte général à modifier</h2><p>Les textes supplémentaires, s’ils sont activés, apparaissent plus bas. Pour modifier les coordonnées ou la présentation du site, l’administrateur doit d’abord les autoriser.</p>';
            if ($is_admin) { echo '<a class="button button-primary" href="' . esc_url(self::tab_link('reglages')) . '">Choisir les textes à modifier</a>'; }
            echo '</div>';
        } elseif (self::can_edit()) {
            echo '<form class="dse-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dse_save">';
            echo '<input type="hidden" name="dse_revision" value="' . esc_attr((string) $state['revision']) . '">';
            wp_nonce_field('dse_save');
            echo '<h2>Coordonnées et présentation</h2><p>Modifiez ici uniquement les informations affichées sur votre site.</p>';
            foreach ($enabled as $key) {
                $definition = $definitions[$key];
                $value = isset($state['values'][$key]) && is_string($state['values'][$key]) ?
                    $state['values'][$key] : '';
                $id = 'dse-field-' . $key;
                echo '<div class="dse-field"><label for="' . esc_attr($id) . '">' .
                    esc_html($definition['label']) . '</label>';
                echo '<p class="description" id="' . esc_attr($id . '-help') . '">' .
                    esc_html($definition['help']) . '</p>';
                if ($definition['type'] === 'image') {
                    DSE_Media::render_picker($key, $value, $id);
                } elseif ($definition['type'] === 'textarea') {
                    echo '<textarea id="' . esc_attr($id) . '" name="dse_fields[' . esc_attr($key) .
                        ']" rows="4" maxlength="' . esc_attr((string) $definition['max']) .
                        '" aria-describedby="' . esc_attr($id . '-help') . '">' . esc_textarea($value) . '</textarea>';
                } else {
                    echo '<input id="' . esc_attr($id) . '" name="dse_fields[' . esc_attr($key) .
                        ']" type="' . esc_attr($definition['type']) .
                        '" maxlength="' . esc_attr((string) $definition['max']) .
                        '" aria-describedby="' . esc_attr($id . '-help') . '" value="' .
                        esc_attr($value) . '">';
                }
                echo '</div>';
            }
            echo '<p><button type="submit" class="button button-primary button-hero">Enregistrer mes modifications</button></p></form>';
        } else {
            echo '<div class="dse-card"><h2>Consultation uniquement</h2><p>Votre compte ne peut pas publier de changements.</p></div>';
        }


            DSE_Media::render_uploads();
        } elseif ($tab === 'newsletters') {
            if (!DSE_Modules::enabled('newsletters')) {
                echo '<section class="dse-card dse-empty"><h3>Les newsletters ne sont pas encore activées</h3><p>Depuis Autorisations, l’administrateur peut choisir qui peut préparer, modifier ou valider les brouillons.</p>';
                if ($is_admin) { echo '<p><a class="button button-primary" href="' . esc_url(self::tab_link('reglages')) . '">Gérer les accès</a></p>'; }
                echo '</section>';
            } else {
                DSE_Newsletters::render();
            }
        } elseif ($tab === 'boutique') {
            if (!DSE_Modules::enabled('products')) {
                echo '<section class="dse-card dse-empty"><h3>La boutique n’est pas encore activée ici</h3><p>Depuis Autorisations, l’administrateur peut donner accès à la gestion des produits.</p>';
                if ($is_admin) { echo '<p><a class="button button-primary" href="' . esc_url(self::tab_link('reglages')) . '">Gérer les accès</a></p>'; }
                echo '</section>';
            } elseif (!class_exists('WooCommerce')) {
                echo '<section class="dse-card dse-empty"><h3>WooCommerce n’est pas installé</h3><p>Cette rubrique sera utilisable lorsque la boutique WordPress sera activée.</p></section>';
            } else { DSE_Woo::render(); }
        } elseif ($tab === 'historique') {
        echo '<section class="dse-card" aria-labelledby="dse-history-heading"><h2 id="dse-history-heading">Modifications précédentes</h2>';
        echo '<p>Vous pouvez retrouver une ancienne version de vos textes. Les autres contenus du site ne sont pas touchés.</p>';
        $history = array_slice($state['history'], 0, 15);
        if (!$history || !$enabled) {
            echo '<p>Aucune modification précédente à afficher.</p>';
        } else {
            echo '<ul class="dse-history">';
            foreach ($history as $entry) {
                if (!is_array($entry) || !isset($entry['revision'], $entry['timestamp']) ||
                    !is_scalar($entry['revision']) || !is_string($entry['timestamp'])) {
                    continue;
                }
                $affected = array();
                foreach (isset($entry['changed']) && is_array($entry['changed']) ? $entry['changed'] : array() as $key) {
                    if (in_array($key, $enabled, true)) {
                        $affected[] = $definitions[$key]['label'];
                    }
                }
                echo '<li><div><strong>Version ' . esc_html((string) $entry['revision']) .
                    '</strong><p>' . esc_html($entry['timestamp']) . ' UTC';
                if ($affected) {
                    echo ' — ' . esc_html(implode(', ', $affected));
                }
                echo '</p></div>';
                if (self::can_edit()) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="dse_restore">';
                    echo '<input type="hidden" name="dse_revision" value="' . esc_attr((string) $state['revision']) . '">';
                    echo '<input type="hidden" name="dse_restore_revision" value="' .
                        esc_attr((string) $entry['revision']) . '">';
                    wp_nonce_field('dse_restore');
                    echo '<button class="button" type="submit">Restaurer cette version</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
        echo '<section class="dse-card"><h2>Où ces textes sont-ils affichés ?</h2>';
        echo '<p>Une modification ne se voit que là où ce texte est déjà utilisé sur le site.</p>';
        if ($enabled) {
            echo '<ul>';
            foreach ($enabled as $key) {
                echo '<li><strong>' . esc_html($definitions[$key]['label']) . '</strong>';
                $locations = self::public_locations($key);
                if ($locations) {
                    echo '<span class="dse-usage-label">Pages sur lesquelles ce texte apparaît :</span><ul class="dse-usage">';
                    foreach ($locations as $location) {
                        $url = get_permalink((int) $location->ID);
                        if (!$url) {
                            continue;
                        }
                        echo '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' .
                            esc_html($location->post_title ? $location->post_title : '(Sans titre)') .
                            ' <span class="screen-reader-text">(nouvel onglet)</span></a></li>';
                    }
                    echo '</ul>';
                }
                echo '</li>';
            }
            echo '</ul>';
            echo '<p class="description">Cette liste peut être incomplète : certains contenus sont gérés directement par le thème ou un constructeur de pages.</p>';
        }
        echo '</section>';
        } elseif ($tab === 'reglages' && $is_admin) {
        if (current_user_can('manage_options')) {
            echo '<section class="dse-card"><h2>Qui peut modifier le site ?</h2>';
            echo '<p>Attribuez le rôle <strong>Édition simple</strong> aux comptes de votre choix. ' .
                'Ces personnes verront uniquement cette interface dans leur administration WordPress.</p>';
            echo '<p><a class="button button-secondary" href="' .
                esc_url(admin_url('users.php')) . '">Gérer les utilisateurs</a></p></section>';
            echo '<form class="dse-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<h2>Choisir les informations modifiables</h2><p>Cochez ce que les utilisateurs autorisés pourront modifier, sans leur ouvrir les autres réglages WordPress.</p>';
            echo '<input type="hidden" name="action" value="dse_configure">';
            wp_nonce_field('dse_configure');
            foreach ($definitions as $key => $definition) {
                echo '<label class="dse-check"><input type="checkbox" name="dse_enabled[]" value="' .
                    esc_attr($key) . '" ' . checked(in_array($key, $enabled, true), true, false) . '> ' .
                    esc_html($definition['label']) . '</label>';
            }
            echo '<p><button class="button button-secondary" type="submit">Enregistrer les accès aux textes</button></p>';
            echo '<p class="description">Pour qu’un texte apparaisse sur le site, son emplacement doit être prévu dans la page ou le modèle. Les codes techniques sont présentés dans l’onglet Aide.</p>';
            echo '</form>';
            $created = isset($_GET['dse_created']) && is_string($_GET['dse_created']) ?
                sanitize_key(wp_unslash($_GET['dse_created'])) : '';
            $shared = array_filter($definitions, static function ($key) {
                return strpos($key, 'zone_') === 0;
            }, ARRAY_FILTER_USE_KEY);
            echo '<details id="dse-shared-text" class="dse-card dse-advanced"' .
                (isset($shared[$created]) ? ' open' : '') . '><summary>Textes partagés entre plusieurs pages</summary>';
            echo '<p>Vous voulez afficher la même annonce sur plusieurs pages ? Créez un texte partagé. Son édition reste accessible depuis les réglages avancés.</p>';
            if (isset($shared[$created])) {
                echo '<p><strong>' . esc_html($shared[$created]['label']) . ' est prêt à être placé.</strong></p>';
            }
            if ($shared) {
                echo '<p>Dans chaque page concernée, ajoutez un bloc <strong>Code court</strong> et collez le code du texte choisi. Rédigez ensuite le texte dans <strong>Textes et images</strong>.</p>';
                foreach ($shared as $key => $definition) {
                    echo '<div class="dse-field"><label for="dse-shared-' . esc_attr($key) . '">' .
                        esc_html($definition['label']) . ' · code à copier</label><input readonly type="text" id="dse-shared-' .
                        esc_attr($key) . '" value="' . esc_attr('[dendrila_simple_edit field="' . $key . '"]') . '"></div>';
                }
                echo '<p><a href="' . esc_url(self::tab_link('contenus')) . '">Ouvrir les anciens textes</a></p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dse_add_field">';
            wp_nonce_field('dse_add_field');
            echo '<div class="dse-field"><label for="dse-label">Nom du texte</label>';
            echo '<input type="text" id="dse-label" name="dse_label" required maxlength="80" placeholder="Ex. Message de bienvenue"></div>';
            echo '<div class="dse-field"><label for="dse-type">Format</label>';
            echo '<select id="dse-type" name="dse_field_type">';
            echo '<option value="text">Texte court (titre)</option>';
            echo '<option value="textarea">Plusieurs lignes (paragraphe)</option>';
            echo '</select></div>';
            echo '<p><button class="button" type="submit">Créer le texte et obtenir son code</button></p>';
            echo '</form></details>';
        }
            DSE_ACF::render(true);
            DSE_Media::render_admin();
            DSE_Post_Editor::render_admin();
            DSE_Event_Editor::render_admin();
            echo '<details class="dse-card dse-advanced"><summary>Anciens textes et champs complémentaires</summary>';
            echo '<p>Ces réglages restent accessibles à l’administrateur pour les sites qui les utilisaient avant la séparation des rubriques.</p>';
            echo '<p><a class="button" href="' . esc_url(self::tab_link('contenus')) . '">Ouvrir les anciens textes</a></p></details>';
            DSE_Modules::render_admin();
        } elseif ($tab === 'aide') {
            echo '<section class="dse-card"><h3>Comment modifier un contenu ?</h3><ol class="dse-steps"><li>Ouvrez l’onglet Pages, Articles ou Événements.</li><li>Choisissez un contenu autorisé et modifiez son titre et les éléments nécessaires.</li><li>Enregistrez vos modifications.</li></ol><p>Si un texte n’apparaît pas, l’administrateur doit peut-être encore autoriser sa modification.</p></section>';
            echo '<section class="dse-card"><h3>Ce que Simple Edit ne modifie pas</h3><p>Les menus, les modèles et la disposition de la page restent tels quels. L’idée est de pouvoir changer le contenu sans risquer de casser le design.</p></section>';
            if ($is_admin) {
                echo '<details class="dse-card dse-advanced"><summary>Pour les administrateurs : placer un texte sur une page</summary><div class="dse-details-body"><p>Chaque texte peut être inséré à un endroit précis avec un petit code WordPress (appelé « code court »).</p>';
                foreach ($enabled as $key) {
                    echo '<p><strong>' . esc_html($definitions[$key]['label']) . '</strong><code>[dendrila_simple_edit field="' . esc_html($key) . '"]</code></p>';
                }
                DSE_Builders::render();
                echo '</div></details>';
            }
        }
        echo '</div></main></div>';
    }
}
