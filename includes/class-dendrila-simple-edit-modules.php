<?php
/**
 * Optional delegated modules: module activation and grants by WordPress role.
 * No WordPress core capabilities are added to the delegated role.
 */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Modules
{
    const OPTION = 'dendrila_simple_edit_modules';

    public static function definitions()
    {
        return array(
            'editorial' => array(
                'label' => __('Création de contenus et menu principal', 'dendrila-simple-edit'),
                'actions' => array(
                    'edit_pages' => __('Modifier les pages existantes autorisées', 'dendrila-simple-edit'),
                    'create_pages' => __('Créer de nouvelles pages (brouillons)', 'dendrila-simple-edit'),
                    'publish_pages' => __('Publier les nouvelles pages sans validation', 'dendrila-simple-edit'),
                    'edit_articles' => __('Modifier les articles existants autorisés', 'dendrila-simple-edit'),
                    'edit_events' => __('Modifier les événements existants', 'dendrila-simple-edit'),
                    'create_articles' => __('Créer de nouveaux articles (brouillons)', 'dendrila-simple-edit'),
                    'publish_articles' => __('Publier les nouveaux articles sans validation', 'dendrila-simple-edit'),
                    'reorder_menu' => __('Réorganiser le menu principal', 'dendrila-simple-edit'),
                ),
                'future' => 'Ces autorisations s’attribuent par rôle WordPress. Aucun accès supplémentaire à Gutenberg, au thème, aux extensions ou aux réglages WordPress.',
            ),
            'newsletters' => array(
                'label' => __('Newsletters', 'dendrila-simple-edit'),
                'actions' => array(
                    'create' => __('Créer un brouillon', 'dendrila-simple-edit'),
                    'edit' => __('Modifier les brouillons', 'dendrila-simple-edit'),
                    'review' => __('Soumettre à validation', 'dendrila-simple-edit'),
                    'approve' => __('Valider un contenu', 'dendrila-simple-edit'),
                    'send' => __('Préparer et envoyer ses newsletters sans validation préalable', 'dendrila-simple-edit'),
                    'manage_lists' => __('Créer et gérer ses listes et leurs contacts', 'dendrila-simple-edit'),
                ),
                'future' => 'L’administrateur définit les droits par rôle : chaque utilisateur gère ensuite ses propres contenus, listes et campagnes sans autorisation supplémentaire. Les listes existantes peuvent être attribuées depuis leur gestion.',
            ),
            'products' => array(
                'label' => __('Produits WooCommerce', 'dendrila-simple-edit'),
                'actions' => array(
                    'create' => __('Créer un produit (brouillon)', 'dendrila-simple-edit'),
                    'publish' => __('Publier les produits sans validation', 'dendrila-simple-edit'),
                    'approve' => __('Valider les produits proposés', 'dendrila-simple-edit'),
                    'types' => __('Créer des produits variables, groupés ou externes', 'dendrila-simple-edit'),
                    'variations' => __('Gérer les variations des produits', 'dendrila-simple-edit'),
                    'create_categories' => __('Créer des catégories et sous-catégories de produits', 'dendrila-simple-edit'),
                    'content' => __('Modifier les textes', 'dendrila-simple-edit'),
                    'images' => __('Choisir une image autorisée', 'dendrila-simple-edit'),
                    'categories' => __('Modifier les catégories existantes', 'dendrila-simple-edit'),
                    'prices' => __('Modifier le prix régulier', 'dendrila-simple-edit'),
                    'stock' => __('Modifier le stock', 'dendrila-simple-edit'),
                    'live' => __('Modifier des produits déjà publiés', 'dendrila-simple-edit'),
                ),
                'future' => 'Les commandes, remboursements et paiements restent hors périmètre. La publication et les variations sont soumises à des droits explicites.',
            ),
        );
    }

    public static function settings()
    {
        $raw = get_option(self::OPTION, array());
        if (!is_array($raw)) { return array(); }
        // Existing laboratory installations stored per-user grants. Convert only
        // unambiguous accounts (one role) so the upgrade does not silently lock
        // those users out. Never expand one grant into multiple unrelated roles.
        $changed = false;
        foreach (self::definitions() as $module => $definition) {
            if (!isset($raw[$module]) || !is_array($raw[$module]) ||
                array_key_exists('roles', $raw[$module]) ||
                !isset($raw[$module]['grants']) || !is_array($raw[$module]['grants'])) { continue; }
            $mapped = array();
            foreach ($raw[$module]['grants'] as $id => $actions) {
                $user = ctype_digit((string)$id) ? get_userdata((int)$id) : false;
                if (!$user || !is_array($user->roles) || count($user->roles) !== 1 || !is_array($actions)) { continue; }
                $role = reset($user->roles);
                if ($role === 'administrator') { continue; }
                $actions = array_values(array_intersect($actions, array_keys($definition['actions'])));
                if (!isset($mapped[$role])) { $mapped[$role] = array(); }
                $mapped[$role] = array_values(array_unique(array_merge($mapped[$role], $actions)));
            }
            $raw[$module]['roles'] = $mapped;
            unset($raw[$module]['grants']);
            $changed = true;
        }
        // A one-time save by an administrator; other users only use the
        // in-memory migrated representation, and cannot modify settings.
        if ($changed && current_user_can('manage_options')) { update_option(self::OPTION, $raw, false); }
        return $raw;
    }

    public static function enabled($module)
    {
        $defs = self::definitions();
        $settings = self::settings();
        return isset($defs[$module], $settings[$module]) &&
            is_array($settings[$module]) && ($settings[$module]['enabled'] ?? false) === true;
    }

    /** All permissions use WordPress roles, never individual account IDs. */
    public static function granted_for_role($module, $role)
    {
        $settings = self::settings();
        $roles = $settings[$module]['roles'] ?? array();
        return is_array($roles) && isset($roles[$role]) && is_array($roles[$role]) ? $roles[$role] : array();
    }

    public static function allowed($module, $action, $user_id = null)
    {
        $definitions = self::definitions();
        if (!is_string($module) || !isset($definitions[$module]) ||
            !is_string($action) || !isset($definitions[$module]['actions'][$action]) ||
            !self::enabled($module)) { return false; }
        $id = $user_id === null ? get_current_user_id() : (int) $user_id;
        if ($id < 1 || !user_can($id, 'read')) { return false; }
        if (user_can($id, 'manage_options')) { return true; }
        $user = get_userdata($id);
        if (!$user || !is_array($user->roles)) { return false; }
        foreach ($user->roles as $role) {
            if (in_array($action, self::granted_for_role($module, $role), true)) { return true; }
        }
        return false;
    }

    /** The menu is available to roles explicitly granted at least one enabled tool. */
    public static function has_any_grant($user_id = null, $module_filter = null)
    {
        $id = $user_id === null ? get_current_user_id() : (int) $user_id;
        if ($id < 1 || !user_can($id, 'read')) { return false; }
        $definitions = self::definitions();
        foreach ($definitions as $module => $definition) {
            if ($module_filter !== null && $module !== $module_filter) { continue; }
            foreach (array_keys($definition['actions']) as $action) {
                if (self::allowed($module, $action, $id)) { return true; }
            }
        }
        return false;
    }

    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_module_configure', array(__CLASS__, 'configure'));
        add_action('admin_post_dendrila_simple_edit_module_toggle', array(__CLASS__, 'configure_enabled'));
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_module_configure');
        $module = isset($_POST['dendrila_simple_edit_module']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_module'])) : '';
        $definitions = self::definitions();
        if (!is_string($module) || !isset($definitions[$module])) {
            wp_die('Module inconnu.', 'Requête incorrecte', array('response' => 400));
        }
        $submitted = isset($_POST['dendrila_simple_edit_roles']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_roles']), 'sanitize_text_field') : array();
        $roles = wp_roles()->roles;
        if (!is_array($submitted) || count($submitted) > count($roles)) {
            wp_die('Autorisations incorrectes.', 'Requête incorrecte', array('response' => 400));
        }
        $allowed_actions = array_keys($definitions[$module]['actions']);
        $grants_by_role = array();
        foreach ($submitted as $role => $actions) {
            if (!is_string($role) || !isset($roles[$role]) || $role === 'administrator' ||
                !is_array($actions) || count($actions) > count($allowed_actions)) {
                wp_die('Rôle ou autorisation incorrect.', 'Requête incorrecte', array('response' => 400));
            }
            foreach ($actions as $action) {
                if (!is_string($action) || !in_array($action, $allowed_actions, true)) {
                    wp_die('Action non autorisée.', 'Requête incorrecte', array('response' => 400));
                }
            }
            $grants_by_role[$role] = array_values(array_unique($actions));
        }
        // The roles form must NEVER alter module activation. A stale roles tab
        // cannot turn newsletter sending on/off while permissions are being saved.
        $settings = self::settings();
        $enabled = self::enabled($module);
        $settings[$module] = array(
            'enabled' => $enabled,
            'roles' => $grants_by_role,
        );
        update_option(self::OPTION, $settings, false);
        wp_safe_redirect(add_query_arg(array('dendrila_simple_edit_notice' => 'module_configured', 'dendrila_simple_edit_tab' => 'reglages',
            'dendrila_simple_edit_settings_section' => 'roles'),
            admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    /** Change activation state without replacing any saved role permissions. */
    public static function configure_enabled()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Configuration réservée à l’administration.', 'dendrila-simple-edit'), '', array('response' => 403));
        }
        $slug = isset($_POST['dendrila_simple_edit_module']) && is_string($_POST['dendrila_simple_edit_module']) ?
            sanitize_key(wp_unslash($_POST['dendrila_simple_edit_module'])) : '';
        $defs = self::definitions();
        if (!isset($defs[$slug])) { wp_die(esc_html__('Module inconnu.', 'dendrila-simple-edit'), '', array('response' => 400)); }
        check_admin_referer('dendrila_simple_edit_module_toggle_' . $slug);
        $enable = isset($_POST['dendrila_simple_edit_module_enabled']) &&
            sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_module_enabled'])) === '1';
        if ($slug === 'newsletters' && $enable && !Dendrila_Simple_Edit_Mailing::transport_ready()) {
            wp_die(esc_html__('Pour activer les newsletters, configurez une connexion FluentSMTP.', 'dendrila-simple-edit'), '', array('response' => 400));
        }
        $settings = self::settings();
        if (!isset($settings[$slug]) || !is_array($settings[$slug])) { $settings[$slug] = array(); }
        $settings[$slug]['enabled'] = $enable;
        if (!isset($settings[$slug]['roles']) || !is_array($settings[$slug]['roles'])) {
            $settings[$slug]['roles'] = array();
        }
        update_option(self::OPTION, $settings, false);
        wp_safe_redirect(add_query_arg(array('dendrila_simple_edit_tab'=>'reglages',
            'dendrila_simple_edit_settings_section'=>'general', 'dendrila_simple_edit_notice'=>'module_configured'),
            admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    /** A short first screen: activation only, with permissions handled separately. */
    public static function render_modules()
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<section class="dendrila-simple-edit-card"><h2>' . esc_html__('Outils disponibles', 'dendrila-simple-edit') . '</h2>';
        echo '<p>' . esc_html__('Choisissez les fonctions accessibles sur le site. Configurez ensuite les droits dans « Rôles et permissions ».', 'dendrila-simple-edit') . '</p>';
        foreach (self::definitions() as $slug=>$def) {
            $enabled = self::enabled($slug);
            echo '<form class="dendrila-simple-edit-module-switch" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_module_toggle">';
            echo '<input type="hidden" name="dendrila_simple_edit_module" value="' . esc_attr($slug) . '">';
            wp_nonce_field('dendrila_simple_edit_module_toggle_' . $slug);
            echo '<div class="dendrila-simple-edit-module-switch-main"><strong>' . esc_html($def['label']) . '</strong>';
            echo '<span class="dendrila-simple-edit-module-status">' . ($enabled ? esc_html__('Activé', 'dendrila-simple-edit') : esc_html__('Désactivé', 'dendrila-simple-edit')) . '</span>';
            if ($slug === 'newsletters') {
                echo '<p class="description">' . esc_html__('FluentSMTP est requis pour les newsletters.', 'dendrila-simple-edit') . ' ';
                echo Dendrila_Simple_Edit_Mailing::transport_ready() ? esc_html__('Connexion détectée.', 'dendrila-simple-edit') : esc_html__('Connexion manquante : activation impossible.', 'dendrila-simple-edit');
                echo ' <a href="' . esc_url(admin_url('options-general.php?page=fluent-mail')) . '">' . esc_html__('Configurer FluentSMTP', 'dendrila-simple-edit') . '</a></p>';
            }
            echo '</div><label class="dendrila-simple-edit-module-toggle"><input type="checkbox" name="dendrila_simple_edit_module_enabled" value="1" ' . checked($enabled, true, false);
            if ($slug === 'newsletters' && !$enabled && !Dendrila_Simple_Edit_Mailing::transport_ready()) { echo ' disabled'; }
            echo '> ' . esc_html__('Activer', 'dendrila-simple-edit') . '</label>';
            echo '<button class="button button-secondary" type="submit">' . esc_html__('Enregistrer', 'dendrila-simple-edit') . '</button>';
            echo '</form>';
        }
        echo '</section>';
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        $roles = wp_roles()->roles;
        unset($roles['administrator']); // Administrators have full access while a module is active.
        $settings = self::settings();
        foreach (self::definitions() as $module => $definition) {
            $configured = $settings[$module] ?? array();
            $grants = is_array($configured) ? ($configured['roles'] ?? array()) : array();
            $granted_roles = 0;
            foreach ($grants as $role_permissions) { if (is_array($role_permissions) && $role_permissions) { $granted_roles++; } }
            echo '<details class="dendrila-simple-edit-card dendrila-simple-edit-module-permissions"><summary><strong>' . esc_html($definition['label']) . '</strong> <span class="description">(' . (int)$granted_roles . ' rôles autorisés)</span></summary>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<p>Configurez les accès une fois par rôle WordPress : les membres d’un même rôle ont les mêmes droits.</p>';
            if ($module === 'newsletters') {
                echo '<p><strong>FluentSMTP est obligatoire pour le mailing.</strong> ' .
                    (Dendrila_Simple_Edit_Mailing::transport_ready() ?
                        'Connexion détectée, vous pouvez activer le module.' :
                        'Installez/activez FluentSMTP et configurez une connexion avant d’activer ce module.') .
                    ' <a href="' . esc_url(admin_url('plugin-install.php?s=FluentSMTP&tab=search&type=term')) . '">Installer FluentSMTP</a> · ' .
                    '<a href="' . esc_url(admin_url('options-general.php?page=fluent-mail')) . '">Configurer la connexion</a></p>';
            }
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_module_configure">';
            echo '<input type="hidden" name="dendrila_simple_edit_module" value="' . esc_attr($module) . '">';
            wp_nonce_field('dendrila_simple_edit_module_configure');
            echo '<p class="description">' . esc_html__('Activation et désactivation : rubrique « Modules ».', 'dendrila-simple-edit') . '</p>';
            foreach ($roles as $role => $meta) {
                $assigned = is_array($grants) ? ($grants[$role] ?? array()) : array();
                $role_label = translate_user_role($meta['name'] ?? $role);
                $group_id = 'dendrila-simple-edit-' . $module . '-' . $role;
                echo '<details class="dendrila-simple-edit-role-permissions" id="' . esc_attr($group_id) . '"><summary>' . esc_html($role_label) . ' (' . count(is_array($assigned) ? $assigned : array()) . ')</summary><fieldset class="dendrila-simple-edit-permissions"><legend class="screen-reader-text">' . esc_html($role_label) . '</legend>';
                echo '<div class="dendrila-simple-edit-bulk-permissions"><button type="button" class="button button-secondary" data-dendrila-simple-edit-select-all="' . esc_attr($group_id) . '">' . esc_html__('Tout sélectionner', 'dendrila-simple-edit') . '</button> ';
                echo '<button type="button" class="button button-link" data-dendrila-simple-edit-select-none="' . esc_attr($group_id) . '">' . esc_html__('Tout désélectionner', 'dendrila-simple-edit') . '</button></div>';
                foreach ($definition['actions'] as $key => $label) {
                    echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_roles[' .
                        esc_attr($role) . '][]" value="' . esc_attr($key) . '" ' .
                        checked(is_array($assigned) && in_array($key, $assigned, true), true, false) .
                        '> ' . esc_html($label) . '</label>';
                }
                echo '</fieldset></details>';
            }
            echo '<p class="description">' . esc_html($definition['future']) . '</p>';
            echo '<p><button type="submit" class="button button-primary">Enregistrer les autorisations</button></p></form></details>';
        }
    }
}
