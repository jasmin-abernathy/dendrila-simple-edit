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
        wp_safe_redirect(add_query_arg(array('dse_nnotice' => $notice, 'dse_tab' => 'newsletters'), admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    private static function clean_body($body)
    {
        $tags = array_fill_keys(array('p', 'br', 'h1', 'h2', 'h3', 'h4', 'strong', 'b',
            'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'hr'), array());
        $tags['a'] = array('href' => true, 'title' => true, 'class' => true);
        $tags['img'] = array('src' => true, 'alt' => true, 'width' => true, 'height' => true);
        foreach (array('h1', 'h2', 'p', 'a', 'img') as $tag) {
            $tags[$tag]['data-dse-color'] = true;
        }
        $tags['div'] = array('data-dse-block' => true);
        $clean = wp_kses($body, $tags, array('https', 'http', 'mailto'));
        $heading_count = 0;
        return preg_replace_callback('~(<\/?)(h1)(?=[\s>])[^>]*>~i', static function ($match) use (&$heading_count) {
            if ($match[1] === '<') { $heading_count++; }
            return $heading_count > 1 ? preg_replace('/h1/i', 'h2', $match[0], 1) : $match[0];
        }, $clean);
    }

    private static function formatted_body($body)
    {
        $html = new WP_HTML_Tag_Processor(self::clean_body(wpautop($body)));
        $palette = array('green' => '#24543b', 'blue' => '#245185', 'plum' => '#713e6d',
            'brick' => '#8c3832', 'slate' => '#394758');
        while ($html->next_tag()) {
            $tag = $html->get_tag();
            $key = $html->get_attribute('data-dse-color');
            $color = is_string($key) ? ($palette[$key] ?? '') : '';
            if ($tag === 'A' && $html->has_class('dse-newsletter-cta')) {
                $html->set_attribute('style', 'display:inline-block;background-color:' . ($color ?: $palette['green']) .
                    ';color:#ffffff;padding:10px 18px;border-radius:6px;font-weight:bold;text-decoration:none;');
            } elseif ($color !== '' && $tag === 'IMG') {
                $html->set_attribute('style', 'border:3px solid ' . $color . ';max-width:100%;height:auto;');
            } elseif ($color !== '') {
                $html->set_attribute('style', 'color:' . $color . ';');
            }
        }
        return $html->get_updated_html();
    }

    private static function editor_url($selection)
    {
        return add_query_arg(array('page' => 'dendrila-simple-edit', 'dse_tab' => 'newsletters',
            'dse_nedit' => $selection), admin_url('admin.php'));
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
        $body = self::clean_body($body);
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

    private static function active_providers()
    {
        $providers = array(
            'newsletter/plugin.php' => array('Newsletter', 'admin.php?page=newsletter_emails_index',
                'Créez une newsletter dans WordPress, puis collez l’objet et insérez le contenu dans un bloc HTML du générateur.'),
            'mailpoet/mailpoet.php' => array('MailPoet', 'admin.php?page=mailpoet-newsletters',
                'Créez une newsletter standard, puis collez le contenu dans un bloc HTML MailPoet si votre éditeur en propose un.'),
            'fluent-crm/fluent-crm.php' => array('FluentCRM', 'admin.php?page=fluentcrm-admin#/email/campaigns',
                'Créez une campagne e-mail, puis collez l’objet et reprenez le contenu mis en forme dans son éditeur.'),
            'mailin/sendinblue.php' => array('Brevo', '',
                'Dans votre compte Brevo, ouvrez les campagnes e-mail, puis reprenez l’objet et le contenu mis en forme dans une nouvelle campagne.'),
            'mailchimp-for-wp/mailchimp-for-wp.php' => array('Mailchimp', '',
                'Dans votre compte Mailchimp, ouvrez les campagnes, puis reprenez l’objet et le contenu mis en forme dans un nouvel e-mail.'),
        );

        return array_filter($providers, 'is_plugin_active', ARRAY_FILTER_USE_KEY);
    }

    private static function render_handoff($post, $providers)
    {
        $id = 'dse-handoff-' . (int) $post->ID;
        echo '<details class="dse-field"><summary>Récupérer le contenu validé</summary>';
        echo '<label for="' . esc_attr($id . '-subject') . '">Objet à copier</label>';
        echo '<input type="text" readonly id="' . esc_attr($id . '-subject') . '" value="' .
            esc_attr($post->post_title) . '">';
        echo '<label for="' . esc_attr($id . '-body') . '">Code HTML à copier</label>';
        echo '<textarea readonly rows="6" id="' . esc_attr($id . '-body') . '">' .
            esc_textarea(self::formatted_body($post->post_content)) . '</textarea>';
        foreach ($providers as $provider) {
            list($name, $admin_page, $instruction) = $provider;
            echo '<p><strong>' . esc_html($name) . '</strong> · ' . esc_html($instruction);
            if ($admin_page !== '' && current_user_can('manage_options')) {
                echo ' <a href="' . esc_url(admin_url($admin_page)) . '">Ouvrir ' . esc_html($name) . '</a>';
            }
            echo '</p>';
        }
        echo '</details>';
    }

    public static function render()
    {
        if (!DSE_Modules::enabled('newsletters')) { return; }
        $may_create = DSE_Modules::allowed('newsletters', 'create');
        $may_edit = DSE_Modules::allowed('newsletters', 'edit');
        $may_review = DSE_Modules::allowed('newsletters', 'review');
        $may_approve = DSE_Modules::allowed('newsletters', 'approve');
        if (!$may_create && !$may_edit && !$may_review && !$may_approve) { return; }
        $providers = self::active_providers();
        echo '<section class="dse-card"><h2>Messages à préparer</h2>';
        if ($providers) {
            echo '<p>Outils de newsletter détectés sur ce site · ' .
                esc_html(implode(', ', array_column($providers, 0))) . '</p>';
        }
        echo '<p>Rédigez un message, prévisualisez-le et, si besoin, demandez sa validation. Aucun e-mail n’est envoyé depuis Simple Edit.</p>';
        $notices = array('saved' => 'Brouillon enregistré.', 'review' => 'Contenu soumis à validation.',
            'approved' => 'Contenu validé. Aucun e-mail envoyé.', 'conflict' => 'Le texte a changé. Rechargez cette page.',
            'error' => 'Enregistrement non confirmé.');
        $notice = isset($_GET['dse_nnotice']) ? wp_unslash($_GET['dse_nnotice']) : '';
        if (is_string($notice) && isset($notices[$notice])) {
            echo '<p role="status">' . esc_html($notices[$notice]) . '</p>';
        }
        $selection = isset($_GET['dse_nedit']) && is_string($_GET['dse_nedit']) ?
            wp_unslash($_GET['dse_nedit']) : '';
        if ($may_create) {
            echo '<p><a class="button button-primary" href="' . esc_url(self::editor_url('new')) .
                '">Nouvelle newsletter</a></p>';
        }
        if ($may_create && $selection === 'new') {
            self::form(null);
        } elseif ($may_edit && ctype_digit($selection)) {
            $selected = self::get_draft((int) $selection);
            if ($selected) { self::form($selected); }
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
                esc_html($post->post_title) . '</strong>' .
                self::formatted_body($post->post_content) . '</div></details>';
            if ($approved) { self::render_handoff($post, $providers); }
            if ($may_edit) {
                echo '<p><a class="button" href="' . esc_url(self::editor_url((int) $post->ID)) .
                    '">Modifier</a></p>';
            }
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
        echo '<div class="dse-newsletter-builder" data-dse-newsletter-builder>';
        echo '<label for="dse-newsletter-source">Contenu de la newsletter</label>';
        echo '<textarea id="dse-newsletter-source" name="dse_nbody" rows="10">' .
            esc_textarea($existing ? self::clean_body($post->post_content) : '') . '</textarea>';
        echo '</div>';
        echo '<p><button type="submit" class="button button-primary">Enregistrer le brouillon</button></p></form>';
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

