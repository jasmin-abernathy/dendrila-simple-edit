<?php
/** Approval queue for delegated edits; no core editing caps are granted. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Approvals
{
    const OPTION = 'dendrila_simple_edit_approval_settings';
    const PENDING = '_dendrila_simple_edit_pending_content';

    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_approval_configure', array(__CLASS__, 'configure'));
        add_action('admin_post_dendrila_simple_edit_approve_content', array(__CLASS__, 'decide'));
    }

    public static function settings()
    {
        $value = get_option(self::OPTION, array());
        return is_array($value) ? $value : array();
    }

    public static function required($kind)
    {
        if (!in_array($kind, array('page', 'post', 'newsletter', 'product'), true)) { return false; }
        $settings = self::settings();
        return !empty($settings['require_' . $kind]);
    }

    public static function can_approve($kind)
    {
        if (!self::required($kind)) { return false; }
        if (current_user_can('manage_options') || (function_exists('is_super_admin') && is_super_admin())) {
            return true;
        }
        if (!current_user_can('read')) { return false; }
        $settings = self::settings();
        $roles = isset($settings['roles']) && is_array($settings['roles']) ? $settings['roles'] : array();
        $current = wp_get_current_user();
        return $current && (bool) array_intersect((array) $current->roles, $roles);
    }

    public static function is_reviewer()
    {
        foreach (array('page', 'post', 'newsletter', 'product') as $kind) {
            if (self::can_approve($kind)) { return true; }
        }
        return false;
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Réservé à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_approval_configure');
        $submitted = isset($_POST['dendrila_simple_edit_approval_roles']) ?
            map_deep(wp_unslash($_POST['dendrila_simple_edit_approval_roles']), 'sanitize_text_field') : array();
        if (!is_array($submitted) || count($submitted) > 50) {
            wp_die('Rôles invalides.', 'Requête incorrecte', array('response' => 400));
        }
        $registered = wp_roles()->get_names();
        $roles = array();
        foreach ($submitted as $role) {
            if (!is_string($role) || !isset($registered[$role]) || $role === 'administrator') {
                wp_die('Rôle non reconnu.', 'Requête incorrecte', array('response' => 400));
            }
            $roles[] = $role;
        }
        $settings = array('roles' => array_values(array_unique($roles)));
        foreach (array('page', 'post', 'newsletter', 'product') as $kind) {
            $settings['require_' . $kind] =
                isset($_POST['dendrila_simple_edit_approval_' . $kind]) &&
                $_POST['dendrila_simple_edit_approval_' . $kind] === '1';
        }
        update_option(self::OPTION, $settings, false);
        wp_safe_redirect(add_query_arg('dendrila_simple_edit_tab', 'validations',
            admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    /** Store a proposal without altering the published page or article. */
    public static function submit($post, $title, $body, $target_status = null, $image = null)
    {
        if (!$post || !in_array($post->post_type, array('page','post'), true) ||
            !self::required($post->post_type) || !is_string($title) || !is_string($body)) {
            return false;
        }
        $status = $target_status === 'publish' ? 'publish' : $post->post_status;
        if (!in_array($status, array('draft','publish'), true)) { return false; }
        $proposal = array(
            'origin' => Dendrila_Simple_Edit_Post_Editor::fingerprint($post),
            'title' => $title,
            'body' => $body,
            'target_status' => $status,
            'image' => $post->post_type === 'post' && is_array($image) ? $image : null,
            'user' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        );
        $existing = get_post_meta($post->ID, self::PENDING, true);
        if ($existing === $proposal) { return true; }
        // WordPress unslashes metadata; Gutenberg block JSON needs its escaping preserved.
        return (bool) update_post_meta($post->ID, self::PENDING, wp_slash($proposal));
    }

    public static function decide()
    {
        if (!isset($_POST['dendrila_simple_edit_approval_post'])) {
            wp_die('Contenu manquant.', 'Requête incorrecte', array('response' => 400));
        }
        $id = absint(wp_unslash($_POST['dendrila_simple_edit_approval_post']));
        check_admin_referer('dendrila_simple_edit_approve_content_' . $id);
        $post = get_post($id);
        if (!$post || !self::can_approve($post->post_type)) {
            wp_die('Validation non autorisée.', 'Accès refusé', array('response' => 403));
        }
        $proposal = get_post_meta($id, self::PENDING, true);
        if (!is_array($proposal) || !isset($proposal['origin'], $proposal['title'], $proposal['body'], $proposal['target_status']) ||
            !is_string($proposal['origin']) || !hash_equals(
                Dendrila_Simple_Edit_Post_Editor::fingerprint($post), $proposal['origin'])) {
            self::redirect('conflict');
        }
        $decision = isset($_POST['dendrila_simple_edit_approval_decision']) ?
            sanitize_key(wp_unslash($_POST['dendrila_simple_edit_approval_decision'])) : '';
        if (!in_array($decision, array('approve', 'reject'), true)) {
            wp_die('Décision invalide.', 'Requête incorrecte', array('response' => 400));
        }
        if ($decision === 'approve') {
            if (!in_array($proposal['target_status'], array('draft','publish'), true) ||
                !Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($proposal['body']) ||
                !Dendrila_Simple_Edit_Post_Editor::is_layout_safe($post)) {
                self::redirect('invalid');
            }
            if ($post->post_type === 'post' && isset($proposal['image']) && is_array($proposal['image'])) {
                $image = $proposal['image'];
                if (!isset($image['id'], $image['alt'], $image['description']) ||
                    ($image['id'] && (!wp_attachment_is_image((int)$image['id']) || !$image['alt'] ||
                        ((int)$image['id'] !== (int)get_post_thumbnail_id($post) && !Dendrila_Simple_Edit_Media::allowed_image((int)$image['id']))))) {
                    self::redirect('invalid');
                }
            }
            $done = wp_update_post(array('ID' => $id,
                'post_title' => wp_slash($proposal['title']),
                'post_content' => wp_slash($proposal['body']),
                'post_status' => $proposal['target_status']), true);
            if (is_wp_error($done) || !$done) { self::redirect('error'); }
            if ($post->post_type === 'post' && isset($proposal['image']) && is_array($proposal['image'])) {
                Dendrila_Simple_Edit_Post_Editor::apply_illustration($id, $proposal['image']);
            }
            update_post_meta($id, '_dendrila_simple_edit_last_edit', time());
        }
        delete_post_meta($id, self::PENDING);
        self::redirect($decision === 'approve' ? 'approved' : 'rejected');
    }

    private static function redirect($notice)
    {
        wp_safe_redirect(add_query_arg(array(
            'dendrila_simple_edit_tab' => 'validations',
            'dendrila_simple_edit_approval_notice' => $notice,
        ), admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function render_settings()
    {
        if (!current_user_can('manage_options')) { return; }
        $settings = self::settings();
        echo '<section class="dendrila-simple-edit-card"><h3>Quand faut-il valider les contenus ?</h3>';
        echo '<p>Les administrateurs (y compris les super-administrateurs) peuvent toujours valider. ' .
            'Vous pouvez autoriser d’autres rôles WordPress sans leur donner les droits généraux de publication.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_approval_configure">';
        wp_nonce_field('dendrila_simple_edit_approval_configure');
        foreach (array('page' => __('Pages', 'dendrila-simple-edit'), 'post' => __('Articles', 'dendrila-simple-edit'), 'newsletter' => __('Newsletters', 'dendrila-simple-edit')) as $key => $label) {
            echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_approval_' .
                esc_attr($key) . '" value="1" ' . checked(self::required($key), true, false) . '> ' .
                'Demander une validation avant publication ou envoi : ' . esc_html($label) . '</label>';
        }
        $roles = isset($settings['roles']) && is_array($settings['roles']) ? $settings['roles'] : array();
        echo '<fieldset class="dendrila-simple-edit-permissions"><legend>Autres rôles autorisés à valider</legend>';
        foreach (wp_roles()->get_names() as $key => $name) {
            if ($key === 'administrator') { continue; }
            echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_approval_roles[]" value="' .
                esc_attr($key) . '" ' . checked(in_array($key, $roles, true), true, false) . '> ' .
                esc_html(translate_user_role($name)) . '</label>';
        }
        echo '</fieldset><p><button class="button button-primary" type="submit">Enregistrer la politique de validation</button></p></form></section>';
    }

    public static function render()
    {
        if (!self::is_reviewer()) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET navigation or notice; no state change.
        $message = isset($_GET['dendrila_simple_edit_approval_notice']) ?
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET navigation or notice; no state change.
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_approval_notice'])) : '';
        $notices = array('approved' => 'Modification validée et appliquée.',
            'rejected' => 'Demande refusée.', 'conflict' => 'Le contenu a changé : demande à examiner de nouveau.',
            'invalid' => 'Le contenu proposé n’est plus compatible.', 'error' => 'Erreur lors de la validation.');
        if (isset($notices[$message])) {
            echo '<p class="notice notice-info" role="status">' . esc_html($notices[$message]) . '</p>';
        }
        $posts = get_posts(array('post_type' => array('page','post'),
            'post_status' => array('publish', 'draft'), 'posts_per_page' => 100,
            'meta_key' => self::PENDING, 'orderby' => 'modified', 'order' => 'DESC'));
        echo '<section class="dendrila-simple-edit-card"><h3>Pages et articles en attente</h3>';
        $count = 0;
        foreach ($posts as $post) {
            if (!self::can_approve($post->post_type)) { continue; }
            $pending = get_post_meta($post->ID, self::PENDING, true);
            if (!is_array($pending) || !isset($pending['title'], $pending['body'])) { continue; }
            $count++;
            echo '<article class="dendrila-simple-edit-subcard"><h4>' . esc_html($pending['title']) .
                ' <small>(' . esc_html($post->post_type === 'page' ? 'page' : 'article') . ')</small></h4>';
            echo '<p>Demande déposée le ' . esc_html($pending['created_at'] ?? '') . '</p>';
            echo '<details><summary>Lire le contenu proposé</summary><div class="dendrila-simple-edit-preview">' .
                wp_kses_post($pending['body']) . '</div></details>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_approve_content">';
            echo '<input type="hidden" name="dendrila_simple_edit_approval_post" value="' . (int) $post->ID . '">';
            wp_nonce_field('dendrila_simple_edit_approve_content_' . $post->ID);
            echo '<button type="submit" name="dendrila_simple_edit_approval_decision" value="approve" class="button button-primary">Valider et appliquer</button> ';
            echo '<button type="submit" name="dendrila_simple_edit_approval_decision" value="reject" class="button">Refuser</button></form></article>';
        }
        if (!$count) { echo '<p>Aucune demande concernant les pages ou articles.</p>'; }
        echo '</section>';
        if (self::can_approve('newsletter')) {
            echo '<p>Pour valider les newsletters, ouvrez la rubrique <strong>Newsletters</strong> : les brouillons soumis y sont présentés.</p>';
        }
    }
}
