<?php
/** Delegated editing of explicitly permitted, layout-safe WordPress pages and posts. */
if (!defined('ABSPATH')) { exit; }

final class DSE_Post_Editor
{
    const OPTION = 'dse_visual_editable_posts';

    public static function boot()
    {
        add_action('admin_post_dse_post_permissions', array(__CLASS__, 'configure'));
        add_action('admin_post_dse_post_save', array(__CLASS__, 'save'));
        add_filter('the_content', array(__CLASS__, 'apply_frontend_colors'), 20);
    }

    public static function allowed_ids()
    {
        $ids = get_option(self::OPTION, array());
        if (!is_array($ids)) { return array(); }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
    }

    private static function compatible($post)
    {
        return $post && DSE_Block_Editor::is_safe_for_editor($post->post_content) &&
            get_post_meta($post->ID, '_elementor_edit_mode', true) !== 'builder' &&
            !get_post_meta($post->ID, '_elementor_data', true) &&
            get_post_meta($post->ID, '_et_pb_use_builder', true) !== 'on';
    }

    private static function permitted_post($id)
    {
        if (!in_array($id, self::allowed_ids(), true)) { return false; }
        $post = get_post($id);
        return $post && in_array($post->post_type, array('page', 'post'), true) &&
            $post->post_status === 'publish' && (string) $post->post_password === '' ? $post : false;
    }

    public static function fingerprint($post)
    {
        return hash('sha256', $post->ID . '|' . $post->post_title . '|' . $post->post_content . '|' . $post->post_modified_gmt);
    }

    private static function can_modify()
    {
        return current_user_can('manage_options') || DSE_Plugin::can_edit();
    }

    private static function redirect($notice, $post_id = 0)
    {
        $post = $post_id ? get_post((int) $post_id) : false;
        $tab = $post && $post->post_type === 'post' ? 'articles' : 'pages';
        wp_safe_redirect(add_query_arg(array('page' => 'dendrila-simple-edit', 'dse_tab' => $tab,
            'dse_paged_notice' => $notice, 'dse_paged_id' => (int) $post_id), admin_url('admin.php')));
        exit;
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Réservé à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_post_permissions');
        $raw = isset($_POST['dse_post_ids']) ? wp_unslash($_POST['dse_post_ids']) : array();
        if (!is_array($raw) || count($raw) > 100) {
            wp_die('Choix de contenus invalide.', 'Requête incorrecte', array('response' => 400));
        }
        $ids = array();
        foreach ($raw as $value) {
            if (!is_string($value) || !ctype_digit($value)) { continue; }
            $post = get_post((int) $value);
            if ($post && in_array($post->post_type, array('page', 'post'), true) &&
                $post->post_status === 'publish' && !$post->post_password &&
                self::compatible($post)) {
                $ids[] = (int) $post->ID;
            }
        }
        update_option(self::OPTION, array_values(array_unique($ids)), false);
        self::redirect('permissions');
    }

    public static function save()
    {
        check_admin_referer('dse_post_save');
        if (!self::can_modify()) {
            wp_die('Vous ne pouvez pas modifier ces pages.', 'Accès refusé', array('response' => 403));
        }
        $raw_id = isset($_POST['dse_paged_id']) ? wp_unslash($_POST['dse_paged_id']) : '';
        $expected = isset($_POST['dse_paged_version']) ? wp_unslash($_POST['dse_paged_version']) : '';
        $content = isset($_POST['dse_paged_body']) ? wp_unslash($_POST['dse_paged_body']) : null;
        $raw_title = isset($_POST['dse_paged_title']) ? wp_unslash($_POST['dse_paged_title']) : null;
        if (!is_string($raw_id) || !ctype_digit($raw_id) || !is_string($expected) ||
            !preg_match('/^[a-f0-9]{64}$/', $expected) || !is_string($content) || strlen($content) > 100000 ||
            !is_string($raw_title) || strlen($raw_title) > 800) {
            wp_die('Formulaire invalide.', 'Requête incorrecte', array('response' => 400));
        }
        $id = (int) $raw_id;
        $post = self::permitted_post($id);
        if (!$post || !self::compatible($post)) {
            self::redirect('forbidden');
        }
        if (!hash_equals(self::fingerprint($post), $expected)) { self::redirect('conflict', $id); }
        $clean = DSE_Block_Editor::clean($content);
        if (!DSE_Block_Editor::is_safe_for_editor($clean)) {
            wp_die('Ce contenu n’est pas compatible avec l’éditeur simplifié.', 'Format non pris en charge', array('response' => 400));
        }
        $title = sanitize_text_field($raw_title);
        if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 180) {
            wp_die('Veuillez saisir un titre valide (180 caractères maximum).', 'Titre invalide', array('response' => 400));
        }
        if ($clean === $post->post_content && $title === $post->post_title) { self::redirect('unchanged', $id); }
        // The delegated role has no native edit_posts/edit_pages capability: this
        // exact write is authorized by the allowlist, nonce and optimistic version.
        $updated = wp_update_post(array('ID' => $id, 'post_title' => wp_slash($title),
            'post_content' => wp_slash($clean)), true);
        if (is_wp_error($updated) || !$updated) { self::redirect('error', $id); }
        self::redirect('saved', $id);
    }

    public static function apply_frontend_colors($html)
    {
        if (!is_singular(array('page', 'post')) || !in_the_loop() || !is_main_query()) { return $html; }
        $id = get_the_ID();
        if (!in_array((int) $id, self::allowed_ids(), true)) { return $html; }
        $post = get_post($id);
        if (!$post || !self::compatible($post)) { return $html; }
        return DSE_Block_Editor::frontend_colors($html);
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        $allowed = self::allowed_ids();
        $posts = get_posts(array('post_type' => array('post', 'page'), 'post_status' => 'publish',
            'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC'));
        echo '<section class="dse-card"><h2>Pages et articles autorisés</h2>';
        echo '<p>Cochez uniquement les contenus que les utilisateurs « Édition simple » pourront modifier. Les pages construites avec Gutenberg, Divi, Elementor ou du code complexe sont protégées.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dse_post_permissions">';
        wp_nonce_field('dse_post_permissions');
        $count = 0;
        foreach (array('page' => 'Pages', 'post' => 'Articles') as $type => $heading) {
            echo '<fieldset class="dse-permissions"><legend>' . esc_html($heading) . '</legend>';
            $group_count = 0;
            foreach ($posts as $post) {
                if (!$post || $post->post_type !== $type || $post->post_password || !self::compatible($post)) { continue; }
                $count++;
                $group_count++;
                echo '<label class="dse-check"><input type="checkbox" name="dse_post_ids[]" value="' . (int) $post->ID . '" ' .
                    checked(in_array((int) $post->ID, $allowed, true), true, false) . '> ' .
                    esc_html($post->post_title) . '</label>';
            }
            if (!$group_count) { echo '<p>Aucun contenu compatible dans cette rubrique.</p>'; }
            echo '</fieldset>';
        }
        if ($count === 0) { echo '<p>Créez une page ou un article vide dans WordPress pour commencer.</p>'; }
        echo '<p><button type="submit" class="button button-primary">Enregistrer les autorisations</button></p></form></section>';
    }

    public static function render($type = 'page')
    {
        if (!in_array($type, array('page', 'post'), true)) { return; }
        $ids = array_values(array_filter(self::allowed_ids(), static function ($id) use ($type) {
            $post = get_post($id);
            return $post && $post->post_type === $type;
        }));
        $name = $type === 'page' ? 'page' : 'article';
        $article = $type === 'page' ? 'une page' : 'un article';
        $title_label = $type === 'page' ? 'Titre de la page' : 'Titre de l’article';
        $content_label = $type === 'page' ? 'Contenu de la page' : 'Contenu de l’article';
        $tab = $type === 'page' ? 'pages' : 'articles';
        if (!$ids) {
            echo '<section class="dse-card"><h3>Aucun ' . esc_html($name) . ' autorisé</h3><p>L’administrateur choisit les contenus accessibles dans l’onglet Autorisations.</p></section>';
            return;
        }
        $notice = isset($_GET['dse_paged_notice']) ? sanitize_key(wp_unslash($_GET['dse_paged_notice'])) : '';
        $notices = array('saved' => 'Modifications enregistrées.', 'permissions' => 'Autorisations mises à jour.',
            'forbidden' => 'Ce contenu ne peut pas être modifié ici.', 'conflict' => 'Cette page a changé depuis votre ouverture : rechargez-la.',
            'unchanged' => 'Aucun changement.', 'error' => 'Enregistrement impossible.');
        if (isset($notices[$notice])) { echo '<p class="notice notice-info" role="status">' . esc_html($notices[$notice]) . '</p>'; }
        $selected = isset($_GET['dse_paged_id']) ? absint($_GET['dse_paged_id']) : 0;
        echo '<section class="dse-card"><h2>Choisir ' . esc_html($article) . '</h2><div class="dse-post-choice">';
        foreach ($ids as $id) {
            $post = self::permitted_post($id);
            if (!$post) { continue; }
            echo '<a class="button ' . ($selected === $id ? 'button-primary' : 'button-secondary') . '" href="' .
                esc_url(add_query_arg(array('page' => 'dendrila-simple-edit', 'dse_tab' => $tab,
                    'dse_paged_id' => $id), admin_url('admin.php'))) . '">' . esc_html($post->post_title) . '</a> ';
        }
        echo '</div></section>';
        if (!$selected) { return; }
        $post = self::permitted_post($selected);
        if (!$post || $post->post_type !== $type || !self::compatible($post)) {
            echo '<p class="notice notice-warning">Ce contenu n’est pas accessible dans cette rubrique ou utilise une mise en page non compatible.</p>'; return;
        }
        echo '<form class="dse-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h2>Modifier ' . esc_html($article) . '</h2>';
        echo '<p>Le titre et les autres éléments seront visibles sur le site après enregistrement.</p>';
        echo '<div class="dse-field"><label for="dse-paged-title">' . esc_html($title_label) . '</label>';
        echo '<input type="text" id="dse-paged-title" name="dse_paged_title" required maxlength="180" value="' .
            esc_attr($post->post_title) . '"></div>';
        echo '<input type="hidden" name="action" value="dse_post_save">';
        echo '<input type="hidden" name="dse_paged_id" value="' . (int) $post->ID . '">';
        echo '<input type="hidden" name="dse_paged_version" value="' . esc_attr(self::fingerprint($post)) . '">';
        wp_nonce_field('dse_post_save');
        echo '<div class="dse-block-editor" data-dse-block-editor data-allow-title="0">';
        echo '<label for="dse-paged-body">' . esc_html($content_label) . '</label>';
        echo '<textarea id="dse-paged-body" data-dse-editor-source name="dse_paged_body" rows="10">' .
            esc_textarea($post->post_content) . '</textarea></div>';
        echo '<p><button type="submit" class="button button-primary">Enregistrer les modifications</button></p></form>';
    }
}
