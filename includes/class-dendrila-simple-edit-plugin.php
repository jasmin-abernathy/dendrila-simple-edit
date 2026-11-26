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

final class Dendrila_Simple_Edit_Plugin
{
    const ROLE = 'dendrila_simple_editor';
    const LEGACY_ROLE = 'dendrila_site_manager';
    const ROLE_SCHEMA_OPTION = 'dendrila_simple_edit_simple_editor_role_v1';
    const STATE_OPTION = 'dendrila_simple_edit_state';
    const ENABLED_OPTION = 'dendrila_simple_edit_enabled_fields';
    const CUSTOM_OPTION = 'dendrila_simple_edit_custom_fields';

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
        add_option(Dendrila_Simple_Edit_Media::OPTION, array(), '', false);
        add_option(Dendrila_Simple_Edit_Media::ALL_OPTION, '0', '', false);
        Dendrila_Simple_Edit_Setup::on_activate();
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
        $caps = array('read' => true, 'dendrila_simple_edit_access' => true,
            'dendrila_simple_edit_edit' => true, 'dendrila_simple_edit_publish' => true, 'upload_files' => true);
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
        return Dendrila_Simple_Edit_Admin_Access::is_restricted_user();
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
            user_can($user, 'dendrila_simple_edit_access') && !user_can($user, 'manage_options');
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
        Dendrila_Simple_Edit_Admin_Access::limit_admin_menu();
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
        if (Dendrila_Simple_Edit_Admin_Access::allows_current_page()) { return; }
        wp_safe_redirect(admin_url('admin.php?page=dendrila-simple-edit'));
        exit;
    }

    public static function boot()
    {
        add_action('init', array(__CLASS__, 'upgrade_editor_role'), 5);
        Dendrila_Simple_Edit_Setup::boot();
        Dendrila_Simple_Edit_Admin_Access::boot();
        add_filter('woocommerce_prevent_admin_access',
            array(__CLASS__, 'allow_delegated_admin_access'), 999);
        add_filter('login_redirect', array(__CLASS__, 'redirect_wp_login'), 999, 3);
        add_filter('woocommerce_login_redirect',
            array(__CLASS__, 'redirect_woocommerce_login'), 999, 2);
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_menu', array(__CLASS__, 'limit_admin_menu'), PHP_INT_MAX);
        // The final menu hook catches menu items added by other extensions later.
        add_action('adminmenu', array(__CLASS__, 'limit_admin_menu'), 999);
        add_action('admin_init', array(__CLASS__, 'restrict_admin_pages'), PHP_INT_MAX);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_post_dendrila_simple_edit_save', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_restore', array(__CLASS__, 'restore'));
        add_action('admin_post_dendrila_simple_edit_configure', array(__CLASS__, 'configure'));
        add_action('admin_post_dendrila_simple_edit_add_field', array(__CLASS__, 'add_field'));
        add_shortcode('dse_text', array(__CLASS__, 'shortcode'));
        Dendrila_Simple_Edit_Approvals::boot();
        Dendrila_Simple_Edit_ACF::boot();
        Dendrila_Simple_Edit_Media::boot();
        Dendrila_Simple_Edit_Modules::boot();
        Dendrila_Simple_Edit_Newsletters::boot();
        Dendrila_Simple_Edit_Post_Editor::boot();
        Dendrila_Simple_Edit_Navigation::boot();
        Dendrila_Simple_Edit_Event_Editor::boot();
        Dendrila_Simple_Edit_Mailing::boot();
        Dendrila_Simple_Edit_Woo::boot();
        Dendrila_Simple_Edit_Woo_Mailing::boot();
    }

    public static function can_access()
    {
        return Dendrila_Simple_Edit_Setup::can_configure() || current_user_can('manage_options') || current_user_can('dendrila_simple_edit_access') ||
            Dendrila_Simple_Edit_Modules::has_any_grant() || Dendrila_Simple_Edit_Approvals::is_reviewer();
    }

    public static function can_edit()
    {
        return current_user_can('manage_options') ||
            (current_user_can('dendrila_simple_edit_access') && current_user_can('dendrila_simple_edit_edit') && current_user_can('dendrila_simple_edit_publish'));
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
            __('Modifier mon site', 'dendrila-simple-edit'),
            __('Modifier mon site', 'dendrila-simple-edit'),
            'read',
            'dendrila-simple-edit',
            array(__CLASS__, 'render'),
            'dashicons-edit-page',
            26
        );
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_dendrila-simple-edit' || !self::can_access()) {
            return;
        }
        wp_enqueue_style(
            'dendrila-simple-edit-admin',
            plugins_url('assets/admin.css', DENDRILA_SIMPLE_EDIT_PLUGIN_FILE),
            array(),
            DENDRILA_SIMPLE_EDIT_VERSION . "." . filemtime(dirname(DENDRILA_SIMPLE_EDIT_PLUGIN_FILE) . "/assets/admin.css")
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
            wp_enqueue_script('dendrila-simple-edit-block-editor', plugins_url('assets/block-editor.js', DENDRILA_SIMPLE_EDIT_PLUGIN_FILE),
                array('media-views'), DENDRILA_SIMPLE_EDIT_VERSION . '.' . filemtime(dirname(DENDRILA_SIMPLE_EDIT_PLUGIN_FILE) . '/assets/block-editor.js'), true);
        }
        wp_enqueue_script(
            'dendrila-simple-edit-admin',
            plugins_url('assets/admin.js', DENDRILA_SIMPLE_EDIT_PLUGIN_FILE),
            $script_dependencies,
            DENDRILA_SIMPLE_EDIT_VERSION . "." . filemtime(dirname(DENDRILA_SIMPLE_EDIT_PLUGIN_FILE) . "/assets/admin.js"),
            true
        );
        $dendrila_simple_edit_js_strings = array(
            'Replier : ' => __('Replier : ', 'dendrila-simple-edit'),
            'Descendre : ' => __('Descendre : ', 'dendrila-simple-edit'),
            'Monter : ' => __('Monter : ', 'dendrila-simple-edit'),
            'Adresse du lien' => __('Adresse du lien', 'dendrila-simple-edit'),
            'Contenu' => __('Contenu', 'dendrila-simple-edit'),
            'Retirer' => __('Retirer', 'dendrila-simple-edit'),
            ' de la sélection' => __(' de la sélection', 'dendrila-simple-edit'),
            ' image sélectionnée' => __(' image sélectionnée', 'dendrila-simple-edit'),
            ' images sélectionnées' => __(' images sélectionnées', 'dendrila-simple-edit'),
            '. Pensez à enregistrer.' => __('. Pensez à enregistrer.', 'dendrila-simple-edit'),
            'Actions du formulaire (également disponibles en bas)' => __('Actions du formulaire (également disponibles en bas)', 'dendrila-simple-edit'),
            'Adresse de l’image' => __('Adresse de l’image', 'dendrila-simple-edit'),
            'Ajouter des images' => __('Ajouter des images', 'dendrila-simple-edit'),
            'Ajouter un élément' => __('Ajouter un élément', 'dendrila-simple-edit'),
            'Alignement de cet élément' => __('Alignement de cet élément', 'dendrila-simple-edit'),
            'Aperçu de cet élément' => __('Aperçu de cet élément', 'dendrila-simple-edit'),
            'Aperçu du cadre de l’image' => __('Aperçu du cadre de l’image', 'dendrila-simple-edit'),
            'Aperçu du contenu' => __('Aperçu du contenu', 'dendrila-simple-edit'),
            'Ardoise' => __('Ardoise', 'dendrila-simple-edit'),
            'Article ajouté. Vous pouvez maintenant modifier chaque élément dans la newsletter, sans changer l’article original.' => __('Article ajouté. Vous pouvez maintenant modifier chaque élément dans la newsletter, sans changer l’article original.', 'dendrila-simple-edit'),
            'Article inséré dans la newsletter.' => __('Article inséré dans la newsletter.', 'dendrila-simple-edit'),
            'Aucun article publié avec un paragraphe n’est disponible pour le moment.' => __('Aucun article publié avec un paragraphe n’est disponible pour le moment.', 'dendrila-simple-edit'),
            'Automatique' => __('Automatique', 'dendrila-simple-edit'),
            'Bleu' => __('Bleu', 'dendrila-simple-edit'),
            'Bouton' => __('Bouton', 'dendrila-simple-edit'),
            'Brique' => __('Brique', 'dendrila-simple-edit'),
            'Centré' => __('Centré', 'dendrila-simple-edit'),
            'Choisir dans la médiathèque' => __('Choisir dans la médiathèque', 'dendrila-simple-edit'),
            'Choisir les images autorisées' => __('Choisir les images autorisées', 'dendrila-simple-edit'),
            'Choisir ou importer une image' => __('Choisir ou importer une image', 'dendrila-simple-edit'),
            'Choisir une couleur personnalisée' => __('Choisir une couleur personnalisée', 'dendrila-simple-edit'),
            'Choisir une image' => __('Choisir une image', 'dendrila-simple-edit'),
            'Choisissez un élément ci-dessus pour commencer.' => __('Choisissez un élément ci-dessus pour commencer.', 'dendrila-simple-edit'),
            'Copie impossible : sélectionnez le code' => __('Copie impossible : sélectionnez le code', 'dendrila-simple-edit'),
            'Copié !' => __('Copié !', 'dendrila-simple-edit'),
            'Couleur automatique' => __('Couleur automatique', 'dendrila-simple-edit'),
            'Couleur du cadre' => __('Couleur du cadre', 'dendrila-simple-edit'),
            'Couleur du texte ou du bouton' => __('Couleur du texte ou du bouton', 'dendrila-simple-edit'),
            'Description de l’image' => __('Description de l’image', 'dendrila-simple-edit'),
            'Déplacement annulé.' => __('Déplacement annulé.', 'dendrila-simple-edit'),
            'Déplier' => __('Déplier', 'dendrila-simple-edit'),
            'Déplier le bloc' => __('Déplier le bloc', 'dendrila-simple-edit'),
            'Grand titre' => __('Grand titre', 'dendrila-simple-edit'),
            'Image' => __('Image', 'dendrila-simple-edit'),
            'Image ' => __('Image ', 'dendrila-simple-edit'),
            'Image choisie' => __('Image choisie', 'dendrila-simple-edit'),
            'Image sélectionnée' => __('Image sélectionnée', 'dendrila-simple-edit'),
            'Intertitre' => __('Intertitre', 'dendrila-simple-edit'),
            'Le titre qui ouvre le message' => __('Le titre qui ouvre le message', 'dendrila-simple-edit'),
            'Les images importées sont disponibles. Rechargez la page pour voir les nouvelles miniatures.' => __('Les images importées sont disponibles. Rechargez la page pour voir les nouvelles miniatures.', 'dendrila-simple-edit'),
            'Lien' => __('Lien', 'dendrila-simple-edit'),
            'L’insertion n’a pas pu être terminée. Vérifiez le contenu.' => __('L’insertion n’a pas pu être terminée. Vérifiez le contenu.', 'dendrila-simple-edit'),
            'Ordre mis à jour.' => __('Ordre mis à jour.', 'dendrila-simple-edit'),
            'Personnalisée :' => __('Personnalisée :', 'dendrila-simple-edit'),
            'Photo ou illustration' => __('Photo ou illustration', 'dendrila-simple-edit'),
            'Pour séparer deux parties' => __('Pour séparer deux parties', 'dendrila-simple-edit'),
            'Prune' => __('Prune', 'dendrila-simple-edit'),
            'Replier' => __('Replier', 'dendrila-simple-edit'),
            'Retirer ' => __('Retirer ', 'dendrila-simple-edit'),
            'Texte' => __('Texte', 'dendrila-simple-edit'),
            'Titre de section' => __('Titre de section', 'dendrila-simple-edit'),
            'Titre principal' => __('Titre principal', 'dendrila-simple-edit'),
            'Un lien bien visible' => __('Un lien bien visible', 'dendrila-simple-edit'),
            'Un lien dans le texte' => __('Un lien dans le texte', 'dendrila-simple-edit'),
            'Un ou plusieurs paragraphes' => __('Un ou plusieurs paragraphes', 'dendrila-simple-edit'),
            'Utiliser ces images' => __('Utiliser ces images', 'dendrila-simple-edit'),
            'Utiliser cette image' => __('Utiliser cette image', 'dendrila-simple-edit'),
            'Vert' => __('Vert', 'dendrila-simple-edit'),
            'Vous pouvez choisir 60 images maximum. Réduisez votre sélection.' => __('Vous pouvez choisir 60 images maximum. Réduisez votre sélection.', 'dendrila-simple-edit'),
            'Vérifiez la liste : numéros valides, 60 images maximum.' => __('Vérifiez la liste : numéros valides, 60 images maximum.', 'dendrila-simple-edit'),
            'À droite' => __('À droite', 'dendrila-simple-edit'),
            'À gauche' => __('À gauche', 'dendrila-simple-edit'),
            'Élément' => __('Élément', 'dendrila-simple-edit'),
            'Sous-menu créé. Pensez à enregistrer.' => __('Sous-menu créé. Pensez à enregistrer.', 'dendrila-simple-edit'),
            'Lien replacé au premier niveau. Pensez à enregistrer.' => __('Lien replacé au premier niveau. Pensez à enregistrer.', 'dendrila-simple-edit'),
            'Choisissez un parent du premier niveau, sans créer de troisième niveau.' => __('Choisissez un parent du premier niveau, sans créer de troisième niveau.', 'dendrila-simple-edit'),
            'Déplacez ce lien. Déposez sur « Sous-menu » pour grouper.' => __('Déplacez ce lien. Déposez sur « Sous-menu » pour grouper.', 'dendrila-simple-edit'),
            'Élément descendu.' => __('Élément descendu.', 'dendrila-simple-edit'),
            'Élément remonté.' => __('Élément remonté.', 'dendrila-simple-edit'),
            'Élément retiré.' => __('Élément retiré.', 'dendrila-simple-edit'),
        );
        wp_localize_script('dendrila-simple-edit-admin', 'dendrilaSimpleEditStrings',
            $dendrila_simple_edit_js_strings);
        if (wp_script_is('dendrila-simple-edit-block-editor', 'enqueued')) {
            wp_localize_script('dendrila-simple-edit-block-editor',
                'dendrilaSimpleEditStrings', $dendrila_simple_edit_js_strings);
        }
    }

    public static function sanitize_field($key, $value)
    {
        $definitions = self::definitions();
        if (!isset($definitions[$key]) || !is_string($value)) {
            throw new InvalidArgumentException('Champ inconnu ou valeur invalide.');
        }
        // The save() boundary already unslashed the submitted values.
        if ($definitions[$key]['type'] === 'image') {
            return Dendrila_Simple_Edit_Media::sanitize_selection($value);
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

    private static function submitted_revision($nonce_action)
    {
        check_admin_referer($nonce_action);
        $raw = isset($_POST['dendrila_simple_edit_revision']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_revision'])) : null;
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
                    if (($current_image !== '' && !Dendrila_Simple_Edit_Media::allowed_image((int) $current_image)) ||
                        ($previous !== '' && !Dendrila_Simple_Edit_Media::allowed_image((int) $previous))) {
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
                !Dendrila_Simple_Edit_Media::allowed_image((int) $changes['photo'])) {
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
            do_action('dendrila_simple_edit_fields_updated', $changed_keys, $new['revision']);
        }
        return $result;
    }

    public static function save()
    {
        self::require_editor('dendrila_simple_edit_save');
        $expected = self::submitted_revision('dendrila_simple_edit_save');
        $input = isset($_POST['dendrila_simple_edit_fields']) && is_array($_POST['dendrila_simple_edit_fields']) ?
            wp_unslash($_POST['dendrila_simple_edit_fields']) : array();
        // validate_changes() only permits configured keys and sanitizes every value
        // by its declared type. Sanitizing the whole payload here would erase
        // intentional textarea formatting before field-specific validation.
        try {
            $result = self::commit_change($expected, $input);
        } catch (InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), 'Champ non autorisé', array('response' => 400));
        }
        self::redirect($result);
    }

    public static function restore()
    {
        self::require_editor('dendrila_simple_edit_restore');
        $expected = self::submitted_revision('dendrila_simple_edit_restore');
        $raw = isset($_POST['dendrila_simple_edit_restore_revision']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_restore_revision'])) : null;
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
        check_admin_referer('dendrila_simple_edit_configure');
        $input = isset($_POST['dendrila_simple_edit_enabled']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_enabled']), 'sanitize_text_field') : array();
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
        check_admin_referer('dendrila_simple_edit_add_field');
        $label_input = isset($_POST['dendrila_simple_edit_label']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_label'])) : null;
        $type_input = isset($_POST['dendrila_simple_edit_field_type']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_field_type'])) : null;
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
        wp_safe_redirect(add_query_arg(array('dendrila_simple_edit_notice' => 'field_added', 'dendrila_simple_edit_created' => $slug),
            self::tab_link('reglages')) . '#dendrila-simple-edit-shared-text');
        exit;
    }

    public static function shortcode($atts)
    {
        $attributes = shortcode_atts(array('field' => ''), $atts, 'dse_text');
        $key = $attributes['field'];
        if (!is_string($key) || !array_key_exists($key, self::definitions())) {
            return '';
        }
        $state = self::state();
        $value = isset($state['values'][$key]) && is_string($state['values'][$key]) ?
            $state['values'][$key] : '';
        if ($key === 'photo') {
            return Dendrila_Simple_Edit_Media::render_image($value);
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
            'dendrila_simple_edit_notice' => $notice,
            'dendrila_simple_edit_tab' => in_array($notice, array('configured', 'field_added'), true) ? 'reglages' :
                ($notice === 'restored' ? 'historique' : 'contenus'),
        ), admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    private static function notices()
    {
        $notice = isset($_GET['dendrila_simple_edit_notice']) && is_string($_GET['dendrila_simple_edit_notice']) ?
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_notice'])) : '';
        $messages = array(
            'saved' => array('updated', 'Modifications enregistrées. Vérifiez le site public : un cache peut retarder l’affichage.'),
            'unchanged' => array('updated', 'Aucune modification à enregistrer.'),
            'configured' => array('updated', 'Champs autorisés mis à jour.'),
            'field_added' => array('updated', 'Texte partagé créé. Suivez les étapes dans la rubrique « Textes partagés ».'),
            'acf_added' => array('updated', 'Champ complémentaire autorisé.'),
            'acf_removed' => array('updated', 'Autorisation retirée. La valeur publique a été conservée.'),
            'acf_duplicate' => array('updated', 'Ce champ est déjà autorisé.'),
            'acf_saved' => array('updated', 'Champ complémentaire mis à jour. Le cache public peut nécessiter une purge.'),
            'acf_unchanged' => array('updated', 'Ce champ est déjà à jour.'),
            'acf_conflict' => array('error', 'Ce champ a changé entre-temps. Rechargez la page avant de réessayer.'),
            'acf_error' => array('error', 'Champ non enregistré. Aucun succès ne peut être confirmé.'),
            'media_configured' => array('updated', 'La sélection des images autorisées a été mise à jour.'),
            'module_configured' => array('updated', 'Réglages du module enregistrés.'),
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
        // Advisory shortcode discovery can tolerate a short delay; unlike
        // membership/consent checks, these matches do not authorize any action.
        $cache_key = 'public_shortcode_locations_' . md5($field);
        $cached = function_exists('wp_cache_get') ?
            wp_cache_get($cache_key, 'dendrila_simple_edit') : false;
        if (is_array($cached)) { return $cached; }
        $needle = '%' . $wpdb->esc_like('[dse_text') . '%';
        $field_needle = '%' . $wpdb->esc_like('field="' . $field . '"') . '%';
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM %i
             WHERE post_status = 'publish'
               AND post_type IN ('page', 'post')
               AND post_content LIKE %s
               AND post_content LIKE %s
             ORDER BY post_title ASC
             LIMIT 30",
            $wpdb->posts, $needle, $field_needle
        ));
        if (is_array($pages) && function_exists('wp_cache_set')) {
            wp_cache_set($cache_key, $pages, 'dendrila_simple_edit', 30);
        }
        return is_array($pages) ? $pages : array();
    }

    /** Hide tools the current delegated account cannot actually use. */
    private static function module_visible($module)
    {
        if (current_user_can('manage_options')) { return true; }
        if ($module === 'newsletters' && Dendrila_Simple_Edit_Approvals::can_approve('newsletter')) { return true; }
        if (!Dendrila_Simple_Edit_Modules::enabled($module)) { return false; }
        $definitions = Dendrila_Simple_Edit_Modules::definitions();
        if (!isset($definitions[$module]['actions'])) { return false; }
        foreach (array_keys($definitions[$module]['actions']) as $action) {
            if (Dendrila_Simple_Edit_Modules::allowed($module, $action)) { return true; }
        }
        return false;
    }

    /** Simple navigation: each tab is its own URL, so it also works without JavaScript. */
    private static function active_tab()
    {
        $raw = isset($_GET['dendrila_simple_edit_tab']) && is_string($_GET['dendrila_simple_edit_tab']) ?
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_tab'])) : '';
        if ($raw === '') {
            // Preserve the right tab after actions performed by older plugin versions.
            if (isset($_GET['dendrila_simple_edit_nnotice'])) { $raw = 'newsletters'; }
            elseif (isset($_GET['dendrila_simple_edit_pnotice'])) { $raw = 'boutique'; }
            elseif (isset($_GET['dendrila_simple_edit_notice']) && is_string($_GET['dendrila_simple_edit_notice']) &&
                in_array(sanitize_key(wp_unslash($_GET['dendrila_simple_edit_notice'])),
                    array('configured', 'field_added', 'media_configured', 'module_configured'), true)) {
                $raw = 'reglages';
            } else { $raw = isset($_GET['dendrila_simple_edit_notice']) ? 'pages' : 'accueil'; }
        }
        if (!in_array($raw, array('accueil', 'contenus', 'pages', 'articles', 'evenements', 'menu', 'newsletters', 'boutique', 'historique', 'reglages', 'validations', 'aide'), true)) {
            return 'pages';
        }
        if ($raw === 'contenus' && !current_user_can('manage_options')) { return 'pages'; }
        if ($raw === 'menu' && !Dendrila_Simple_Edit_Navigation::allowed()) { return 'pages'; }
        if ($raw === 'reglages' && !current_user_can('manage_options')) { return 'pages'; }
        if ($raw === 'validations' && !Dendrila_Simple_Edit_Approvals::is_reviewer()) { return 'accueil'; }
        if ($raw === 'newsletters' && !self::module_visible('newsletters')) {
            return 'pages';
        }
        if ($raw === 'boutique' && !self::module_visible('products')) {
            return 'pages';
        }
        if (!self::can_edit() && !Dendrila_Simple_Edit_Modules::has_any_grant(null, 'editorial') &&
            Dendrila_Simple_Edit_Approvals::is_reviewer() &&
            !in_array($raw, array('accueil', 'validations', 'newsletters', 'aide'), true)) { return 'validations'; }
        return $raw;
    }

    private static function tab_link($tab)
    {
        return add_query_arg('dendrila_simple_edit_tab', $tab, admin_url('admin.php?page=dendrila-simple-edit'));
    }

    public static function render()
    {
        if (!self::can_access()) {
            wp_die('Accès refusé.', 'Accès refusé', array('response' => 403));
        }
        if (Dendrila_Simple_Edit_Setup::needs_wizard()) {
            Dendrila_Simple_Edit_Setup::render();
            return;
        }
        $definitions = self::definitions();
        $enabled = self::enabled_fields();
        $state = self::state();
        $is_admin = current_user_can('manage_options');
        $tab = self::active_tab();
        $tabs = array(
            'accueil' => array('label' => __('Accueil', 'dendrila-simple-edit'), 'icon' => 'dashboard',
                'title' => __('Que voulez-vous faire ?', 'dendrila-simple-edit'), 'intro' => ''),
            'pages' => array('label' => __('Pages', 'dendrila-simple-edit'), 'icon' => 'admin-page',
                'title' => __('Modifier une page', 'dendrila-simple-edit'),
                'intro' => __('Choisissez la page à modifier et personnalisez son titre, ses textes et ses images.', 'dendrila-simple-edit')),
            'articles' => array('label' => __('Articles', 'dendrila-simple-edit'), 'icon' => 'admin-post',
                'title' => __('Modifier un article', 'dendrila-simple-edit'),
                'intro' => __('Choisissez un article autorisé et modifiez son titre et son contenu.', 'dendrila-simple-edit')),
            'menu' => array('label' => __('Menu principal', 'dendrila-simple-edit'), 'icon' => 'menu',
                'title' => __('Réorganiser le menu principal', 'dendrila-simple-edit'),
                'intro' => __('Changez l’ordre des liens, sans toucher à leur destination.', 'dendrila-simple-edit')),
            'evenements' => array('label' => __('Événements', 'dendrila-simple-edit'), 'icon' => 'calendar-alt',
                'title' => __('Modifier un événement', 'dendrila-simple-edit'),
                'intro' => __('Modifiez le titre, la description, les dates, les horaires et le lieu des événements autorisés.', 'dendrila-simple-edit')),
            'newsletters' => array('label' => __('Newsletters', 'dendrila-simple-edit'), 'icon' => 'email-alt',
                'title' => __('Préparer une newsletter', 'dendrila-simple-edit'),
                'intro' => __('Préparez vos messages et, si vous disposez du droit d’envoi, choisissez vos destinataires et lancez votre campagne.', 'dendrila-simple-edit')),
            'boutique' => array('label' => __('Boutique', 'dendrila-simple-edit'), 'icon' => 'cart',
                'title' => __('Modifier les produits', 'dendrila-simple-edit'),
                'intro' => __('Mettez à jour uniquement les informations de produits qui vous ont été confiées.', 'dendrila-simple-edit')),
            'historique' => array('label' => __('Historique', 'dendrila-simple-edit'), 'icon' => 'backup',
                'title' => __('Retrouver une ancienne version', 'dendrila-simple-edit'),
                'intro' => __('Consultez les changements précédents et les pages qui utilisent vos textes.', 'dendrila-simple-edit')),
            'reglages' => array('label' => __('Autorisations', 'dendrila-simple-edit'), 'icon' => 'admin-users',
                'title' => __('Choisir ce que chacun peut modifier', 'dendrila-simple-edit'),
                'intro' => __('Activez les outils, puis choisissez les droits des rôles et les types de contenus.', 'dendrila-simple-edit')),
            'validations' => array('label' => __('Validations', 'dendrila-simple-edit'), 'icon' => 'yes-alt',
                'title' => __('Contenus en attente de validation', 'dendrila-simple-edit'),
                'intro' => __('Vérifiez les propositions avant qu’elles ne soient mises en ligne.', 'dendrila-simple-edit')),
            'aide' => array('label' => __('Aide', 'dendrila-simple-edit'), 'icon' => 'editor-help',
                'title' => __('Comprendre comment ça marche', 'dendrila-simple-edit'),
                'intro' => __('Quelques repères pour modifier le site sans risque.', 'dendrila-simple-edit')),
            // A legacy admin-only screen keeps older shared texts and ACF forms
            // functional without showing a redundant editor in the main navigation.
            'contenus' => array('label' => 'Textes partagés', 'icon' => 'edit-page',
                'title' => 'Anciens textes et informations complémentaires',
                'intro' => 'Réglages avancés des textes partagés et champs complémentaires.'),
        );
        if (!$is_admin) {
            unset($tabs['reglages'], $tabs['contenus']);
            foreach (array('pages' => 'page', 'articles' => 'post') as $key => $type) {
                if (!Dendrila_Simple_Edit_Post_Editor::can_modify($type) && !Dendrila_Simple_Edit_Post_Editor::can_create_type($type)) {
                    unset($tabs[$key]);
                }
            }
            if (!Dendrila_Simple_Edit_Event_Editor::available() || !Dendrila_Simple_Edit_Event_Editor::can_modify_events()) {
                unset($tabs['evenements']);
            }
        }
        if (!Dendrila_Simple_Edit_Approvals::is_reviewer()) { unset($tabs['validations']); }
        if (!self::can_edit() && !Dendrila_Simple_Edit_Modules::has_any_grant(null, 'editorial') &&
            Dendrila_Simple_Edit_Approvals::is_reviewer()) {
            foreach (array('pages', 'articles', 'menu', 'evenements', 'historique') as $hidden) { unset($tabs[$hidden]); }
        }
        if (!Dendrila_Simple_Edit_Navigation::allowed()) { unset($tabs['menu']); }
        if (!self::module_visible('newsletters')) { unset($tabs['newsletters']); }
        if (!self::module_visible('products')) { unset($tabs['boutique']); }

        if (!isset($tabs[$tab])) { $tab = 'accueil'; }

        echo '<div class="wrap dendrila-simple-edit-wrap"><header class="dendrila-simple-edit-intro">';
        echo '<div class="dendrila-simple-edit-brand"><img src="' . esc_url(plugins_url('assets/icon-128x128.png', DENDRILA_SIMPLE_EDIT_PLUGIN_FILE)) . '" width="30" height="30" alt="" aria-hidden="true"> Dendrila Simple Edit <span class="dendrila-simple-edit-version">' . esc_html(DENDRILA_SIMPLE_EDIT_VERSION) . '</span></div>';
        echo '<h1>' . esc_html__('Modifier mon site', 'dendrila-simple-edit') . '</h1></header>';
        self::notices();
        echo '<nav class="dendrila-simple-edit-nav-tabs" aria-label="Rubriques de Modifier mon site">';
        foreach ($tabs as $key => $item) {
            if ($key === 'contenus') { continue; }
            echo '<a class="dendrila-simple-edit-tab' . ($tab === $key ? ' is-active' : '') . '" href="' .
                esc_url(self::tab_link($key)) . '"' . ($tab === $key ? ' aria-current="page"' : '') . '>' .
                '<span class="dashicons dashicons-' . esc_attr($item['icon']) . '" aria-hidden="true"></span>' .
                '<span>' . esc_html($item['label']) . '</span></a>';
        }
        echo '</nav><main id="dendrila-simple-edit-tab-content" class="dendrila-simple-edit-tab-content" tabindex="-1">';
        echo '<header class="dendrila-simple-edit-tab-heading"><h2>' . esc_html($tabs[$tab]['title']) . '</h2>';
        if ($tabs[$tab]['intro'] !== '') { echo '<p>' . esc_html($tabs[$tab]['intro']) . '</p>'; }
        echo '</header>';
        echo '<div class="dendrila-simple-edit-panel-grid dendrila-simple-edit-panel-' . esc_attr($tab) . '">';
        if ($tab === 'accueil') {
            $actions = array(
                array('pages', __('Modifier une page', 'dendrila-simple-edit'), 'Changer le titre et le contenu d’une page.'),
                array('articles', __('Modifier un article', 'dendrila-simple-edit'), 'Changer le titre et le contenu d’un article.'),
                array('evenements', __('Modifier un événement', 'dendrila-simple-edit'), 'Mettre à jour le titre, les horaires, le lieu et la description.'),
                array('menu', 'Réorganiser le menu', 'Changer la position des liens du menu principal.'),
                array('newsletters', __('Préparer une newsletter', 'dendrila-simple-edit'), 'Rédiger un message ou reprendre un brouillon.'),
                array('boutique', 'Modifier un produit', 'Mettre à jour les produits de la boutique.'),
                array('historique', __('Historique', 'dendrila-simple-edit'), 'Retrouver une version précédente des textes du site.'),
                array('reglages', 'Gérer les autorisations', 'Choisir ce que l’équipe peut modifier.'),
                array('validations', 'Valider les contenus', 'Examiner les demandes de l’équipe.'),
            );
            echo '<ul class="dendrila-simple-edit-dashboard-actions">';
            foreach ($actions as list($destination, $label, $description)) {
                if (!isset($tabs[$destination])) { continue; }
                echo '<li><a href="' . esc_url(self::tab_link($destination)) . '">' .
                    '<span class="dashicons dashicons-' . esc_attr($tabs[$destination]['icon']) . '" aria-hidden="true"></span>' .
                    '<span><strong>' . esc_html($label) . '</strong><span class="dendrila-simple-edit-action-description">' .
                    esc_html($description) . '</span></span></a></li>';
            }
            echo '</ul>';
        } elseif ($tab === 'validations') {
            Dendrila_Simple_Edit_Approvals::render();
        } elseif ($tab === 'pages') {
            Dendrila_Simple_Edit_Post_Editor::render('page');
        } elseif ($tab === 'articles') {
            Dendrila_Simple_Edit_Post_Editor::render('post');
        } elseif ($tab === 'evenements') {
            Dendrila_Simple_Edit_Event_Editor::render();
        } elseif ($tab === 'menu') {
            Dendrila_Simple_Edit_Navigation::render();
        } elseif ($tab === 'contenus' && $is_admin) {
            Dendrila_Simple_Edit_ACF::render();
        if (!$enabled) {
            echo '<div class="dendrila-simple-edit-card dendrila-simple-edit-empty"><h2>Aucun texte général à modifier</h2><p>Les textes supplémentaires, s’ils sont activés, apparaissent plus bas. Pour modifier les coordonnées ou la présentation du site, l’administrateur doit d’abord les autoriser.</p>';
            if ($is_admin) { echo '<a class="button button-primary" href="' . esc_url(self::tab_link('reglages')) . '">Choisir les textes à modifier</a>'; }
            echo '</div>';
        } elseif (self::can_edit()) {
            echo '<form class="dendrila-simple-edit-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_save">';
            echo '<input type="hidden" name="dendrila_simple_edit_revision" value="' . esc_attr((string) $state['revision']) . '">';
            wp_nonce_field('dendrila_simple_edit_save');
            echo '<h2>Coordonnées et présentation</h2><p>Modifiez ici uniquement les informations affichées sur votre site.</p>';
            foreach ($enabled as $key) {
                $definition = $definitions[$key];
                $value = isset($state['values'][$key]) && is_string($state['values'][$key]) ?
                    $state['values'][$key] : '';
                $id = 'dendrila-simple-edit-field-' . $key;
                echo '<div class="dendrila-simple-edit-field"><label for="' . esc_attr($id) . '">' .
                    esc_html($definition['label']) . '</label>';
                echo '<p class="description" id="' . esc_attr($id . '-help') . '">' .
                    esc_html($definition['help']) . '</p>';
                if ($definition['type'] === 'image') {
                    Dendrila_Simple_Edit_Media::render_picker($key, $value, $id);
                } elseif ($definition['type'] === 'textarea') {
                    echo '<textarea id="' . esc_attr($id) . '" name="dendrila_simple_edit_fields[' . esc_attr($key) .
                        ']" rows="4" maxlength="' . esc_attr((string) $definition['max']) .
                        '" aria-describedby="' . esc_attr($id . '-help') . '">' . esc_textarea($value) . '</textarea>';
                } else {
                    echo '<input id="' . esc_attr($id) . '" name="dendrila_simple_edit_fields[' . esc_attr($key) .
                        ']" type="' . esc_attr($definition['type']) .
                        '" maxlength="' . esc_attr((string) $definition['max']) .
                        '" aria-describedby="' . esc_attr($id . '-help') . '" value="' .
                        esc_attr($value) . '">';
                }
                echo '</div>';
            }
            echo '<p><button type="submit" class="button button-primary button-hero">Enregistrer mes modifications</button></p></form>';
        } else {
            echo '<div class="dendrila-simple-edit-card"><h2>Consultation uniquement</h2><p>Votre compte ne peut pas publier de changements.</p></div>';
        }


            Dendrila_Simple_Edit_Media::render_uploads();
        } elseif ($tab === 'newsletters') {
            if (!Dendrila_Simple_Edit_Modules::enabled('newsletters')) {
                echo '<section class="dendrila-simple-edit-card dendrila-simple-edit-empty"><h3>Les newsletters ne sont pas encore activées</h3><p>Depuis Autorisations, l’administrateur peut choisir qui peut préparer, modifier ou valider les brouillons.</p>';
                if ($is_admin) { echo '<p><a class="button button-primary" href="' . esc_url(self::tab_link('reglages')) . '">Gérer les accès</a></p>'; }
                echo '</section>';
            } else {
                Dendrila_Simple_Edit_Newsletters::render();
            }
        } elseif ($tab === 'boutique') {
            if (!Dendrila_Simple_Edit_Modules::enabled('products')) {
                echo '<section class="dendrila-simple-edit-card dendrila-simple-edit-empty"><h3>La boutique n’est pas encore activée ici</h3><p>Depuis Autorisations, l’administrateur peut donner accès à la gestion des produits.</p>';
                if ($is_admin) { echo '<p><a class="button button-primary" href="' . esc_url(self::tab_link('reglages')) . '">Gérer les accès</a></p>'; }
                echo '</section>';
            } elseif (!class_exists('WooCommerce')) {
                echo '<section class="dendrila-simple-edit-card dendrila-simple-edit-empty"><h3>WooCommerce n’est pas installé</h3><p>Cette rubrique sera utilisable lorsque la boutique WordPress sera activée.</p></section>';
            } else { Dendrila_Simple_Edit_Woo::render(); }
        } elseif ($tab === 'historique') {
        echo '<section class="dendrila-simple-edit-card" aria-labelledby="dendrila-simple-edit-history-heading"><h2 id="dendrila-simple-edit-history-heading">Modifications précédentes</h2>';
        echo '<p>Vous pouvez retrouver une ancienne version de vos textes. Les autres contenus du site ne sont pas touchés.</p>';
        $history = array_slice($state['history'], 0, 15);
        if (!$history || !$enabled) {
            echo '<p>Aucune modification précédente à afficher.</p>';
        } else {
            echo '<ul class="dendrila-simple-edit-history">';
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
                    echo '<input type="hidden" name="action" value="dendrila_simple_edit_restore">';
                    echo '<input type="hidden" name="dendrila_simple_edit_revision" value="' . esc_attr((string) $state['revision']) . '">';
                    echo '<input type="hidden" name="dendrila_simple_edit_restore_revision" value="' .
                        esc_attr((string) $entry['revision']) . '">';
                    wp_nonce_field('dendrila_simple_edit_restore');
                    echo '<button class="button" type="submit">Restaurer cette version</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
        echo '<section class="dendrila-simple-edit-card"><h2>Où ces textes sont-ils affichés ?</h2>';
        echo '<p>Une modification ne se voit que là où ce texte est déjà utilisé sur le site.</p>';
        if ($enabled) {
            echo '<ul>';
            foreach ($enabled as $key) {
                echo '<li><strong>' . esc_html($definitions[$key]['label']) . '</strong>';
                $locations = self::public_locations($key);
                if ($locations) {
                    echo '<span class="dendrila-simple-edit-usage-label">Pages sur lesquelles ce texte apparaît :</span><ul class="dendrila-simple-edit-usage">';
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
        $settings_sections = array(
            'general' => __('Modules', 'dendrila-simple-edit'),
            'contenu' => __('Contenu', 'dendrila-simple-edit'),
            'roles' => __('Rôles et permissions', 'dendrila-simple-edit'),
            'plugins' => __('Accès aux plugins', 'dendrila-simple-edit'),
            'validations' => __('Validation', 'dendrila-simple-edit'),
        );
        $section = isset($_GET['dendrila_simple_edit_settings_section']) && is_string($_GET['dendrila_simple_edit_settings_section']) ?
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_settings_section'])) : 'general';
        // Preserve old links/bookmarks without keeping redundant settings tabs.
        $legacy_content = array('textes'=>'textes', 'contenus'=>'pages', 'images'=>'images',
            'acf'=>'acf', 'evenements'=>'pages');
        $content_section = isset($_GET['dendrila_simple_edit_content_section']) && is_string($_GET['dendrila_simple_edit_content_section']) ?
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_content_section'])) : 'pages';
        if (isset($legacy_content[$section])) {
            $content_section = $legacy_content[$section];
            $section = 'contenu';
        } elseif ($section === 'modules') { $section = 'general'; }
        if (!isset($settings_sections[$section])) { $section = 'general'; }
        if (!in_array($content_section, array('pages','images','acf','textes'), true)) { $content_section = 'pages'; }
        echo '<nav class="dendrila-simple-edit-newsletter-subnav dendrila-simple-edit-settings-subnav" aria-label="Sous-rubriques des réglages">';
        foreach ($settings_sections as $key => $label) {
            $url = add_query_arg('dendrila_simple_edit_settings_section', $key, self::tab_link('reglages'));
            echo '<a class="dendrila-simple-edit-newsletter-subtab" href="' . esc_url($url) . '"' .
                ($section === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ($section === 'general') {
            echo '<section class="dendrila-simple-edit-card dendrila-simple-edit-setup-relaunch"><h2>' . esc_html__('Assistant de configuration', 'dendrila-simple-edit') . '</h2>';
            echo '<p>' . esc_html__('Reprendre manuellement les cinq étapes sans désinstaller le plugin. Les listes et contacts sont conservés, et vous pouvez quitter le parcours à tout moment. Une mise à jour ne relancera jamais l’assistant.', 'dendrila-simple-edit') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dendrila_simple_edit_setup_restart">';
            wp_nonce_field('dendrila_simple_edit_setup_restart');
            echo '<button class="button button-secondary" type="submit">' . esc_html__('Relancer l’assistant de configuration', 'dendrila-simple-edit') . '</button></form></section>';
            Dendrila_Simple_Edit_Modules::render_modules();
        } elseif ($section === 'contenu') {
            $content_sections = array('pages'=>__('Pages, articles et événements', 'dendrila-simple-edit'),
                'images'=>__('Images', 'dendrila-simple-edit'),
                'acf'=>__('Champs complémentaires', 'dendrila-simple-edit'),
                'textes'=>__('Textes et coordonnées', 'dendrila-simple-edit'));
            echo '<nav class="dendrila-simple-edit-newsletter-subnav dendrila-simple-edit-content-subnav" aria-label="Options de contenu">';
            foreach ($content_sections as $key=>$label) {
                $url = add_query_arg(array('dendrila_simple_edit_settings_section'=>'contenu',
                    'dendrila_simple_edit_content_section'=>$key), self::tab_link('reglages'));
                echo '<a class="dendrila-simple-edit-newsletter-subtab" href="' . esc_url($url) . '"' .
                    ($content_section === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
            }
            echo '</nav>';
            if ($content_section === 'pages') {
                Dendrila_Simple_Edit_Post_Editor::render_admin();
            } elseif ($content_section === 'images') {
                Dendrila_Simple_Edit_Media::render_admin();
            } elseif ($content_section === 'acf') {
                Dendrila_Simple_Edit_ACF::render(true);
            } elseif ($content_section === 'textes') {

            echo '<section class="dendrila-simple-edit-card"><h2>Qui peut modifier le site ?</h2>';
            echo '<p>Attribuez le rôle <strong>Édition simple</strong> aux comptes de votre choix. ' .
                'Ces personnes verront cette interface et les plugins autorisés dans leur administration WordPress.</p>';
            echo '<p><a class="button button-secondary" href="' .
                esc_url(admin_url('users.php')) . '">Gérer les utilisateurs</a></p></section>';
            echo '<form class="dendrila-simple-edit-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<h2>Choisir les informations modifiables</h2><p>Cochez ce que les utilisateurs autorisés pourront modifier, sans leur ouvrir les autres réglages WordPress.</p>';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_configure">';
            wp_nonce_field('dendrila_simple_edit_configure');
            foreach ($definitions as $key => $definition) {
                echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_enabled[]" value="' .
                    esc_attr($key) . '" ' . checked(in_array($key, $enabled, true), true, false) . '> ' .
                    esc_html($definition['label']) . '</label>';
            }
            echo '<p><button class="button button-secondary" type="submit">Enregistrer les accès aux textes</button></p>';
            echo '<p class="description">Pour qu’un texte apparaisse sur le site, son emplacement doit être prévu dans la page ou le modèle. Les codes techniques sont présentés dans l’onglet Aide.</p>';
            $contact_page = get_posts(array('post_type' => 'page', 'post_status' => array('publish','draft'), 'posts_per_page' => 1, 's' => 'contact'));
            if ($contact_page) { echo '<p><a class="button" href="' . esc_url(get_edit_post_link($contact_page[0]->ID, '')) . '" target="_blank" rel="noopener noreferrer">Modifier la page Contact (nouvel onglet)</a></p>'; }
            echo '<p class="description">Vérifiez aussi le pied de page et les autres pages : les coordonnées peuvent y être écrites directement et ne pas dépendre des textes partagés.</p>';
            echo '</form>';
            $created = isset($_GET['dendrila_simple_edit_created']) && is_string($_GET['dendrila_simple_edit_created']) ?
                sanitize_key(wp_unslash($_GET['dendrila_simple_edit_created'])) : '';
            $shared = array_filter($definitions, static function ($key) {
                return strpos($key, 'zone_') === 0;
            }, ARRAY_FILTER_USE_KEY);
            echo '<details id="dendrila-simple-edit-shared-text" class="dendrila-simple-edit-card dendrila-simple-edit-advanced"' .
                (isset($shared[$created]) ? ' open' : '') . '><summary>Textes partagés entre plusieurs pages</summary>';
            echo '<p>Vous voulez afficher la même annonce sur plusieurs pages ? Créez un texte partagé. Son édition reste accessible depuis les réglages avancés.</p>';
            if (isset($shared[$created])) {
                echo '<p><strong>' . esc_html($shared[$created]['label']) . ' est prêt à être placé.</strong></p>';
            }
            if ($shared) {
                echo '<p>Dans chaque page concernée, ajoutez un bloc <strong>Code court</strong> et collez le code du texte choisi. Rédigez ensuite le texte dans <strong>Textes et images</strong>.</p>';
                foreach ($shared as $key => $definition) {
                    echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-shared-' . esc_attr($key) . '">' .
                        esc_html($definition['label']) . ' · code à copier</label><input readonly type="text" id="dendrila-simple-edit-shared-' .
                        esc_attr($key) . '" value="' . esc_attr('[dse_text field="' . $key . '"]') . '"><button type="button" class="button dendrila-simple-edit-copy" data-dendrila-simple-edit-copy="dendrila-simple-edit-shared-' . esc_attr($key) . '">Copier le code</button></div>';
                }
                echo '<p><a href="' . esc_url(self::tab_link('contenus')) . '">Ouvrir les anciens textes</a></p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_add_field">';
            wp_nonce_field('dendrila_simple_edit_add_field');
            echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-label">Nom du texte</label>';
            echo '<input type="text" id="dendrila-simple-edit-label" name="dendrila_simple_edit_label" required maxlength="80" placeholder="Ex. Message de bienvenue"></div>';
            echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-type">Format</label>';
            echo '<select id="dendrila-simple-edit-type" name="dendrila_simple_edit_field_type">';
            echo '<option value="text">Texte court (titre)</option>';
            echo '<option value="textarea">Plusieurs lignes (paragraphe)</option>';
            echo '</select></div>';
            echo '<p><button class="button" type="submit">Créer le texte et obtenir son code</button></p>';
            echo '</form></details>';
            }
        } elseif ($section === 'roles') {
            echo '<section class="dendrila-simple-edit-card"><h2>' . esc_html__('Permissions par rôle', 'dendrila-simple-edit') . '</h2>';
            echo '<p>' . esc_html__('Ouvrez uniquement le module et le rôle à configurer. Les administrateurs conservent tous leurs droits.', 'dendrila-simple-edit') . '</p></section>';
            Dendrila_Simple_Edit_Modules::render_admin();
        } elseif ($section === 'validations') {
            Dendrila_Simple_Edit_Approvals::render_settings();
        } elseif ($section === 'plugins') {
            Dendrila_Simple_Edit_Admin_Access::render_settings();
        }
        } elseif ($tab === 'aide') {
            echo '<section class="dendrila-simple-edit-card"><h3>Comment modifier un contenu ?</h3><ol class="dendrila-simple-edit-steps"><li>Ouvrez l’onglet Pages, Articles ou Événements.</li><li>Choisissez un contenu autorisé et modifiez son titre et les éléments nécessaires.</li><li>Enregistrez vos modifications.</li></ol><p>Si un texte n’apparaît pas, l’administrateur doit peut-être encore autoriser sa modification.</p></section>';
            echo '<section class="dendrila-simple-edit-card"><h3>Ce que Simple Edit ne modifie pas</h3><p>Les menus, les modèles et la disposition de la page restent tels quels. L’idée est de pouvoir changer le contenu sans risquer de casser le design.</p></section>';
            if ($is_admin) {
                echo '<details class="dendrila-simple-edit-card dendrila-simple-edit-advanced"><summary>Pour les administrateurs : placer un texte sur une page</summary><div class="dendrila-simple-edit-details-body"><p>Chaque texte peut être inséré à un endroit précis avec un petit code WordPress (appelé « code court »).</p>';
                foreach ($enabled as $key) {
                    echo '<p><strong>' . esc_html($definitions[$key]['label']) . '</strong><code id="dendrila-simple-edit-code-' . esc_attr($key) . '">[dse_text field="' . esc_html($key) . '"]</code> <button class="button dendrila-simple-edit-copy" type="button" data-dendrila-simple-edit-copy="dendrila-simple-edit-code-' . esc_attr($key) . '">Copier le code</button></p>';
                }
                Dendrila_Simple_Edit_Builders::render();
                echo '</div></details>';
            }
        }
        echo '</div></main>';
        // One understated footer across all Simple Edit screens, including admin sub-tabs.
        echo '<footer class="dendrila-simple-edit-support" aria-label="' .
            esc_attr__('Soutenir le développement', 'dendrila-simple-edit') . '">';
        echo '<a class="dendrila-simple-edit-support-link" href="https://app.lepotager.org/soutenir/" target="_blank" rel="noopener noreferrer">' .
            '<span class="dashicons dashicons-coffee" aria-hidden="true"></span><span>' .
            esc_html__('Offrez un café au développeur', 'dendrila-simple-edit') . '</span>' .
            '<span class="screen-reader-text"> (' . esc_html__('nouvel onglet', 'dendrila-simple-edit') . ')</span></a>';
        echo '</footer></div>';
    }
}
