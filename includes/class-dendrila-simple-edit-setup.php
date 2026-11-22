<?php
/** Guided first-run setup for plugin activators and network administrators. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Setup
{
    const OPTION = 'dendrila_simple_edit_setup_completed_v1';
    const STEP_OPTION = 'dendrila_simple_edit_setup_step_v1';

    public static function completed()
    {
        return get_option(self::OPTION, '0') === '1';
    }

    public static function can_configure()
    {
        return current_user_can('manage_options') || current_user_can('activate_plugins') ||
            current_user_can('install_plugins') || (function_exists('is_super_admin') && is_super_admin());
    }

    public static function on_activate()
    {
        if (get_option(self::OPTION, null) === null) { add_option(self::OPTION, '0', '', false); }
        if (!self::completed()) { update_option(self::STEP_OPTION, 1, false); }
    }

    public static function boot()
    {
        add_action('admin_init', array(__CLASS__, 'after_activation'), 5);
        add_action('admin_post_dendrila_simple_edit_setup_save', array(__CLASS__, 'save'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function after_activation()
    {
        if (self::completed() || !self::can_configure() || !isset($_GET['activate'])) { return; }
        global $pagenow;
        if ($pagenow !== 'plugins.php') { return; }
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
        $step = isset($_POST['dendrila_simple_edit_setup_step']) ? absint($_POST['dendrila_simple_edit_setup_step']) : 0;
        $highest = (int) get_option(self::STEP_OPTION, 1);
        if ($step < 1 || $step > 5 || $step > $highest) { wp_die('Étape invalide.', 'Configuration',array('response'=>400)); }
        if (isset($_POST['dendrila_simple_edit_setup_back'])) { self::redirect($step - 1); }
        if ($step === 1) {
            $defs = Dendrila_Simple_Edit_Modules::definitions();
            $active = isset($_POST['dendrila_simple_edit_setup_modules']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_setup_modules']), 'sanitize_text_field') : array();
            if (!is_array($active) || array_diff($active, array_keys($defs))) { wp_die('Modules invalides.'); }
            if (in_array('newsletters', $active, true) && !Dendrila_Simple_Edit_Mailing::fluent_smtp_active()) {
                wp_die(__('Le module Newsletters nécessite FluentSMTP installé et activé. Activez FluentSMTP dans Extensions, puis reprenez cette étape.', 'dendrila-simple-edit'), __('FluentSMTP nécessaire', 'dendrila-simple-edit'), array('response'=>400));
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
            $ids = isset($_POST['dendrila_simple_edit_setup_post_ids']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_setup_post_ids']), 'sanitize_text_field') : array();
            if (!is_array($ids) || count($ids) > 200) { wp_die('Contenus invalides.'); }
            $clean = array();
            foreach ($ids as $id) {
                if (!is_string($id) || !ctype_digit($id)) { wp_die('Identifiant de contenu invalide.'); }
                $post = get_post((int)$id);
                if ($post && in_array($post->post_type, array('page','post'), true) &&
                    $post->post_status === 'publish' && $post->post_password === '' &&
                    Dendrila_Simple_Edit_Post_Editor::is_layout_safe($post)) { $clean[] = (int)$id; }
            }
            update_option(Dendrila_Simple_Edit_Post_Editor::OPTION, array_values(array_unique($clean)), false);
        } elseif ($step === 4) {
            $list = isset($_POST['dendrila_simple_edit_woo_list']) ? absint($_POST['dendrila_simple_edit_woo_list']) : 0;
            if ($list && !Dendrila_Simple_Edit_Woo_Mailing::valid_list($list)) { wp_die('Liste introuvable.'); }
            update_option(Dendrila_Simple_Edit_Woo_Mailing::OPTION, array('list_id'=>$list,
                'enabled'=>$list && !empty($_POST['dendrila_simple_edit_woo_optin'])), false);
        } elseif ($step === 5) {
            if (Dendrila_Simple_Edit_Modules::enabled('newsletters') &&
                !Dendrila_Simple_Edit_Mailing::transport_ready()) {
                wp_die(__('FluentSMTP doit être actif et posséder une connexion e-mail configurée avant de terminer la configuration du module Newsletters.', 'dendrila-simple-edit'), __('Connexion FluentSMTP nécessaire', 'dendrila-simple-edit'), array('response'=>400));
            }
            if (Dendrila_Simple_Edit_Modules::enabled('newsletters') &&
                !Dendrila_Simple_Edit_Suppressions::ready() &&
                !Dendrila_Simple_Edit_Suppressions::migrate()) {
                wp_die('Migration des désinscriptions incomplète : aucune campagne n’est autorisée.');
            }
            update_option(self::OPTION, '1', false);
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
        $labels = array(1=>__('Modules', 'dendrila-simple-edit'),2=>__('Permissions par rôle', 'dendrila-simple-edit'),3=>__('Contenus accessibles', 'dendrila-simple-edit'),4=>__('Mailing et WooCommerce', 'dendrila-simple-edit'),5=>__('Vérification', 'dendrila-simple-edit'));
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
            echo '<h2>' . esc_html__('Choisir les pages et articles modifiables', 'dendrila-simple-edit') . '</h2><p>' . esc_html__('Les mises en page complexes restent protégées. Ne cochez que les contenus dont l’édition est volontairement déléguée.', 'dendrila-simple-edit') . '</p>';
            $ids = Dendrila_Simple_Edit_Post_Editor::allowed_ids();
            foreach (array('page'=>__('Pages', 'dendrila-simple-edit'),'post'=>__('Articles', 'dendrila-simple-edit')) as $type=>$label) {
                echo '<fieldset class="dendrila-simple-edit-permissions"><legend>'.esc_html($label).'</legend>';
                $posts = get_posts(array('post_type'=>$type,'post_status'=>'publish','posts_per_page'=>150));
                foreach ($posts as $post) {
                    if ($post->post_password !== '' || !Dendrila_Simple_Edit_Post_Editor::is_layout_safe($post)) { continue; }
                    echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_setup_post_ids[]" value="'.(int)$post->ID.'" '.
                        checked(in_array((int)$post->ID,$ids,true),true,false).'> '.esc_html($post->post_title).'</label>';
                }
                echo '</fieldset>';
            }
        } elseif ($step === 4) {
            echo '<h2>' . esc_html__('Courriels et inscription lors d’une commande', 'dendrila-simple-edit') . '</h2>';
            echo '<p>Le module Newsletters exige FluentSMTP installé, activé et configuré avec une connexion d’envoi. L’inscription marketing pendant la commande est facultative et décochée par défaut.</p>';
            echo '<p>État FluentSMTP : <strong>' . (Dendrila_Simple_Edit_Mailing::transport_ready() ? 'connexion détectée' : 'connexion manquante') . '</strong>. <a href="' . esc_url(admin_url('options-general.php?page=fluent-mail')) . '">Configurer FluentSMTP</a></p>';
            echo '<p>' . esc_html__('Les désinscriptions historiques doivent être migrées avant le premier envoi. Le module refuse les campagnes si cette protection échoue.', 'dendrila-simple-edit') . '</p>';
            $current = Dendrila_Simple_Edit_Woo_Mailing::settings();
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
        if ($step>1) { echo '<button type="submit" name="dendrila_simple_edit_setup_back" value="1" class="button button-secondary" formnovalidate>Retour</button>'; }
        echo '<button type="submit" class="button button-primary">'.($step===5?'Terminer la configuration':'Continuer').' </button></div>';
        echo '</form><footer class="dendrila-simple-edit-support"><a class="dendrila-simple-edit-support-link" href="https://app.lepotager.org/soutenir/" target="_blank" rel="noopener noreferrer">☕ Offrez un café au développeur</a></footer></div>';
    }
}
