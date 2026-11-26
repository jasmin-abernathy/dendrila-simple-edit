<?php
/** Guided first-run setup for plugin activators and network administrators. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Setup
{
    const OPTION = 'dendrila_simple_edit_setup_completed_v1';
    const STEP_OPTION = 'dendrila_simple_edit_setup_step_v1';
    const REDIRECT_OPTION = 'dendrila_simple_edit_setup_redirect_v1';
    const REVIEW_OPTION = 'dendrila_simple_edit_setup_review_v1';

    public static function completed()
    {
        return get_option(self::OPTION, '0') === '1';
    }

    public static function reviewing()
    {
        return get_option(self::REVIEW_OPTION, '0') === '1';
    }

    public static function needs_wizard()
    {
        return !self::completed() || self::reviewing();
    }

    public static function can_configure()
    {
        return current_user_can('manage_options') || current_user_can('activate_plugins') ||
            current_user_can('install_plugins') || (function_exists('is_super_admin') && is_super_admin());
    }

    public static function on_activate()
    {
        // First installation only: activation after an update or reactivation must
        // preserve the completed wizard and every existing module/list/contact.
        if (get_option(self::OPTION, null) !== null) { return; }
        add_option(self::OPTION, '0', '', false);
        update_option(self::STEP_OPTION, 1, false);
        update_option(self::REDIRECT_OPTION, '1', false);
    }

    public static function boot()
    {
        add_action('admin_init', array(__CLASS__, 'after_activation'), 5);
        add_action('admin_post_dendrila_simple_edit_setup_save', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_setup_restart', array(__CLASS__, 'restart'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function after_activation()
    {
        if (!self::can_configure() || self::completed() ||
            get_option(self::REDIRECT_OPTION, '0') !== '1') { return; }
        global $pagenow;
        // Respect the admin-post handler and AJAX requests while activation finishes.
        if (in_array($pagenow, array('admin-post.php', 'admin-ajax.php'), true)) { return; }
        if ($pagenow === 'admin.php' && isset($_GET['page']) &&
            sanitize_key(wp_unslash($_GET['page'])) === 'dendrila-simple-edit') {
            delete_option(self::REDIRECT_OPTION);
            return;
        }
        delete_option(self::REDIRECT_OPTION);
        wp_safe_redirect(admin_url('admin.php?page=dendrila-simple-edit&dendrila_simple_edit_setup_step=1'));
        exit;
    }

    public static function restart()
    {
        if (!self::can_configure()) {
            wp_die(esc_html__('Accès refusé.', 'dendrila-simple-edit'), '', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_setup_restart');
        // Explicit manual review: do not mark an already configured plugin as
        // unconfigured if the administrator leaves the wizard unfinished.
        update_option(self::REVIEW_OPTION, '1', false);
        update_option(self::STEP_OPTION, 1, false);
        delete_option(self::REDIRECT_OPTION);
        wp_safe_redirect(admin_url('admin.php?page=dendrila-simple-edit&dendrila_simple_edit_setup_step=1'));
        exit;
    }

    public static function notice()
    {
        if (self::completed() || !self::can_configure() ||
            (isset($_GET['page']) && $_GET['page'] === 'dendrila-simple-edit')) { return; }
        echo '<div class="notice notice-warning"><p>' .
            esc_html__('Dendrila Simple Edit doit être configuré avant utilisation.', 'dendrila-simple-edit') .
            ' <a href="' . esc_url(admin_url('admin.php?page=dendrila-simple-edit')) . '">' .
            esc_html__('Ouvrir l’assistant', 'dendrila-simple-edit') . '</a></p></div>';
    }

    private static function step()
    {
        $saved = (int) get_option(self::STEP_OPTION, 1);
        $requested = isset($_GET['dendrila_simple_edit_setup_step']) ? absint($_GET['dendrila_simple_edit_setup_step']) : $saved;
        return max(1, min(5, min($requested, max(1, $saved))));
    }

    private static function redirect($step)
    {
        wp_safe_redirect(add_query_arg('dendrila_simple_edit_setup_step', max(1,min(5,$step)),
            admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function save()
    {
        if (!self::can_configure()) { wp_die('Accès refusé.', 'Accès refusé', array('response'=>403)); }
        check_admin_referer('dendrila_simple_edit_setup_save');
        if (isset($_POST['dendrila_simple_edit_setup_cancel']) && self::reviewing()) {
            delete_option(self::REVIEW_OPTION);
            wp_safe_redirect(admin_url('admin.php?page=dendrila-simple-edit&dendrila_simple_edit_tab=reglages'));
            exit;
        }
        $step = isset($_POST['dendrila_simple_edit_setup_step']) ? absint($_POST['dendrila_simple_edit_setup_step']) : 0;
        $highest = (int) get_option(self::STEP_OPTION, 1);
        if ($step < 1 || $step > 5 || $step > $highest) { wp_die('Étape invalide.', 'Configuration',array('response'=>400)); }
        if (isset($_POST['dendrila_simple_edit_setup_back'])) { self::redirect($step - 1); }
        if ($step === 1) {
            $defs = Dendrila_Simple_Edit_Modules::definitions();
            $active = isset($_POST['dendrila_simple_edit_setup_modules']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_setup_modules']), 'sanitize_text_field') : array();
            if (!is_array($active) || array_diff($active, array_keys($defs))) { wp_die('Modules invalides.'); }
            if (in_array('newsletters', $active, true) && !Dendrila_Simple_Edit_Mailing::fluent_smtp_active()) {
                wp_die(esc_html__('Le module Newsletters nécessite FluentSMTP installé et activé. Activez FluentSMTP dans Extensions, puis reprenez cette étape.', 'dendrila-simple-edit'), esc_html__('FluentSMTP nécessaire', 'dendrila-simple-edit'), array('response'=>400));
            }
            $settings = Dendrila_Simple_Edit_Modules::settings();
            foreach ($defs as $slug => $definition) {
                if (!isset($settings[$slug]) || !is_array($settings[$slug])) { $settings[$slug] = array(); }
                $settings[$slug]['enabled'] = in_array($slug, $active, true);
                if (!isset($settings[$slug]['roles'])) { $settings[$slug]['roles'] = array(); }
            }
            update_option(Dendrila_Simple_Edit_Modules::OPTION, $settings, false);
        } elseif ($step === 2) {
            $defs = Dendrila_Simple_Edit_Modules::definitions();
            $submitted = isset($_POST['dendrila_simple_edit_setup_permissions']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_setup_permissions']), 'sanitize_text_field') : array();
            if (!is_array($submitted) || count($submitted) > 30) { wp_die('Autorisations invalides.'); }
            $registered = wp_roles()->get_names();
            $settings = Dendrila_Simple_Edit_Modules::settings();
            foreach ($defs as $mod => $definition) {
                $settings[$mod]['roles'] = array();
                $per_role = isset($submitted[$mod]) ? $submitted[$mod] : array();
                if (!is_array($per_role)) { wp_die('Rôle invalide.'); }
                foreach ($per_role as $role => $actions) {
                    if (!is_string($role) || !isset($registered[$role]) || $role === 'administrator' ||
                        !is_array($actions) || array_diff($actions, array_keys($definition['actions']))) {
                        wp_die('Permissions invalides.', 'Configuration',array('response'=>400));
                    }
                    $settings[$mod]['roles'][$role] = array_values(array_unique(array_map('sanitize_key', $actions)));
                }
            }
            update_option(Dendrila_Simple_Edit_Modules::OPTION, $settings, false);
        } elseif ($step === 3) {
            $types = isset($_POST['dendrila_simple_edit_setup_types']) ?
                map_deep(wp_unslash($_POST['dendrila_simple_edit_setup_types']), 'sanitize_key') : array();
            if (!Dendrila_Simple_Edit_Post_Editor::save_types($types)) {
                wp_die('Types de contenus invalides.', 'Configuration', array('response'=>400));
            }
        } elseif ($step === 4) {
            $list = isset($_POST['dendrila_simple_edit_woo_list']) ? absint($_POST['dendrila_simple_edit_woo_list']) : 0;
            $new_name = isset($_POST['dendrila_simple_edit_setup_new_list']) &&
                is_string($_POST['dendrila_simple_edit_setup_new_list']) ?
                sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_setup_new_list'])) : '';
            $new_name = trim($new_name);
            if ($new_name !== '') {
                if (!Dendrila_Simple_Edit_Modules::enabled('newsletters') || strlen($new_name) > 160) {
                    wp_die('Nom de liste incorrect ou module Newsletters désactivé.', 'Configuration', array('response'=>400));
                }
                if (get_option(Dendrila_Simple_Edit_Mailing::DB_OPTION) !== Dendrila_Simple_Edit_Mailing::DB_VERSION) {
                    Dendrila_Simple_Edit_Mailing::install();
                }
                if (!Dendrila_Simple_Edit_Suppressions::ready() ||
                    get_option(Dendrila_Simple_Edit_Mailing::DB_OPTION) !== Dendrila_Simple_Edit_Mailing::DB_VERSION) {
                    wp_die('Le stockage des listes est indisponible : vérifiez la migration du mailing.',
                        'Configuration', array('response'=>503));
                }
                global $wpdb;
                $table = $wpdb->prefix . 'dendrila_simple_edit_ml_lists';
                $existing = (int)$wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM %i WHERE LOWER(name)=LOWER(%s) ORDER BY id ASC LIMIT 1', $table, $new_name));
                if ($existing) {
                    $list = $existing; // Prefer an existing list to a duplicate.
                } else {
                    $ok = $wpdb->insert($table, array('name'=>$new_name,'owner_user_id'=>0,
                        'created_at'=>current_time('mysql',true)), array('%s','%d','%s'));
                    if (!$ok || !$wpdb->insert_id) { wp_die('Création de la liste impossible.', 'Configuration', array('response'=>500)); }
                    $list = (int)$wpdb->insert_id;
                    if (function_exists('wp_cache_delete')) {
                        wp_cache_delete('woo_mailing_lists', 'dendrila_simple_edit');
                        wp_cache_delete('mailing_admin_lists', 'dendrila_simple_edit');
                    }
                }
            }
            if ($list && !Dendrila_Simple_Edit_Woo_Mailing::valid_list($list)) { wp_die('Liste introuvable.'); }
            if (!empty($_POST['dendrila_simple_edit_woo_optin']) && !$list) {
                wp_die('Choisissez ou créez une liste pour activer l’inscription WooCommerce.', 'Configuration', array('response'=>400));
            }
            update_option(Dendrila_Simple_Edit_Woo_Mailing::OPTION, array('list_id'=>$list,
                'enabled'=>(bool)($list && !empty($_POST['dendrila_simple_edit_woo_optin']))), false);
        } elseif ($step === 5) {
            if (Dendrila_Simple_Edit_Modules::enabled('newsletters') &&
                !Dendrila_Simple_Edit_Mailing::transport_ready()) {
                wp_die(esc_html__('FluentSMTP doit être actif et posséder une connexion e-mail configurée avant de terminer la configuration du module Newsletters.', 'dendrila-simple-edit'), esc_html__('Connexion FluentSMTP nécessaire', 'dendrila-simple-edit'), array('response'=>400));
            }
            if (Dendrila_Simple_Edit_Modules::enabled('newsletters') &&
                !Dendrila_Simple_Edit_Suppressions::ready() &&
                !Dendrila_Simple_Edit_Suppressions::migrate()) {
                wp_die('Migration des désinscriptions incomplète : aucune campagne n’est autorisée.');
            }
            update_option(self::OPTION, '1', false);
            delete_option(self::REVIEW_OPTION);
            delete_option(self::REDIRECT_OPTION);
            wp_safe_redirect(admin_url('admin.php?page=dendrila-simple-edit'));
            exit;
        }
        update_option(self::STEP_OPTION, max($highest, $step + 1), false);
        self::redirect($step + 1);
    }

    public static function render()
    {
        if (!self::can_configure()) {
            echo '<div class="wrap dendrila-simple-edit-wrap"><h1>' . esc_html__('Configuration en cours', 'dendrila-simple-edit') . '</h1><p>' . esc_html__('Un administrateur doit terminer la configuration de Dendrila Simple Edit avant son utilisation.', 'dendrila-simple-edit') . '</p></div>';
            return;
        }
        $step = self::step();
        $labels = array(1=>__('Modules', 'dendrila-simple-edit'),2=>__('Permissions par rôle', 'dendrila-simple-edit'),3=>__('Types de contenus', 'dendrila-simple-edit'),4=>__('Mailing et WooCommerce', 'dendrila-simple-edit'),5=>__('Vérification', 'dendrila-simple-edit'));
        echo '<div class="wrap dendrila-simple-edit-wrap dendrila-simple-edit-setup"><h1>' . esc_html__('Configuration de Dendrila Simple Edit', 'dendrila-simple-edit') . '</h1>';
        echo '<p>Étape ' . (int)$step . ' sur 5 — ' . esc_html($labels[$step]) . '</p>';
        echo '<ol class="dendrila-simple-edit-setup-steps">';
        foreach ($labels as $number=>$label) {
            echo '<li' . ($number === $step ? ' aria-current="step"' : '') . '>' . esc_html($label) . '</li>';
        }
        echo '</ol><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dendrila-simple-edit-card">';
        wp_nonce_field('dendrila_simple_edit_setup_save');
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_setup_save">';
        echo '<input type="hidden" name="dendrila_simple_edit_setup_step" value="'.(int)$step.'">';
        $defs = Dendrila_Simple_Edit_Modules::definitions();
        $settings = Dendrila_Simple_Edit_Modules::settings();
        if ($step === 1) {
            echo '<h2>' . esc_html__('Activer uniquement les outils nécessaires', 'dendrila-simple-edit') . '</h2><p>' . esc_html__('Les fonctions désactivées resteront inaccessibles. Elles pourront être activées plus tard.', 'dendrila-simple-edit') . '</p>';
            echo '<p><strong>Newsletters :</strong> FluentSMTP doit être installé et actif. Une connexion doit être configurée avant le premier envoi. ';
            echo '<a href="' . esc_url(admin_url('plugin-install.php?s=FluentSMTP&tab=search&type=term')) . '">Rechercher FluentSMTP dans les extensions</a> · ';
            echo '<a href="' . esc_url(admin_url('options-general.php?page=fluent-mail')) . '">Ouvrir les réglages FluentSMTP</a>.</p>';
            if (!Dendrila_Simple_Edit_Mailing::fluent_smtp_active()) {
                echo '<p class="notice notice-warning inline">FluentSMTP est absent ou inactif. L’activation du module Newsletters est bloquée jusqu’à son activation.</p>';
            }
            foreach ($defs as $slug => $definition) {
                $missing_dependency = ($slug === 'newsletters' && !Dendrila_Simple_Edit_Mailing::fluent_smtp_active());
                echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_setup_modules[]" value="'.esc_attr($slug).'" '.
                    checked(!$missing_dependency && !empty($settings[$slug]['enabled']),true,false).
                    disabled($missing_dependency,true,false).'> '.esc_html($definition['label']).
                    ($missing_dependency ? ' — FluentSMTP requis' : '').'</label>';
            }
        } elseif ($step === 2) {
            echo '<h2>' . esc_html__('Définir les autorisations des rôles', 'dendrila-simple-edit') . '</h2><p>' . esc_html__('Les administrateurs conservent leurs droits natifs. Tout autre rôle est refusé par défaut.', 'dendrila-simple-edit') . '</p>';
            foreach ($defs as $slug=>$def) {
                if (empty($settings[$slug]['enabled'])) { continue; }
                echo '<details class="dendrila-simple-edit-setup-module" open><summary>'.esc_html($def['label']).'</summary>';
                foreach (wp_roles()->get_names() as $role=>$label) {
                    if ($role==='administrator') { continue; }
                    $enabled = (array)($settings[$slug]['roles'][$role] ?? array());
                    echo '<fieldset class="dendrila-simple-edit-permissions"><legend>'.esc_html(translate_user_role($label)).'</legend>';
                    echo '<button type="button" class="button" data-dendrila-simple-edit-select-all>' . esc_html__('Tout sélectionner', 'dendrila-simple-edit') . '</button> ';
                    echo '<button type="button" class="button" data-dendrila-simple-edit-select-none>' . esc_html__('Tout désélectionner', 'dendrila-simple-edit') . '</button>';
                    foreach ($def['actions'] as $action=>$desc) {
                        echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_setup_permissions['.
                            esc_attr($slug).']['.esc_attr($role).'][]" value="'.esc_attr($action).'" '.
                            checked(in_array($action,$enabled,true),true,false).'> '.esc_html($desc).'</label>';
                    }
                    echo '</fieldset>';
                }
                echo '</details>';
            }
        } elseif ($step === 3) {
            echo '<h2>' . esc_html__('Choisir les types de contenus modifiables', 'dendrila-simple-edit') . '</h2>';
            echo '<p>Ces choix s’appliquent aux rôles auxquels vous accordez le droit de modifier le type concerné. L’administrateur conserve tous ses droits. Aucun choix page par page n’est nécessaire.</p>';
            echo '<p class="description">Les pages ou articles complexes restent visibles, mais leur mise en page ne peut être réécrite avec l’éditeur simplifié.</p>';
            $types = Dendrila_Simple_Edit_Post_Editor::editable_types();
            foreach (array('page'=>__('Pages','dendrila-simple-edit'), 'post'=>__('Articles','dendrila-simple-edit'),
                'tribe_events'=>__('Événements','dendrila-simple-edit')) as $type=>$label) {
                $counts = wp_count_posts($type);
                $num = is_object($counts) ? (int)($counts->publish ?? 0) + (int)($counts->draft ?? 0) : 0;
                echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_setup_types[]" value="' . esc_attr($type) . '" ' .
                    checked(in_array($type,$types,true),true,false) . '> <strong>' . esc_html($label) . '</strong> (' . (int)$num . ')</label>';
            }
        } elseif ($step === 4) {
            echo '<h2>' . esc_html__('Courriels et inscription lors d’une commande', 'dendrila-simple-edit') . '</h2>';
            echo '<p>Le module Newsletters exige FluentSMTP installé, activé et configuré avec une connexion d’envoi. L’inscription marketing pendant la commande est facultative et décochée par défaut.</p>';
            echo '<p>État FluentSMTP : <strong>' . (Dendrila_Simple_Edit_Mailing::transport_ready() ? 'connexion détectée' : 'connexion manquante') . '</strong>. <a href="' . esc_url(admin_url('options-general.php?page=fluent-mail')) . '">Configurer FluentSMTP</a></p>';
            echo '<p>' . esc_html__('Les désinscriptions historiques doivent être migrées avant le premier envoi. Le module refuse les campagnes si cette protection échoue.', 'dendrila-simple-edit') . '</p>';
            $current = Dendrila_Simple_Edit_Woo_Mailing::settings();
            if (Dendrila_Simple_Edit_Modules::enabled('newsletters')) {
                echo '<fieldset class="dendrila-simple-edit-permissions"><legend>Créer une liste de diffusion</legend>';
                echo '<p>Vous pouvez créer une liste ici. Elle sera conservée pour le mailing et sélectionnée automatiquement à l’étape suivante, sans quitter l’assistant.</p>';
                echo '<label>Nom de la nouvelle liste <input type="text" name="dendrila_simple_edit_setup_new_list" maxlength="160" placeholder="Ex. Actualités du site"></label>';
                echo '<p class="description">Laissez vide pour utiliser une liste existante. Les doublons de nom sont évités.</p></fieldset>';
            }
            echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_woo_optin" value="1" '.checked(!empty($current['enabled']),true,false).'> Proposer une inscription à la newsletter lors du paiement WooCommerce</label>';
            echo '<p><label>Liste de destination <select name="dendrila_simple_edit_woo_list"><option value="0">Ne pas connecter</option>';
            foreach (Dendrila_Simple_Edit_Woo_Mailing::lists() as $list) {
                echo '<option value="'.(int)$list->id.'" '.selected((int)$current['list_id'], (int)$list->id,false).'>'.esc_html($list->name).'</option>';
            }
            echo '</select></label></p>';
        } else {
            echo '<h2>' . esc_html__('Vérification finale', 'dendrila-simple-edit') . '</h2><p>' . esc_html__('Après validation, l’espace d’édition est accessible aux rôles autorisés.', 'dendrila-simple-edit') . '</p>';
            echo '<p>Désinscriptions historiques : <strong>'.(Dendrila_Simple_Edit_Suppressions::ready()?'Contrôlées':'Migration à effectuer avant activation du mailing').'</strong></p>';
            if (Dendrila_Simple_Edit_Modules::enabled('newsletters')) {
                echo '<p>FluentSMTP : <strong>' . (Dendrila_Simple_Edit_Mailing::transport_ready() ? 'prêt' : 'inactif ou non configuré — veuillez corriger avant validation') . '</strong></p>';
            }
            echo '<p>' . esc_html__('Les modifications ultérieures se font depuis les réglages du plugin.', 'dendrila-simple-edit') . '</p>';
        }
        echo '<div class="dendrila-simple-edit-setup-actions">';
        if (self::reviewing()) { echo '<button type="submit" name="dendrila_simple_edit_setup_cancel" value="1" class="button button-secondary" formnovalidate>' . esc_html__('Quitter l’assistant', 'dendrila-simple-edit') . '</button>'; }
        if ($step>1) { echo '<button type="submit" name="dendrila_simple_edit_setup_back" value="1" class="button button-secondary" formnovalidate>Retour</button>'; }
        echo '<button type="submit" class="button button-primary">'.($step===5?'Terminer la configuration':'Continuer').' </button></div>';
        echo '</form><footer class="dendrila-simple-edit-support"><a class="dendrila-simple-edit-support-link" href="https://app.lepotager.org/soutenir/" target="_blank" rel="noopener noreferrer">☕ Offrez un café au développeur</a></footer></div>';
    }
}
