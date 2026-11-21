<?php
/** Delegated editing of explicitly permitted, layout-safe WordPress pages and posts. */
if (!defined('ABSPATH')) { exit; }

final class DSE_Post_Editor
{
    const OPTION = 'dse_visual_editable_posts';
    const CREATED_META = '_dse_created_by_simple_edit';

    public static function can_create_type($type)
    {
        if (!in_array($type, array('page', 'post'), true) || !DSE_Plugin::can_edit()) { return false; }
        if (current_user_can('manage_options')) { return true; }
        return DSE_Modules::allowed('editorial', $type === 'page' ? 'create_pages' : 'create_articles');
    }

    public static function can_publish_type($type)
    {
        if (!self::can_create_type($type)) { return false; }
        if (current_user_can('manage_options')) { return true; }
        return DSE_Modules::allowed('editorial', $type === 'page' ? 'publish_pages' : 'publish_articles');
    }

    private static function own_created($post)
    {
        if (!$post || !in_array($post->post_type, array('page','post'), true) ||
            !in_array($post->post_status, array('publish','draft'), true) ||
            get_post_meta($post->ID, self::CREATED_META, true) !== '1') { return false; }
        if (current_user_can('manage_options')) { return true; }
        return (int) $post->post_author === get_current_user_id() &&
            self::can_create_type($post->post_type);
    }

    public static function boot()
    {
        add_action('admin_post_dse_post_permissions', array(__CLASS__, 'configure'));
        add_action('admin_post_dse_post_save', array(__CLASS__, 'save'));
        add_action('admin_post_dse_post_create', array(__CLASS__, 'create'));
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
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, array('page', 'post'), true) ||
            (string) $post->post_password !== '') { return false; }
        if (self::own_created($post)) { return $post; }
        return in_array($id, self::allowed_ids(), true) &&
            $post->post_status === 'publish' ? $post : false;
    }

    public static function fingerprint($post)
    {
        return hash('sha256', $post->ID . '|' . $post->post_title . '|' . $post->post_content . '|' . $post->post_status . '|' . $post->post_modified_gmt);
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

    public static function create()
    {
        check_admin_referer('dse_post_create');
        $type = isset($_POST['dse_new_type']) && is_string($_POST['dse_new_type']) ?
            sanitize_key(wp_unslash($_POST['dse_new_type'])) : '';
        if (!self::can_create_type($type)) {
            wp_die('Vous ne pouvez pas créer ce type de contenu.', 'Accès refusé', array('response' => 403));
        }
        $raw_title = isset($_POST['dse_new_title']) ? wp_unslash($_POST['dse_new_title']) : null;
        $raw_body = isset($_POST['dse_new_body']) ? wp_unslash($_POST['dse_new_body']) : null;
        $status = isset($_POST['dse_new_status']) && is_string($_POST['dse_new_status']) ?
            sanitize_key(wp_unslash($_POST['dse_new_status'])) : '';
        if (!is_string($raw_title) || strlen($raw_title) > 800 || !is_string($raw_body) ||
            strlen($raw_body) > 100000 || !in_array($status, array('draft','publish'), true)) {
            wp_die('Formulaire invalide.', 'Requête incorrecte', array('response' => 400));
        }
        if ($status === 'publish' && !self::can_publish_type($type)) {
            wp_die('Publication non autorisée. Enregistrez d’abord un brouillon.', 'Accès refusé', array('response' => 403));
        }
        $title = sanitize_text_field($raw_title);
        if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title,'UTF-8') : strlen($title)) > 180) {
            wp_die('Veuillez saisir un titre de 180 caractères maximum.', 'Titre incorrect', array('response' => 400));
        }
        $content = DSE_Block_Editor::clean($raw_body);
        if (!DSE_Block_Editor::is_safe_for_editor($content)) {
            wp_die('Contenu incompatible avec l’éditeur simplifié.', 'Requête incorrecte', array('response' => 400));
        }
        $id = wp_insert_post(array(
            'post_type' => $type, 'post_status' => $status,
            'post_author' => get_current_user_id(), 'post_title' => wp_slash($title),
            'post_content' => wp_slash($content),
            'comment_status' => 'closed',
        ), true);
        if (is_wp_error($id) || !$id) { self::redirect('error'); }
        update_post_meta((int) $id, self::CREATED_META, '1');
        self::redirect('created', (int) $id);
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
        $update = array('ID' => $id, 'post_title' => wp_slash($title),
            'post_content' => wp_slash($clean));
        // Only an explicitly authorized creator can publish their own Simple Edit draft.
        $status = $post->post_status;
        if (self::own_created($post) && self::can_create_type($post->post_type)) {
            $requested = isset($_POST['dse_paged_status']) && is_string($_POST['dse_paged_status']) ?
                sanitize_key(wp_unslash($_POST['dse_paged_status'])) : '';
            if (!in_array($requested, array('draft', 'publish'), true)) {
                wp_die('Statut de publication invalide.', 'Requête incorrecte', array('response' => 400));
            }
            if ($requested === 'publish' && $post->post_status !== 'publish' &&
                !self::can_publish_type($post->post_type)) {
                wp_die('Publication non autorisée.', 'Accès refusé', array('response' => 403));
            }
            $status = $requested;
            $update['post_status'] = $status;
        }
        if ($clean === $post->post_content && $title === $post->post_title && $status === $post->post_status) {
            self::redirect('unchanged', $id);
        }
        // No native editing capabilities are added to the role; this precise write
        // is validated by the content allowlist/ownership, nonce and fingerprint.
        $updated = wp_update_post($update, true);
        if (is_wp_error($updated) || !$updated) { self::redirect('error', $id); }
        self::redirect('saved', $id);
    }

    public static function apply_frontend_colors($html)
    {
        if (!is_singular(array('page', 'post')) || !in_the_loop() || !is_main_query()) { return $html; }
        $id = get_the_ID();
        $post = get_post($id);
        if ((!in_array((int) $id, self::allowed_ids(), true) &&
            get_post_meta($id, self::CREATED_META, true) !== '1') ||
            !$post || !self::compatible($post)) { return $html; }
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
        // Add only own newly created content, plus all Simple Edit creations for admins.
        if (current_user_can('manage_options') || self::can_create_type($type)) {
            $created = get_posts(array('post_type' => $type, 'post_status' => array('publish','draft'),
                'posts_per_page' => 100, 'meta_key' => self::CREATED_META, 'meta_value' => '1',
                'author' => current_user_can('manage_options') ? '' : get_current_user_id(),
                'fields' => 'ids'));
            foreach ($created as $created_id) {
                if (self::own_created(get_post($created_id))) { $ids[] = (int) $created_id; }
            }
            $ids = array_values(array_unique($ids));
        }
        $name = $type === 'page' ? 'page' : 'article';
        $article = $type === 'page' ? 'une page' : 'un article';
        $title_label = $type === 'page' ? 'Titre de la page' : 'Titre de l’article';
        $content_label = $type === 'page' ? 'Contenu de la page' : 'Contenu de l’article';
        $tab = $type === 'page' ? 'pages' : 'articles';
        if (self::can_create_type($type)) {
            echo '<details class="dse-card dse-create-content"><summary>Créer ' . esc_html($article) . '</summary>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dse_post_create">';
            echo '<input type="hidden" name="dse_new_type" value="' . esc_attr($type) . '">';
            wp_nonce_field('dse_post_create');
            echo '<div class="dse-field"><label for="dse-new-title">' . esc_html($title_label) . '</label>';
            echo '<input type="text" id="dse-new-title" name="dse_new_title" required maxlength="180"></div>';
            echo '<div class="dse-block-editor" data-dse-block-editor data-allow-title="0">';
            echo '<label for="dse-new-body">' . esc_html($content_label) . '</label>';
            echo '<textarea id="dse-new-body" data-dse-editor-source name="dse_new_body" rows="9"></textarea></div>';
            echo '<div class="dse-field"><label for="dse-new-status">Enregistrer comme</label>';
            echo '<select id="dse-new-status" name="dse_new_status">';
            echo '<option value="draft">Brouillon — non visible sur le site</option>';
            if (self::can_publish_type($type)) {
                echo '<option value="publish">Publier sur le site immédiatement</option>';
            }
            echo '</select></div>';
            echo '<p><button class="button button-primary" type="submit">Créer ' . esc_html($article) . '</button></p>';
            echo '</form></details>';
        }
        if (!$ids) {
            echo '<section class="dse-card"><h3>Aucun ' . esc_html($name) . ' à modifier</h3>';
            echo '<p>' . (self::can_create_type($type) ? 'Vous pouvez créer un contenu avec le formulaire ci-dessus.' :
                'L’administrateur choisit les contenus accessibles dans Autorisations.') . '</p></section>';
            return;
        }
        $notice = isset($_GET['dse_paged_notice']) ? sanitize_key(wp_unslash($_GET['dse_paged_notice'])) : '';
        $notices = array('saved' => 'Modifications enregistrées.', 'created' => 'Contenu créé et enregistré.',
            'permissions' => 'Autorisations mises à jour.',
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
                    'dse_paged_id' => $id), admin_url('admin.php'))) . '">' . esc_html($post->post_title) .
                ($post->post_status === 'draft' ? ' (brouillon)' : '') . '</a> ';
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
        if (self::own_created($post) && self::can_create_type($post->post_type)) {
            echo '<div class="dse-field"><label for="dse-paged-status">Publication</label>';
            echo '<select id="dse-paged-status" name="dse_paged_status">';
            echo '<option value="draft" ' . selected($post->post_status, 'draft', false) . '>Conserver en brouillon</option>';
            if (self::can_publish_type($post->post_type) || $post->post_status === 'publish') {
                echo '<option value="publish" ' . selected($post->post_status, 'publish', false) . '>Publier sur le site</option>';
            }
            echo '</select></div>';
        }
        echo '<div class="dse-block-editor" data-dse-block-editor data-allow-title="0">';
        echo '<label for="dse-paged-body">' . esc_html($content_label) . '</label>';
        echo '<textarea id="dse-paged-body" data-dse-editor-source name="dse_paged_body" rows="10">' .
            esc_textarea($post->post_content) . '</textarea></div>';
        echo '<p><button type="submit" class="button button-primary">Enregistrer les modifications</button></p></form>';
    }
}
