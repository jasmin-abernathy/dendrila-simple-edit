<?php
/**
 * Editorial drafts and validation; only explicitly authorized senders can deliver
 * through Dendrila_Simple_Edit_Mailing, which uses wp_mail() / FluentSMTP.
 */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Newsletters
{
    const TYPE = 'dendrila_simple_edit';
    const APPROVED = '_dendrila_simple_edit_newsletter_approval';

    public static function boot()
    {
        add_action('init', array(__CLASS__, 'register'));
        add_action('admin_post_dendrila_simple_edit_newsletter_save', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_newsletter_review', array(__CLASS__, 'request_review'));
        add_action('admin_post_dendrila_simple_edit_newsletter_approve', array(__CLASS__, 'approve'));
    }

    public static function register()
    {
        register_post_type(self::TYPE, array(
            'label' => __('Brouillons de newsletters', 'dendrila-simple-edit'),
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title', 'editor', 'author'),
            'capability_type' => 'dendrila_simple_edit_newsletter',
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

    private static function post_id($nonce_action)
    {
        check_admin_referer($nonce_action);
        $raw = isset($_POST['dendrila_simple_edit_nid']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nid'])) : '';
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    }

    /** A delegated sender can act on their own drafts, not anyone else's. */
    public static function can_send($post = null)
    {
        $allowed = Dendrila_Simple_Edit_Modules::allowed('newsletters', 'send');
        if (!$allowed) { return false; }
        if ($post === null || current_user_can('manage_options')) { return true; }
        return $post->post_type === self::TYPE &&
            (int) $post->post_author === get_current_user_id();
    }

    /** A sender skips review for their own drafts; administrators review others' drafts. */
    public static function can_prepare_for_send($post)
    {
        if (!$post || $post->post_type !== self::TYPE ||
            !in_array($post->post_status, array('draft', 'pending'), true) ||
            !self::can_send($post)) { return false; }
        if (!current_user_can('manage_options') ||
            (int) $post->post_author === get_current_user_id()) { return true; }
        $approved = (string) get_post_meta($post->ID, self::APPROVED, true);
        return $post->post_status === 'pending' && $approved !== '' &&
            hash_equals(self::fingerprint($post), $approved);
    }

    private static function visible_to_user($post)
    {
        if (!$post || $post->post_type !== self::TYPE) { return false; }
        return current_user_can('manage_options') ||
            (int) $post->post_author === get_current_user_id() ||
            ($post->post_status === 'pending' && Dendrila_Simple_Edit_Approvals::can_approve('newsletter'));
    }

    private static function redirect($notice, $post_id = 0)
    {
        $args = array('dendrila_simple_edit_nnotice' => $notice, 'dendrila_simple_edit_tab' => 'newsletters',
            'dendrila_simple_edit_nl_section' => 'rediger');
        if ($post_id > 0) { $args['dendrila_simple_edit_current_draft'] = (int) $post_id; }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function save()
    {
        check_admin_referer('dendrila_simple_edit_newsletter_save');
        $id = self::post_id('dendrila_simple_edit_newsletter_save');
        $action = $id ? 'edit' : 'create';
        if (!Dendrila_Simple_Edit_Modules::allowed('newsletters', $action) &&
            !Dendrila_Simple_Edit_Modules::allowed('newsletters', 'send')) {
            wp_die('Création ou modification non autorisée.', 'Accès refusé', array('response' => 403));
        }
        $title = isset($_POST['dendrila_simple_edit_nsubject']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nsubject'])) : null;
        $body = isset($_POST['dendrila_simple_edit_nbody']) ? wp_kses(wp_unslash($_POST['dendrila_simple_edit_nbody']), Dendrila_Simple_Edit_Block_Editor::allowed_tags(), array('http', 'https', 'mailto')) : null;
        if (!is_string($title) || !is_string($body) ||
            strlen($title) > 640 || strlen($body) > 40000) {
            wp_die('Format ou taille de newsletter incorrect.', 'Requête incorrecte', array('response' => 400));
        }
        $title = sanitize_text_field($title);
        $body = Dendrila_Simple_Edit_Block_Editor::clean($body);
        if ($title === '' || strlen($title) > 640 || strlen($body) > 40000) {
            wp_die('Objet obligatoire ou contenu trop long.', 'Requête incorrecte', array('response' => 400));
        }
        if ($id) {
            $post = self::get_draft($id);
            $expected = isset($_POST['dendrila_simple_edit_nversion']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nversion'])) : '';
            if (!$post || (!current_user_can('manage_options') &&
                (int) $post->post_author !== get_current_user_id())) {
                wp_die('Vous ne pouvez pas modifier ce brouillon.', 'Accès refusé', array('response' => 403));
            }
            if (!is_string($expected) || !hash_equals(self::fingerprint($post), $expected)) {
                self::redirect('conflict');
            }
            $result = wp_update_post(array(
                'ID' => $id,
                'post_title' => $title,
                'post_content' => wp_slash($body),
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
                'post_content' => wp_slash($body),
            ), true);
            if (is_wp_error($result) || !$result) { self::redirect('error'); }
        }
        $template = isset($_POST['dendrila_simple_edit_template']) ?
            map_deep(wp_unslash($_POST['dendrila_simple_edit_template']), 'sanitize_text_field') : array();
        Dendrila_Simple_Edit_Newsletter_Templates::save((int)$result,$template,
            !$id || isset($_POST['dendrila_simple_edit_template_set_default']));
        self::redirect('saved', (int) $result);
    }

    public static function request_review()
    {
        check_admin_referer('dendrila_simple_edit_newsletter_review');
        if (self::can_send()) {
            self::redirect('send_direct', self::post_id('dendrila_simple_edit_newsletter_review'));
        }
        if (!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'review')) {
            wp_die('Validation non autorisée.', 'Accès refusé', array('response' => 403));
        }
        $post = self::get_draft(self::post_id('dendrila_simple_edit_newsletter_review'));
        $expected = isset($_POST['dendrila_simple_edit_nversion']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nversion'])) : '';
        if (!$post || !self::visible_to_user($post)) {
            wp_die('Vous ne pouvez pas soumettre ce brouillon.', 'Accès refusé', array('response' => 403));
        }
        if (!is_string($expected) || !hash_equals(self::fingerprint($post), $expected)) {
            self::redirect('conflict');
        }
        $result = wp_update_post(array('ID' => $post->ID, 'post_status' => 'pending'), true);
        if (is_wp_error($result) || !$result) { self::redirect('error'); }
        delete_post_meta($post->ID, self::APPROVED);
        self::redirect('review', (int) $post->ID);
    }

    public static function approve()
    {
        check_admin_referer('dendrila_simple_edit_newsletter_approve');
        if (!Dendrila_Simple_Edit_Modules::allowed('newsletters', 'approve') &&
            !Dendrila_Simple_Edit_Approvals::can_approve('newsletter')) {
            wp_die('Approbation non autorisée.', 'Accès refusé', array('response' => 403));
        }
        $post = self::get_draft(self::post_id('dendrila_simple_edit_newsletter_approve'));
        $expected = isset($_POST['dendrila_simple_edit_nversion']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nversion'])) : '';
        if (!$post || !self::visible_to_user($post)) {
            wp_die('Validation de ce brouillon non autorisée.', 'Accès refusé', array('response' => 403));
        }
        if (!is_string($expected) || !hash_equals(self::fingerprint($post), $expected)) {
            self::redirect('conflict');
        }
        // One-click validation of an existing draft, with no review submission detour.
        if ($post->post_status === 'draft') {
            $result = wp_update_post(array('ID' => $post->ID, 'post_status' => 'pending'), true);
            if (is_wp_error($result) || !$result) { self::redirect('error'); }
            $post = get_post($post->ID);
        }
        update_post_meta($post->ID, self::APPROVED, self::fingerprint($post));
        self::redirect('approved', (int) $post->ID);
    }

    public static function render()
    {
        if (!Dendrila_Simple_Edit_Modules::enabled('newsletters')) { return; }
        $may_send = self::can_send();
        $may_create = $may_send || Dendrila_Simple_Edit_Modules::allowed('newsletters', 'create');
        $may_edit = $may_send || Dendrila_Simple_Edit_Modules::allowed('newsletters', 'edit');
        $may_review = Dendrila_Simple_Edit_Modules::allowed('newsletters', 'review');
        $may_approve = Dendrila_Simple_Edit_Modules::allowed('newsletters', 'approve') ||
            Dendrila_Simple_Edit_Approvals::can_approve('newsletter');
        $may_manage_lists = Dendrila_Simple_Edit_Modules::allowed('newsletters', 'manage_lists');
        if (!$may_create && !$may_edit && !$may_review && !$may_approve && !$may_send &&
            !$may_manage_lists) { return; }
        $admin = current_user_can('manage_options');
        $section = isset($_GET['dendrila_simple_edit_nl_section']) && is_string($_GET['dendrila_simple_edit_nl_section']) ?
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_nl_section'])) : 'rediger';
        $sections = array('rediger' => __('Rédiger les newsletters', 'dendrila-simple-edit'));
        if ($admin || $may_manage_lists) { $sections['listes'] = __('Listes et contacts', 'dendrila-simple-edit'); }
        if ($admin || $may_send) {
            $sections['envois'] = __('Préparer un envoi', 'dendrila-simple-edit');
            $sections['suivi'] = __('Suivre les envois', 'dendrila-simple-edit');
        }
        if ($admin) { $sections['connexion'] = __('Configuration des envois', 'dendrila-simple-edit');
            $sections['woocommerce'] = 'WooCommerce'; }
        if (!$admin && $may_manage_lists && !$may_create && !$may_edit &&
            !$may_review && !$may_approve && !$may_send &&
            $section === 'rediger') { $section = 'listes'; }
        if (!isset($sections[$section])) { $section = 'rediger'; }
        if (count($sections) > 1) {
            echo '<nav class="dendrila-simple-edit-newsletter-subnav" aria-label="Rubriques des newsletters">';
            foreach ($sections as $key => $label) {
                echo '<a class="dendrila-simple-edit-newsletter-subtab" href="' .
                    esc_url(add_query_arg(array('dendrila_simple_edit_tab' => 'newsletters', 'dendrila_simple_edit_nl_section' => $key),
                        admin_url('admin.php?page=dendrila-simple-edit'))) .
                    '"' . ($key === $section ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
            }
            echo '</nav>';
        }
        if ($section !== 'rediger') {
            if ($section === 'woocommerce') { Dendrila_Simple_Edit_Woo_Mailing::render_admin(); return; }
            Dendrila_Simple_Edit_Mailing::render_admin($section);
            return;
        }

        echo '<section class="dendrila-simple-edit-card dendrila-simple-edit-newsletter-list"><h2>Mes newsletters</h2>';
        echo '<p class="description">' . ($may_send ?
            'Préparez votre message, puis ouvrez « Préparer un envoi » pour choisir les destinataires. Aucune validation préalable n’est nécessaire avec votre autorisation d’envoi.' :
            'Les brouillons sont présentés du plus récent au plus ancien. Vous pouvez les soumettre à validation ou les valider directement si vous avez ce droit.') .
            '</p>';
        $notices = array('saved' => __('Brouillon enregistré.', 'dendrila-simple-edit'), 'review' => __('Contenu soumis à validation.', 'dendrila-simple-edit'),
            'approved' => __('Contenu validé. Aucun e-mail envoyé.', 'dendrila-simple-edit'),
            'send_direct' => 'Votre compte peut envoyer directement : ouvrez « Préparer un envoi ».', 'conflict' => 'Ce brouillon a changé. Rechargez la page.',
            'error' => __('Enregistrement non confirmé.', 'dendrila-simple-edit'));
        $notice = isset($_GET['dendrila_simple_edit_nnotice']) && is_string($_GET['dendrila_simple_edit_nnotice']) ?
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_nnotice'])) : '';
        if (isset($notices[$notice])) {
            echo '<p class="notice notice-info dendrila-simple-edit-newsletter-notice" role="status">' .
                esc_html($notices[$notice]) . '</p>';
        }
        $query = array('post_type' => self::TYPE, 'post_status' => array('draft', 'pending'),
            'posts_per_page' => 60, 'orderby' => array('modified' => 'DESC', 'ID' => 'DESC'));
        if (!$admin && !$may_approve) { $query['author'] = get_current_user_id(); }
        $posts = get_posts($query);
        $current = isset($_GET['dendrila_simple_edit_current_draft']) ? absint($_GET['dendrila_simple_edit_current_draft']) : 0;
        // A newly saved draft is always shown at the top, even if several saves
        // happened during the same second or the post was first created earlier.
        if ($current > 0) {
            foreach ($posts as $index => $candidate) {
                if ((int) $candidate->ID === $current) {
                    array_splice($posts, $index, 1);
                    array_unshift($posts, $candidate);
                    break;
                }
            }
        }
        if (!$posts) { echo '<p>' . esc_html__('Aucun brouillon enregistré pour le moment.', 'dendrila-simple-edit') . '</p>'; }
        foreach ($posts as $post) {
            if (!$post || !self::get_draft((int) $post->ID) ||
                !self::visible_to_user($post)) { continue; }
            $hash = self::fingerprint($post);
            $approved = $post->post_status === 'pending' &&
                hash_equals($hash, (string) get_post_meta($post->ID, self::APPROVED, true));
            $status = $may_send && self::can_prepare_for_send($post) ? __('Prête à préparer pour un envoi', 'dendrila-simple-edit') :
                ($approved ? __('Validée, non envoyée', 'dendrila-simple-edit') :
                ($post->post_status === 'pending' ? __('En attente de validation', 'dendrila-simple-edit') : __('Brouillon', 'dendrila-simple-edit')));
            echo '<article class="dendrila-simple-edit-subcard dendrila-simple-edit-newsletter-item"' .
                ($current === (int) $post->ID ? ' data-dendrila-simple-edit-current-draft="1"' : '') . '>';
            echo '<div class="dendrila-simple-edit-newsletter-item-bar">';
            echo '<details class="dendrila-simple-edit-newsletter-item-details"><summary><span class="dendrila-simple-edit-newsletter-item-title">' .
                esc_html($post->post_title) . '</span><small class="dendrila-simple-edit-newsletter-item-status">' .
                esc_html($status) . '</small></summary>';
            echo '<div class="dendrila-simple-edit-newsletter-item-content">';
            echo '<details><summary>' . esc_html__('Prévisualiser le contenu', 'dendrila-simple-edit') . '</summary><div class="dendrila-simple-edit-preview"><strong>' .
                esc_html($post->post_title) . '</strong><p>' .
                wp_kses_post(Dendrila_Simple_Edit_Block_Editor::email_body($post->post_content)) . '</div></details>';
            if ($may_edit && ($admin || (int)$post->post_author === get_current_user_id())) { self::form($post); }
            echo '</div></details>';
            echo '<div class="dendrila-simple-edit-newsletter-quick-actions">';
            if ($may_send && self::can_prepare_for_send($post)) {
                echo '<a class="button button-primary" href="' .
                    esc_url(add_query_arg(array('page' => 'dendrila-simple-edit', 'dendrila_simple_edit_tab' => 'newsletters',
                        'dendrila_simple_edit_nl_section' => 'envois', 'dendrila_simple_edit_send_nid' => (int)$post->ID),
                        admin_url('admin.php'))) . '">Préparer un envoi</a>';
            } elseif ($may_approve && !$approved) {
                self::action_form('dendrila_simple_edit_newsletter_approve', 'dendrila_simple_edit_newsletter_approve',
                    __('Valider', 'dendrila-simple-edit'), $post, $hash);
            } elseif ($may_review && $post->post_status === 'draft') {
                self::action_form('dendrila_simple_edit_newsletter_review', 'dendrila_simple_edit_newsletter_review',
                    __('Soumettre à validation', 'dendrila-simple-edit'), $post, $hash);
            }
            echo '</div></div></article>';
        }
        echo '</section>';
        if ($may_create) {
            echo '<details class="dendrila-simple-edit-card dendrila-simple-edit-newsletter-new">';
            echo '<summary>' . esc_html__('Créer une nouvelle newsletter', 'dendrila-simple-edit') . '</summary>';
            self::form(null);
            echo '</details>';
        }
    }

    /**
     * Return the first actual paragraph from a published article.
     * Gutenberg and Dendrila block HTML both store readable paragraphs in <p>.
     * Never execute shortcodes or HTML from the article when importing it.
     */
    public static function first_article_paragraph($post)
    {
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' ||
            $post->post_password !== '') { return ''; }
        $body = (string) $post->post_content;
        $paragraph = '';
        if (preg_match_all('~<p(?:\s[^>]*)?>(.*?)</p>~isu', $body, $matches)) {
            foreach ($matches[1] as $candidate) {
                $text = preg_replace('~<br\s*/?>~i', ' ', $candidate);
                $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));
                if ($text !== '') { $paragraph = $text; break; }
            }
        }
        if ($paragraph === '') {
            $paragraph = trim((string) $post->post_excerpt);
        }
        if ($paragraph === '') {
            // Plain-text articles may not contain HTML paragraphs.
            $parts = preg_split('/\r?\n(?:\s*\r?\n)+/', $body, 2);
            $paragraph = trim((string) ($parts[0] ?? ''));
        }
        $paragraph = sanitize_textarea_field(html_entity_decode(
            wp_strip_all_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'
        ));
        // Bound the size of the generated page and imported blocks.
        return function_exists('mb_substr') ? mb_substr($paragraph, 0, 4000, 'UTF-8') : substr($paragraph, 0, 4000);
    }

    /** Admin and delegated editors can import published, public articles as an independent copy. */
    private static function article_import_assistant($suffix)
    {
        $articles = get_posts(array(
            'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false,
            'posts_per_page' => 120, 'orderby' => 'date', 'order' => 'DESC'
        ));
        echo '<details class="dendrila-simple-edit-article-import" data-dendrila-simple-edit-article-import hidden>';
        echo '<summary>' . esc_html__('Insérer un article du site', 'dendrila-simple-edit') . '</summary>';
        echo '<div class="dendrila-simple-edit-article-import-body">';
        echo '<p class="description">' . esc_html__('Récupérez son titre et son premier paragraphe. Après insertion, ces textes sont indépendants : vous pouvez les modifier sans changer l’article d’origine.', 'dendrila-simple-edit') . '</p>';
        echo '<div class="dendrila-simple-edit-article-import-controls">';
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-import-article-' . esc_attr($suffix) . '">' . esc_html__('Article à présenter', 'dendrila-simple-edit') . '</label>';
        echo '<select data-dendrila-simple-edit-article-select id="dendrila-simple-edit-import-article-' . esc_attr($suffix) . '">';
        echo '<option value="">' . esc_html__('Choisir un article publié', 'dendrila-simple-edit') . '</option>';
        foreach ($articles as $article) {
            if (!$article || $article->post_password !== '') { continue; }
            $excerpt = self::first_article_paragraph($article);
            $url = get_permalink($article);
            if (!$excerpt || !$url || !preg_match('~^https?://~i', $url)) { continue; }
            echo '<option value="' . (int) $article->ID .
                '" data-dendrila-simple-edit-article-title="' . esc_attr(get_the_title($article)) .
                '" data-dendrila-simple-edit-article-paragraph="' . esc_attr($excerpt) .
                '" data-dendrila-simple-edit-article-url="' . esc_url($url) . '">' .
                esc_html(get_the_title($article)) . '</option>';
        }
        echo '</select></div>';
        echo '<fieldset class="dendrila-simple-edit-article-import-choice"><legend>Comment proposer la suite de l’article ?</legend>';
        echo '<label><input type="radio" name="dendrila_simple_edit_import_cta_' . esc_attr($suffix) . '" value="button" checked> Un bouton bien visible</label>';
        echo '<label><input type="radio" name="dendrila_simple_edit_import_cta_' . esc_attr($suffix) . '" value="link"> Un lien dans le texte</label>';
        echo '</fieldset>';
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-import-label-' . esc_attr($suffix) . '">Texte du bouton ou du lien</label>';
        echo '<input type="text" data-dendrila-simple-edit-article-label id="dendrila-simple-edit-import-label-' . esc_attr($suffix) .
            '" maxlength="100" value="Lire la suite" placeholder="Ex. Découvrir l’article"></div>';
        echo '</div>';
        echo '<div class="dendrila-simple-edit-article-import-preview" data-dendrila-simple-edit-article-preview hidden aria-live="polite">';
        echo '<strong data-dendrila-simple-edit-article-preview-title></strong><p data-dendrila-simple-edit-article-preview-text></p>';
        echo '<span data-dendrila-simple-edit-article-preview-action></span></div>';
        echo '<p><button type="button" class="button button-secondary" data-dendrila-simple-edit-article-insert disabled>' . esc_html__('Ajouter cet article à la newsletter', 'dendrila-simple-edit') . '</button></p>';
        echo '<p class="dendrila-simple-edit-article-import-feedback" data-dendrila-simple-edit-article-feedback role="status"></p>';
        echo '</div></details>';
    }

    private static function form($post)
    {
        $existing = $post !== null;
        echo '<form class="dendrila-simple-edit-subcard" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h3>' . ($existing ? __('Modifier le brouillon', 'dendrila-simple-edit') : __('Nouvelle newsletter', 'dendrila-simple-edit')) . '</h3>';
        Dendrila_Simple_Edit_Newsletter_Templates::form($post);
        if (!$existing) { echo '<div data-dendrila-simple-edit-template-editor hidden>'; }
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_newsletter_save">';
        if ($existing) {
            echo '<input type="hidden" name="dendrila_simple_edit_nid" value="' . esc_attr((string) $post->ID) . '">';
            echo '<input type="hidden" name="dendrila_simple_edit_nversion" value="' .
                esc_attr(self::fingerprint($post)) . '">';
        }
        wp_nonce_field('dendrila_simple_edit_newsletter_save');
        $suffix = $existing ? (string) $post->ID : 'new';
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-subject-' . esc_attr($suffix) .
            '">Objet de la newsletter</label><input id="dendrila-simple-edit-subject-' . esc_attr($suffix) .
            '" type="text" name="dendrila_simple_edit_nsubject" required maxlength="160" value="' .
            esc_attr($existing ? $post->post_title : '') . '"></div>';
        echo '<div class="dendrila-simple-edit-block-editor" data-dendrila-simple-edit-block-editor data-allow-title="1">';
        self::article_import_assistant($suffix);
        echo '<label for="dendrila-simple-edit-body-' . esc_attr($suffix) . '">Contenu de la newsletter</label>';
        echo '<textarea data-dendrila-simple-edit-editor-source id="dendrila-simple-edit-body-' . esc_attr($suffix) .
            '" name="dendrila_simple_edit_nbody" rows="9">' .
            esc_textarea($existing ? $post->post_content : '') . '</textarea></div>';
        echo '<p class="description">' . esc_html__('Lors de chaque envoi, Simple Edit ajoute automatiquement un lien de désinscription individuel en bas du message. Vous n’avez rien à insérer et ce lien ne peut pas être retiré depuis l’éditeur.', 'dendrila-simple-edit') . '</p>';
        echo '<p><button type="submit" class="button button-primary">Enregistrer le brouillon</button></p>';
        if (!$existing) { echo '</div>'; }
        echo '</form>';
    }

    private static function action_form($action, $nonce, $button, $post, $hash)
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="dendrila_simple_edit_nid" value="' . esc_attr((string) $post->ID) . '">';
        echo '<input type="hidden" name="dendrila_simple_edit_nversion" value="' . esc_attr($hash) . '">';
        wp_nonce_field($nonce);
        echo '<p><button type="submit" class="button">' . esc_html($button) . '</button></p></form>';
    }
}
