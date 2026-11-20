<?php
/**
 * Internal editorial drafts, independent from mailing lists and delivery engines.
 * This module never touches MailPoet storage or dispatches any email.
 */
if (!defined('ABSPATH')) { exit; }

final class DSE_Newsletters
{
    const TYPE = 'dse_newsletter';
    const APPROVED = '_dse_newsletter_approval';

    public static function boot()
    {
        add_action('init', array(__CLASS__, 'register'));
        add_action('admin_post_dse_newsletter_save', array(__CLASS__, 'save'));
        add_action('admin_post_dse_newsletter_review', array(__CLASS__, 'request_review'));
        add_action('admin_post_dse_newsletter_approve', array(__CLASS__, 'approve'));
    }

    public static function register()
    {
        register_post_type(self::TYPE, array(
            'label' => 'Brouillons de newsletters',
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title', 'editor', 'author'),
            'capability_type' => 'dse_newsletter',
            'map_meta_cap' => false,
            'capabilities' => array(
                'edit_posts' => 'do_not_allow',
                'edit_others_posts' => 'do_not_allow',
                'publish_posts' => 'do_not_allow',
                'read_private_posts' => 'do_not_allow',
                'delete_posts' => 'do_not_allow',
                'create_posts' => 'do_not_allow',
            ),
        ));
    }

    public static function fingerprint($post)
    {
        return hash('sha256', (string) $post->post_title . "\n" . (string) $post->post_content .
            "\n" . (string) $post->post_status . "\n" . (string) $post->post_modified_gmt);
    }

    private static function get_draft($id)
    {
        if (!is_int($id) || $id < 1) { return false; }
        $post = get_post($id);
        return $post && $post->post_type === self::TYPE &&
            in_array($post->post_status, array('draft', 'pending'), true) ? $post : false;
    }

    private static function post_id()
    {
        $raw = isset($_POST['dse_nid']) ? wp_unslash($_POST['dse_nid']) : '';
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    }

    private static function redirect($notice)
    {
        wp_safe_redirect(add_query_arg('dse_nnotice', $notice, admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function save()
    {
        check_admin_referer('dse_newsletter_save');
        $id = self::post_id();
        $action = $id ? 'edit' : 'create';
        if (!DSE_Modules::allowed('newsletters', $action)) {
            wp_die('Création ou modification non autorisée.', 'Accès refusé', array('response' => 403));
        }
        $title = isset($_POST['dse_nsubject']) ? wp_unslash($_POST['dse_nsubject']) : null;
        $body = isset($_POST['dse_nbody']) ? wp_unslash($_POST['dse_nbody']) : null;
        if (!is_string($title) || !is_string($body) ||
            strlen($title) > 640 || strlen($body) > 40000) {
            wp_die('Format ou taille de newsletter incorrect.', 'Requête incorrecte', array('response' => 400));
        }
        $title = sanitize_text_field($title);
        $body = sanitize_textarea_field($body);
        if ($title === '' || strlen($title) > 640 || strlen($body) > 40000) {
            wp_die('Objet obligatoire ou contenu trop long.', 'Requête incorrecte', array('response' => 400));
        }
        if ($id) {
            $post = self::get_draft($id);
            $expected = isset($_POST['dse_nversion']) ? wp_unslash($_POST['dse_nversion']) : '';
            if (!$post || !is_string($expected) || !hash_equals(self::fingerprint($post), $expected)) {
                self::redirect('conflict');
            }
            $result = wp_update_post(array(
                'ID' => $id,
                'post_title' => $title,
                'post_content' => $body,
                'post_status' => 'draft',
            ), true);
            if (is_wp_error($result) || !$result) { self::redirect('error'); }
            // A revised draft must be approved anew.
            delete_post_meta($id, self::APPROVED);
        } else {
            $result = wp_insert_post(array(
                'post_type' => self::TYPE,
                'post_status' => 'draft',
                'post_author' => get_current_user_id(),
                'post_title' => $title,
                'post_content' => $body,
            ), true);
            if (is_wp_error($result) || !$result) { self::redirect('error'); }
        }
        self::redirect('saved');
    }

    public static function request_review()
    {
        check_admin_referer('dse_newsletter_review');
        if (!DSE_Modules::allowed('newsletters', 'review')) {
            wp_die('Validation non autorisée.', 'Accès refusé', array('response' => 403));
        }
        $post = self::get_draft(self::post_id());
        $expected = isset($_POST['dse_nversion']) ? wp_unslash($_POST['dse_nversion']) : '';
        if (!$post || !is_string($expected) || !hash_equals(self::fingerprint($post), $expected)) {
            self::redirect('conflict');
        }
        $result = wp_update_post(array('ID' => $post->ID, 'post_status' => 'pending'), true);
        if (is_wp_error($result) || !$result) { self::redirect('error'); }
        delete_post_meta($post->ID, self::APPROVED);
        self::redirect('review');
    }

    public static function approve()
    {
        check_admin_referer('dse_newsletter_approve');
        if (!DSE_Modules::allowed('newsletters', 'approve')) {
            wp_die('Approbation non autorisée.', 'Accès refusé', array('response' => 403));
        }
        $post = self::get_draft(self::post_id());
        $expected = isset($_POST['dse_nversion']) ? wp_unslash($_POST['dse_nversion']) : '';
        if (!$post || $post->post_status !== 'pending' || !is_string($expected) ||
            !hash_equals(self::fingerprint($post), $expected)) {
            self::redirect('conflict');
        }
        update_post_meta($post->ID, self::APPROVED, self::fingerprint($post));
        self::redirect('approved');
    }

    public static function render()
    {
        if (!DSE_Modules::enabled('newsletters')) { return; }
        $may_create = DSE_Modules::allowed('newsletters', 'create');
        $may_edit = DSE_Modules::allowed('newsletters', 'edit');
        $may_review = DSE_Modules::allowed('newsletters', 'review');
        $may_approve = DSE_Modules::allowed('newsletters', 'approve');
        if (!$may_create && !$may_edit && !$may_review && !$may_approve) { return; }
        echo '<section class="dse-card"><h2>Newsletters — préparation</h2>';
        echo '<p>Les textes restent des brouillons privés de ce site. Aucun abonné, aucune liste, aucun envoi ou programmation n’est accessible.</p>';
        $notices = array('saved' => 'Brouillon enregistré.', 'review' => 'Contenu soumis à validation.',
            'approved' => 'Contenu validé. Aucun e-mail envoyé.', 'conflict' => 'Le texte a changé. Rechargez cette page.',
            'error' => 'Enregistrement non confirmé.');
        $notice = isset($_GET['dse_nnotice']) ? wp_unslash($_GET['dse_nnotice']) : '';
        if (is_string($notice) && isset($notices[$notice])) {
            echo '<p role="status">' . esc_html($notices[$notice]) . '</p>';
        }
        if ($may_create) {
            self::form(null);
        }
        $posts = get_posts(array('post_type' => self::TYPE, 'post_status' => array('draft', 'pending'),
            'posts_per_page' => 30, 'orderby' => 'modified', 'order' => 'DESC'));
        foreach ($posts as $post) {
            if (!$post || !self::get_draft((int) $post->ID)) { continue; }
            $hash = self::fingerprint($post);
            $approved = $post->post_status === 'pending' &&
                hash_equals($hash, (string) get_post_meta($post->ID, self::APPROVED, true));
            echo '<article class="dse-subcard"><h3>' . esc_html($post->post_title) . '</h3>';
            echo '<p>Statut : ' . ($approved ? 'Validée, non envoyée' :
                ($post->post_status === 'pending' ? 'En attente de validation' : 'Brouillon')) . '</p>';
            echo '<details><summary>Prévisualiser le contenu</summary><div class="dse-preview"><strong>' .
                esc_html($post->post_title) . '</strong><p>' .
                nl2br(esc_html($post->post_content), false) . '</p></div></details>';
            if ($may_edit) { self::form($post); }
            if ($may_review && $post->post_status === 'draft') {
                self::action_form('dse_newsletter_review', 'dse_newsletter_review',
                    'Soumettre à validation', $post, $hash);
            }
            if ($may_approve && $post->post_status === 'pending' && !$approved) {
                self::action_form('dse_newsletter_approve', 'dse_newsletter_approve',
                    'Valider ce contenu', $post, $hash);
            }
            echo '</article>';
        }
        echo '</section>';
    }

    private static function form($post)
    {
        $existing = $post !== null;
        echo '<form class="dse-subcard" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h3>' . ($existing ? 'Modifier le brouillon' : 'Nouvelle newsletter') . '</h3>';
        echo '<input type="hidden" name="action" value="dse_newsletter_save">';
        if ($existing) {
            echo '<input type="hidden" name="dse_nid" value="' . esc_attr((string) $post->ID) . '">';
            echo '<input type="hidden" name="dse_nversion" value="' .
                esc_attr(self::fingerprint($post)) . '">';
        }
        wp_nonce_field('dse_newsletter_save');
        $suffix = $existing ? (string) $post->ID : 'new';
        echo '<div class="dse-field"><label for="dse-subject-' . esc_attr($suffix) .
            '">Objet de la newsletter</label><input id="dse-subject-' . esc_attr($suffix) .
            '" type="text" name="dse_nsubject" required maxlength="160" value="' .
            esc_attr($existing ? $post->post_title : '') . '"></div>';
        echo '<div class="dse-field"><label for="dse-body-' . esc_attr($suffix) .
            '">Contenu (texte simple)</label><textarea id="dse-body-' . esc_attr($suffix) .
            '" name="dse_nbody" rows="5" maxlength="20000">' .
            esc_textarea($existing ? $post->post_content : '') . '</textarea></div>';
        echo '<p><button type="submit" class="button button-secondary">Enregistrer le brouillon</button></p></form>';
    }

    private static function action_form($action, $nonce, $button, $post, $hash)
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="dse_nid" value="' . esc_attr((string) $post->ID) . '">';
        echo '<input type="hidden" name="dse_nversion" value="' . esc_attr($hash) . '">';
        wp_nonce_field($nonce);
        echo '<p><button type="submit" class="button">' . esc_html($button) . '</button></p></form>';
    }
}
