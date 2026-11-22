<?php
/**
 * Optional delegated modules: module activation and individual per-user grants.
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
                'label' => 'Création de contenus et menu principal',
                'actions' => array(
                    'create_pages' => 'Créer de nouvelles pages (brouillons)',
                    'publish_pages' => 'Publier les nouvelles pages sans validation',
                    'create_articles' => 'Créer de nouveaux articles (brouillons)',
                    'publish_articles' => 'Publier les nouveaux articles sans validation',
                    'reorder_menu' => __('Réorganiser le menu principal', 'dendrila-simple-edit'),
                ),
                'future' => 'Ces autorisations s’attribuent individuellement. Aucun accès à Gutenberg, au thème, aux extensions ou aux réglages WordPress.',
            ),
            'newsletters' => array(
                'label' => __('Newsletters', 'dendrila-simple-edit'),
                'actions' => array(
                    'create' => 'Créer un brouillon',
                    'edit' => 'Modifier les brouillons',
                    'review' => __('Soumettre à validation', 'dendrila-simple-edit'),
                    'approve' => 'Valider un contenu',
                    'send' => 'Préparer et envoyer ses newsletters sans validation préalable',
                    'manage_lists' => 'Créer et gérer ses listes et leurs contacts',
                ),
                'future' => 'L’administrateur définit les droits une seule fois : l’utilisateur gère ensuite ses propres contenus, listes et campagnes sans autorisation supplémentaire. Les listes existantes peuvent être attribuées depuis leur gestion.',
            ),
            'products' => array(
                'label' => 'Produits WooCommerce',
                'actions' => array(
                    'create' => 'Créer un produit brouillon',
                    'content' => 'Modifier les textes',
                    'images' => 'Choisir une image autorisée',
                    'categories' => 'Modifier les catégories existantes',
                    'prices' => 'Modifier le prix régulier',
                    'stock' => 'Modifier le stock',
                    'live' => 'Modifier des produits déjà publiés',
                ),
                'future' => 'Publication, variations, commandes, remboursements et paiements restent hors périmètre.',
            ),
        );
    }

    public static function settings()
    {
        $raw = get_option(self::OPTION, array());
        return is_array($raw) ? $raw : array();
    }

    public static function enabled($module)
    {
        $defs = self::definitions();
        $settings = self::settings();
        return isset($defs[$module], $settings[$module]) &&
            is_array($settings[$module]) && ($settings[$module]['enabled'] ?? false) === true;
    }

    public static function allowed($module, $action, $user_id = null)
    {
        $definitions = self::definitions();
        if (!is_string($module) || !isset($definitions[$module]) ||
            !is_string($action) || !isset($definitions[$module]['actions'][$action]) ||
            !self::enabled($module)) {
            return false;
        }
        $id = $user_id === null ? get_current_user_id() : $user_id;
        if (!is_int($id) || $id < 1) { return false; }
        if (user_can($id, 'manage_options')) { return true; }
        if (!user_can($id, 'read') || !user_can($id, 'dendrila_simple_edit_access')) {
            return false;
        }
        $settings = self::settings();
        $grants = $settings[$module]['grants'] ?? array();
        $operations = is_array($grants) ? ($grants[(string) $id] ?? array()) : array();
        return is_array($operations) && in_array($action, $operations, true);
    }

    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_module_configure', array(__CLASS__, 'configure'));
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_module_configure');
        $module = isset($_POST['dendrila_simple_edit_module']) ? wp_unslash($_POST['dendrila_simple_edit_module']) : '';
        $definitions = self::definitions();
        if (!is_string($module) || !isset($definitions[$module])) {
            wp_die('Module inconnu.', 'Requête incorrecte', array('response' => 400));
        }
        $submitted = isset($_POST['dendrila_simple_edit_grants']) ? wp_unslash($_POST['dendrila_simple_edit_grants']) : array();
        if (!is_array($submitted) || count($submitted) > 50) {
            wp_die('Autorisations incorrectes.', 'Requête incorrecte', array('response' => 400));
        }
        $allowed_actions = array_keys($definitions[$module]['actions']);
        $grants = array();
        foreach ($submitted as $key => $actions) {
            $user_id = (string) $key;
            if (!ctype_digit($user_id) || (int) $user_id < 1 ||
                !is_array($actions) || count($actions) > count($allowed_actions) ||
                !get_user_by('id', (int) $user_id) ||
                !user_can((int) $user_id, 'dendrila_simple_edit_access') ||
                user_can((int) $user_id, 'manage_options')) {
                wp_die('Utilisateur ou autorisation incorrect.', 'Requête incorrecte', array('response' => 400));
            }
            foreach ($actions as $action) {
                if (!is_string($action) || !in_array($action, $allowed_actions, true)) {
                    wp_die('Action non autorisée.', 'Requête incorrecte', array('response' => 400));
                }
            }
            $grants[$user_id] = array_values(array_unique($actions));
        }
        $settings = self::settings();
        $settings[$module] = array(
            'enabled' => isset($_POST['dendrila_simple_edit_module_enabled']) && $_POST['dendrila_simple_edit_module_enabled'] === '1',
            'grants' => $grants,
        );
        update_option(self::OPTION, $settings, false);
        wp_safe_redirect(add_query_arg(array('dendrila_simple_edit_notice' => 'module_configured', 'dendrila_simple_edit_tab' => 'reglages'),
            admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        $users = get_users(array('role' => Dendrila_Simple_Edit_Plugin::ROLE, 'number' => 50, 'orderby' => 'display_name'));
        $settings = self::settings();
        foreach (self::definitions() as $module => $definition) {
            $configured = $settings[$module] ?? array();
            $grants = is_array($configured) ? ($configured['grants'] ?? array()) : array();
            echo '<form class="dendrila-simple-edit-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<h2>' . esc_html($definition['label']) . '</h2>';
            echo '<p>Choisissez qui pourra utiliser cet outil et ce que cette personne pourra y faire, sans lui donner accès à toute l’administration.</p>';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_module_configure">';
            echo '<input type="hidden" name="dendrila_simple_edit_module" value="' . esc_attr($module) . '">';
            wp_nonce_field('dendrila_simple_edit_module_configure');
            echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_module_enabled" value="1" ' .
                checked(self::enabled($module), true, false) . '> Activer ce module</label>';
            if (!$users) {
                echo '<p>Aucun utilisateur ne possède encore le rôle « Édition simple ». Créez un compte avec ce rôle pour pouvoir lui donner ces autorisations.</p>';
            }
            foreach ($users as $user) {
                $assigned = is_array($grants) ? ($grants[(string) $user->ID] ?? array()) : array();
                echo '<fieldset class="dendrila-simple-edit-permissions"><legend>' . esc_html($user->display_name) . '</legend>';
                foreach ($definition['actions'] as $key => $label) {
                    echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_grants[' .
                        esc_attr((string) $user->ID) . '][]" value="' . esc_attr($key) . '" ' .
                        checked(is_array($assigned) && in_array($key, $assigned, true), true, false) .
                        '> ' . esc_html($label) . '</label>';
                }
                echo '</fieldset>';
            }
            echo '<p class="description">' . esc_html($definition['future']) . '</p>';
            echo '<p><button type="submit" class="button button-secondary">Enregistrer les autorisations</button></p></form>';
        }
    }
}
