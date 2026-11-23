<?php
/** Delegated editing of explicitly permitted, layout-safe WordPress pages and posts. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Post_Editor
{
    const OPTION = 'dendrila_simple_edit_visual_editable_posts'; // Legacy allowlist: retained only for data migration.
    const TYPES_OPTION = 'dendrila_simple_edit_editable_types';
    const CREATED_META = '_dendrila_simple_edit_created_by_simple_edit';

    public static function can_create_type($type)
    {
        if (!in_array($type, array('page', 'post'), true)) { return false; }
        if (current_user_can('manage_options')) { return true; }
        return self::type_enabled($type) && Dendrila_Simple_Edit_Modules::allowed('editorial', $type === 'page' ? 'create_pages' : 'create_articles');
    }

    public static function can_publish_type($type)
    {
        if (!self::can_create_type($type)) { return false; }
        if (current_user_can('manage_options')) { return true; }
        if (Dendrila_Simple_Edit_Approvals::required($type)) { return false; }
        return Dendrila_Simple_Edit_Modules::allowed('editorial', $type === 'page' ? 'publish_pages' : 'publish_articles');
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
        add_action('admin_post_dendrila_simple_edit_post_permissions', array(__CLASS__, 'configure'));
        add_action('admin_post_dendrila_simple_edit_post_save', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_post_create', array(__CLASS__, 'create'));
        add_filter('post_thumbnail_html', array(__CLASS__, 'thumbnail_html'), 10, 3);
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

    /** Global choices apply to delegated roles. Administrators always retain access. */
    public static function editable_types()
    {
        $saved = get_option(self::TYPES_OPTION, null);
        if ($saved === null) { return array('page', 'post', 'tribe_events'); }
        return is_array($saved) ? array_values(array_intersect(array('page', 'post', 'tribe_events'), $saved)) : array();
    }

    public static function type_enabled($type)
    {
        return current_user_can('manage_options') || in_array($type, self::editable_types(), true);
    }

    public static function save_types($types)
    {
        if (!current_user_can('manage_options') || !is_array($types) || count($types) > 3 ||
            array_diff($types, array('page', 'post', 'tribe_events'))) { return false; }
        update_option(self::TYPES_OPTION, array_values(array_unique($types)), false);
        return true;
    }

    public static function is_layout_safe($post)
    {
        return self::compatible($post);
    }

    private static function compatible($post)
    {
        return $post && Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($post->post_content) &&
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
        if (!self::compatible($post)) { return false; }
        if (current_user_can('manage_options') && in_array($post->post_status, array('publish','draft'), true)) { return $post; }
        if (!self::can_modify($post->post_type)) { return false; }
        return $post->post_status === 'publish' ? $post : false;
    }

    public static function fingerprint($post)
    {
        return hash('sha256', $post->ID . '|' . $post->post_title . '|' . $post->post_content . '|' . $post->post_status . '|' . $post->post_modified_gmt);
    }

    private static function can_modify($type = null)
    {
        if (current_user_can('manage_options')) { return true; }
        if (!self::type_enabled($type)) { return false; }
        // Editing existing posts is explicitly configured by WordPress role.
        if ($type === 'page') { return Dendrila_Simple_Edit_Modules::allowed('editorial', 'edit_pages'); }
        if ($type === 'post') { return Dendrila_Simple_Edit_Modules::allowed('editorial', 'edit_articles'); }
        return false;
    }

    private static function redirect($notice, $post_id = 0)
    {
        $post = $post_id ? get_post((int) $post_id) : false;
        $tab = $post && $post->post_type === 'post' ? 'articles' : 'pages';
        wp_safe_redirect(add_query_arg(array('page' => 'dendrila-simple-edit', 'dendrila_simple_edit_tab' => $tab,
            'dendrila_simple_edit_paged_notice' => $notice, 'dendrila_simple_edit_paged_id' => (int) $post_id), admin_url('admin.php')));
        exit;
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Réservé à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_post_permissions');
        $types = isset($_POST['dendrila_simple_edit_editable_types']) ?
            map_deep(wp_unslash($_POST['dendrila_simple_edit_editable_types']), 'sanitize_key') : array();
        if (!self::save_types($types)) {
            wp_die('Types de contenus invalides.', 'Requête incorrecte', array('response' => 400));
        }
        self::redirect('permissions');
    }

    /** Featured image for articles; per-post alt text avoids modifying attachments site-wide. */
    public static function illustration_form($post = null)
    {
        $current = $post ? (int) get_post_thumbnail_id($post) : 0;
        $ids = Dendrila_Simple_Edit_Media::accessible_ids();
        if ($current) { $ids[] = $current; }
        $meta = $post ? get_post_meta($post->ID, '_dendrila_simple_edit_illustration', true) : array();
        if (!is_array($meta)) { $meta = array(); }
        $alt = isset($meta['alt']) ? $meta['alt'] : ($current ? get_post_meta($current, '_wp_attachment_image_alt', true) : '');
        echo '<fieldset class="dendrila-simple-edit-photo-editor" data-dendrila-simple-edit-photo-picker><legend>Illustration principale</legend>';
        echo '<label>Image <select name="dendrila_simple_edit_illustration" data-dendrila-simple-edit-photo-select><option value="0">Aucune image</option>';
        foreach (array_unique(array_map('absint', $ids)) as $id) {
            if (!$id || !wp_attachment_is_image($id)) { continue; }
            echo '<option value="' . (int) $id . '" ' . selected($id, $current, false) . '>' . esc_html(get_the_title($id)) . '</option>';
        }
        echo '</select></label> <button type="button" class="button" data-dendrila-simple-edit-photo-open hidden>' . esc_html__('Choisir dans la médiathèque', 'dendrila-simple-edit') . '</button> ';
        echo '<button type="button" class="button" data-dendrila-simple-edit-photo-clear hidden>Retirer</button>';
        echo '<div data-dendrila-simple-edit-photo-preview>';
        if ($current) { echo wp_get_attachment_image($current, 'thumbnail'); }
        echo '</div><label>Texte alternatif (obligatoire pour une image)<input type="text" name="dendrila_simple_edit_image_alt" maxlength="500" value="' . esc_attr($alt) . '"></label>';
        echo '<label>Description (facultative)<textarea name="dendrila_simple_edit_image_description" maxlength="2000">' . esc_textarea($meta['description'] ?? '') . '</textarea></label></fieldset>';
    }

    private static function illustration_input($type, $post = null, $nonce_action = '')
    {
        if ($nonce_action !== '') { check_admin_referer($nonce_action); }
        if ($type !== 'post' || !isset($_POST['dendrila_simple_edit_illustration'])) { return null; }
        $raw_id = sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_illustration']));
        if (!is_string($raw_id) || !ctype_digit($raw_id)) {
            wp_die('Illustration invalide.', 'Requête incorrecte', array('response' => 400));
        }
        $id = (int) $raw_id;
        $alt = sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_image_alt'] ?? ''));
        $description = sanitize_textarea_field(wp_unslash($_POST['dendrila_simple_edit_image_description'] ?? ''));
        if (strlen($alt) > 2000 || strlen($description) > 10000 ||
            ($id && (!$alt || !wp_attachment_is_image($id) ||
                ((!$post || $id !== (int) get_post_thumbnail_id($post)) && !Dendrila_Simple_Edit_Media::allowed_image($id))))) {
            wp_die('Choisissez une image autorisée et indiquez son texte alternatif.', 'Image invalide', array('response' => 400));
        }
        return array('id' => $id, 'alt' => $alt, 'description' => $description);
    }

    public static function apply_illustration($id, $image)
    {
        if (!is_array($image) || !isset($image['id'], $image['alt'], $image['description'])) { return; }
        if ($image['id']) {
            set_post_thumbnail($id, (int) $image['id']);
            update_post_meta($id, '_dendrila_simple_edit_illustration', wp_slash($image));
        } else {
            delete_post_thumbnail($id);
            delete_post_meta($id, '_dendrila_simple_edit_illustration');
        }
    }

    public static function thumbnail_html($html, $id, $thumbnail)
    {
        $image = get_post_meta($id, '_dendrila_simple_edit_illustration', true);
        if (!is_array($image) || !isset($image['id']) || (int) $thumbnail !== (int) $image['id']) { return $html; }
        if (!class_exists('WP_HTML_Tag_Processor')) { return $html; }
        $tag = new WP_HTML_Tag_Processor($html);
        if ($tag->next_tag('IMG')) {
            $tag->set_attribute('alt', (string) ($image['alt'] ?? ''));
            if (!empty($image['description'])) { $tag->set_attribute('title', (string) $image['description']); }
        }
        return $tag->get_updated_html();
    }

    public static function create()
    {
        check_admin_referer('dendrila_simple_edit_post_create');
        $type = isset($_POST['dendrila_simple_edit_new_type']) && is_string($_POST['dendrila_simple_edit_new_type']) ?
            sanitize_key(wp_unslash($_POST['dendrila_simple_edit_new_type'])) : '';
        if (!self::can_create_type($type)) {
            wp_die('Vous ne pouvez pas créer ce type de contenu.', 'Accès refusé', array('response' => 403));
        }
        $raw_title = isset($_POST['dendrila_simple_edit_new_title']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_new_title'])) : null;
        $raw_body = isset($_POST['dendrila_simple_edit_new_body']) ? wp_kses(wp_unslash($_POST['dendrila_simple_edit_new_body']), Dendrila_Simple_Edit_Block_Editor::allowed_tags(), array('http', 'https', 'mailto')) : null;
        $status = isset($_POST['dendrila_simple_edit_new_status']) && is_string($_POST['dendrila_simple_edit_new_status']) ?
            sanitize_key(wp_unslash($_POST['dendrila_simple_edit_new_status'])) : '';
        if (!is_string($raw_title) || strlen($raw_title) > 800 || !is_string($raw_body) ||
            strlen($raw_body) > 100000 || !in_array($status, array('draft','publish','review'), true)) {
            wp_die('Formulaire invalide.', 'Requête incorrecte', array('response' => 400));
        }
        if ($status === 'review' && !Dendrila_Simple_Edit_Approvals::required($type)) {
            wp_die('Validation non requise pour ce contenu.', 'Requête incorrecte', array('response' => 400));
        }
        if ($status === 'publish' && !self::can_publish_type($type)) {
            wp_die('Publication non autorisée. Enregistrez d’abord un brouillon.', 'Accès refusé', array('response' => 403));
        }
        $title = sanitize_text_field($raw_title);
        if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title,'UTF-8') : strlen($title)) > 180) {
            wp_die('Veuillez saisir un titre de 180 caractères maximum.', 'Titre incorrect', array('response' => 400));
        }
        $content = Dendrila_Simple_Edit_Block_Editor::clean($raw_body);
        if (!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($content)) {
            wp_die('Contenu incompatible avec l’éditeur simplifié.', 'Requête incorrecte', array('response' => 400));
        }
        $image = self::illustration_input($type, null, 'dendrila_simple_edit_post_create');
        $id = wp_insert_post(array(
            'post_type' => $type, 'post_status' => $status === 'review' ? 'draft' : $status,
            'post_author' => get_current_user_id(), 'post_title' => wp_slash($title),
            'post_content' => wp_slash($content),
            'comment_status' => 'closed',
        ), true);
        if (is_wp_error($id) || !$id) { self::redirect('error'); }
        update_post_meta((int) $id, self::CREATED_META, '1');
        self::apply_illustration((int) $id, $image);
        if ($status === 'review') {
            if (!Dendrila_Simple_Edit_Approvals::submit(get_post((int)$id), $title, $content, 'publish')) {
                self::redirect('error', (int)$id);
            }
            self::redirect('submitted', (int)$id);
        }
        self::redirect('created', (int) $id);
    }

    public static function save()
    {
        check_admin_referer('dendrila_simple_edit_post_save');
        $raw_id = isset($_POST['dendrila_simple_edit_paged_id']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_paged_id'])) : '';
        $expected = isset($_POST['dendrila_simple_edit_paged_version']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_paged_version'])) : '';
        $content = isset($_POST['dendrila_simple_edit_paged_body']) ? wp_kses(wp_unslash($_POST['dendrila_simple_edit_paged_body']), Dendrila_Simple_Edit_Block_Editor::allowed_tags(), array('http', 'https', 'mailto')) : null;
        $raw_title = isset($_POST['dendrila_simple_edit_paged_title']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_paged_title'])) : null;
        if (!is_string($raw_id) || !ctype_digit($raw_id) || !is_string($expected) ||
            !preg_match('/^[a-f0-9]{64}$/', $expected) || !is_string($content) || strlen($content) > 100000 ||
            !is_string($raw_title) || strlen($raw_title) > 800) {
            wp_die('Formulaire invalide.', 'Requête incorrecte', array('response' => 400));
        }
        $id = (int) $raw_id;
        $post = self::permitted_post($id);
        if (!$post || !self::compatible($post) ||
            (!self::can_modify($post->post_type) && !self::own_created($post))) {
            self::redirect('forbidden');
        }
        if (!hash_equals(self::fingerprint($post), $expected)) { self::redirect('conflict', $id); }
        $clean = Dendrila_Simple_Edit_Block_Editor::clean($content);
        if (!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($clean)) {
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
            $requested = isset($_POST['dendrila_simple_edit_paged_status']) && is_string($_POST['dendrila_simple_edit_paged_status']) ?
                sanitize_key(wp_unslash($_POST['dendrila_simple_edit_paged_status'])) : '';
            if (!in_array($requested, array('draft', 'publish', 'review'), true) ||
                ($requested === 'review' && !Dendrila_Simple_Edit_Approvals::required($post->post_type))) {
                wp_die('Statut de publication invalide.', 'Requête incorrecte', array('response' => 400));
            }
            if ($requested === 'publish' && $post->post_status !== 'publish' &&
                !self::can_publish_type($post->post_type)) {
                wp_die('Publication non autorisée.', 'Accès refusé', array('response' => 403));
            }
            $status = $requested === 'review' ? 'draft' : $requested;
            $update['post_status'] = $status;
        }
        // A delegated user never overwrites an already-published item when approval is required.
        // For a draft, "submit" queues publication; saving an ordinary draft remains immediate.
        $review_requested = isset($requested) && $requested === 'review';
        $needs_review = Dendrila_Simple_Edit_Approvals::required($post->post_type) &&
            !current_user_can('manage_options') &&
            ($post->post_status === 'publish' || $review_requested);
        $image = self::illustration_input($post->post_type, $post, 'dendrila_simple_edit_post_save');
        if ($needs_review) {
            if (!Dendrila_Simple_Edit_Approvals::submit($post, $title, $clean,
                $review_requested ? 'publish' : $post->post_status, $image)) {
                self::redirect('error', $id);
            }
            self::redirect('submitted', $id);
        }
        $image_changed = $image && ($image['id'] !== (int) get_post_thumbnail_id($post) ||
            ($image['id'] && $image !== get_post_meta($id, '_dendrila_simple_edit_illustration', true)));
        if (!$image_changed && $clean === $post->post_content && $title === $post->post_title && $status === $post->post_status) {
            self::redirect('unchanged', $id);
        }
        // No native editing capabilities are added to the role; this precise write
        // is validated by the content allowlist/ownership, nonce and fingerprint.
        $updated = wp_update_post($update, true);
        if (is_wp_error($updated) || !$updated) { self::redirect('error', $id); }
        self::apply_illustration($id, $image);
        update_post_meta($id, '_dendrila_simple_edit_last_edit', time());
        self::redirect('saved', $id);
    }

    public static function apply_frontend_colors($html)
    {
        if (!is_singular(array('page', 'post')) || !in_the_loop() || !is_main_query()) { return $html; }
        $id = get_the_ID();
        $post = get_post($id);
        if (!$post || !self::compatible($post) ||
            (strpos($html, 'data-dendrila-simple-edit-') === false &&
             strpos($html, 'data-dse-') === false)) { return $html; }
        return Dendrila_Simple_Edit_Block_Editor::frontend_colors($html);
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        $types = self::editable_types();
        echo '<section class="dendrila-simple-edit-card"><h2>Types de contenus accessibles</h2>';
        echo '<p>Sélectionnez les types modifiables par les rôles autorisés. Il n’est pas nécessaire de cocher chaque page, article ou événement. Les administrateurs conservent tous leurs droits.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_post_permissions">';
        wp_nonce_field('dendrila_simple_edit_post_permissions');
        foreach (array('page'=>__('Pages','dendrila-simple-edit'), 'post'=>__('Articles','dendrila-simple-edit'),
            'tribe_events'=>__('Événements','dendrila-simple-edit')) as $type=>$label) {
            echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_editable_types[]" value="' . esc_attr($type) . '" ' .
                checked(in_array($type,$types,true),true,false) . '> ' . esc_html($label) . '</label>';
        }
        echo '<p class="description">Les mises en page complexes restent visibles mais protégées : utilisez leur éditeur WordPress d’origine.</p>';
        echo '<p><button type="submit" class="button button-primary">Enregistrer les types autorisés</button></p></form></section>';
    }

    public static function render($type = 'page')
    {
        if (!in_array($type, array('page', 'post'), true)) { return; }
        // Discover contents by post type, not an obsolete per-item allowlist.
        $ids = array();
        $protected = array();
        if (self::can_modify($type)) {
            $available = get_posts(array('post_type'=>$type,
                'post_status'=>current_user_can('manage_options') ? array('publish','draft') : 'publish',
                'has_password'=>false, 'posts_per_page'=>-1, 'update_post_term_cache'=>false, 'orderby'=>'modified', 'order'=>'DESC'));
            foreach ($available as $candidate) {
                if (!$candidate || $candidate->post_password !== '') { continue; }
                if (self::compatible($candidate)) { $ids[] = (int)$candidate->ID; }
                elseif (current_user_can('manage_options')) { $protected[] = $candidate; }
            }
        }
        // Administrators already received all drafts and published content above.
        // Delegates keep access to their own compatible creations, without a 100-item ceiling.
        if (!current_user_can('manage_options') && self::can_create_type($type)) {
            $created = get_posts(array(
                'post_type' => $type,
                'post_status' => self::can_modify($type) ? 'draft' : array('publish','draft'),
                'author' => get_current_user_id(),
                'has_password' => false,
                'posts_per_page' => -1,
                'update_post_term_cache' => false,
            ));
            foreach ($created as $candidate) {
                if (self::own_created($candidate) && self::compatible($candidate)) {
                    $ids[] = (int) $candidate->ID;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        $name = $type === 'page' ? 'page' : 'article';
        $article = $type === 'page' ? 'une page' : 'un article';
        $title_label = $type === 'page' ? 'Titre de la page' : 'Titre de l’article';
        $content_label = $type === 'page' ? 'Contenu de la page' : 'Contenu de l’article';
        $tab = $type === 'page' ? 'pages' : 'articles';
        if (self::can_create_type($type)) {
            echo '<details class="dendrila-simple-edit-card dendrila-simple-edit-create-content"><summary>Créer ' . esc_html($article) . '</summary>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_post_create">';
            echo '<input type="hidden" name="dendrila_simple_edit_new_type" value="' . esc_attr($type) . '">';
            wp_nonce_field('dendrila_simple_edit_post_create');
            echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-new-title">' . esc_html($title_label) . '</label>';
            echo '<input type="text" id="dendrila-simple-edit-new-title" name="dendrila_simple_edit_new_title" required maxlength="180"></div>';
            if ($type === 'post') { self::illustration_form(); }
            echo '<div class="dendrila-simple-edit-block-editor" data-dendrila-simple-edit-block-editor data-allow-title="0">';
            echo '<label for="dendrila-simple-edit-new-body">' . esc_html($content_label) . '</label>';
            echo '<textarea id="dendrila-simple-edit-new-body" data-dendrila-simple-edit-editor-source name="dendrila_simple_edit_new_body" rows="9"></textarea></div>';
            echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-new-status">Enregistrer comme</label>';
            echo '<select id="dendrila-simple-edit-new-status" name="dendrila_simple_edit_new_status">';
            echo '<option value="draft">Brouillon — non visible sur le site</option>';
            if (self::can_publish_type($type)) {
                echo '<option value="publish">' . esc_html__('Publier sur le site immédiatement', 'dendrila-simple-edit') . '</option>';
            } elseif (Dendrila_Simple_Edit_Approvals::required($type)) {
                echo '<option value="review">' . esc_html__('Soumettre pour publication après validation', 'dendrila-simple-edit') . '</option>';
            }
            echo '</select></div>';
            echo '<p><button class="button button-primary" type="submit">Créer ' . esc_html($article) . '</button></p>';
            echo '</form></details>';
        }
        if (!$ids && !$protected) {
            echo '<section class="dendrila-simple-edit-card"><h3>Aucun ' . esc_html($name) . ' à modifier</h3>';
            echo '<p>' . (self::can_create_type($type) ? 'Vous pouvez créer un contenu avec le formulaire ci-dessus.' :
                'L’administrateur choisit les contenus accessibles dans Autorisations.') . '</p></section>';
            return;
        }
        $notice = Dendrila_Simple_Edit_Plugin::screen_key('dendrila_simple_edit_paged_notice', '');
        $notices = array('saved' => 'Modifications enregistrées.', 'created' => 'Contenu créé et enregistré.',
            'permissions' => 'Autorisations mises à jour.',
            'submitted' => 'Proposition envoyée. Le site public n’a pas changé ; la validation est en attente.',
            'forbidden' => 'Ce contenu ne peut pas être modifié ici.', 'conflict' => 'Cette page a changé depuis votre ouverture : rechargez-la.',
            'unchanged' => 'Aucun changement.', 'error' => 'Enregistrement impossible.');
        if (isset($notices[$notice])) { echo '<p class="notice notice-info" role="status">' . esc_html($notices[$notice]) . '</p>'; }
        $selected = Dendrila_Simple_Edit_Plugin::screen_id('dendrila_simple_edit_paged_id', 0);
        echo '<section class="dendrila-simple-edit-content-list" aria-label="Contenus modifiables">';
        $shown = 0;
        if ($protected) {
            echo '<section class="dendrila-simple-edit-card"><h3>Contenus dont la mise en page est protégée</h3>';
            echo '<p>Ils existent bien sur ce site, mais l’éditeur simplifié ne doit pas réécrire leurs blocs Gutenberg, WooCommerce ou constructeur.</p><ul>';
            foreach ($protected as $locked) {
                $edit_url = get_edit_post_link($locked->ID, '');
                echo '<li>' . esc_html($locked->post_title) . ' — ' . esc_html($locked->post_status) .
                    ($edit_url ? ' <a href="' . esc_url($edit_url) . '" target="_blank" rel="noopener noreferrer">Modifier dans WordPress</a>' : '') . '</li>';
            }
            echo '</ul></section>';
        }
        foreach ($ids as $id) {
            $post = self::permitted_post($id);
            if (!$post || $post->post_type !== $type || !self::compatible($post)) { continue; }
            $shown++;
            // One editor per accordion, so opening a page never requires another navigation.
            echo '<details class="dendrila-simple-edit-card dendrila-simple-edit-post-item" id="dendrila-simple-edit-item-' . (int) $id . '"' .
                ($selected === $id ? ' open' : '') . '>';
            echo '<summary>' . esc_html($post->post_title) .
                ($post->post_status === 'draft' ? ' <span class="dendrila-simple-edit-status">(brouillon)</span>' : '') . '</summary>';
            if ($post->post_status === 'publish') {
                echo '<p><a class="dendrila-simple-edit-live-link" href="' . esc_url(get_permalink($post)) . '" target="_blank" rel="noopener noreferrer">Voir sur le site <span class="screen-reader-text">(nouvel onglet)</span></a></p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<p>' . (Dendrila_Simple_Edit_Approvals::required($type) && !current_user_can('manage_options') ?
                'Après enregistrement, un responsable devra valider les modifications avant leur publication.' :
                'Les modifications seront visibles sur le site après enregistrement.') . '</p>';
            $suffix = (int) $id;
            echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-paged-title-' . esc_attr((string) $suffix) . '">' . esc_html($title_label) . '</label>';
            echo '<input type="text" id="dendrila-simple-edit-paged-title-' . esc_attr((string) $suffix) . '" name="dendrila_simple_edit_paged_title" required maxlength="180" value="' .
                esc_attr($post->post_title) . '"></div>';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_post_save">';
            echo '<input type="hidden" name="dendrila_simple_edit_paged_id" value="' . esc_attr((string) $suffix) . '">';
            echo '<input type="hidden" name="dendrila_simple_edit_paged_version" value="' . esc_attr(self::fingerprint($post)) . '">';
            wp_nonce_field('dendrila_simple_edit_post_save');
            if (self::own_created($post) && self::can_create_type($post->post_type)) {
                echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-paged-status-' . esc_attr((string) $suffix) . '">Publication</label>';
                echo '<select id="dendrila-simple-edit-paged-status-' . esc_attr((string) $suffix) . '" name="dendrila_simple_edit_paged_status">';
                echo '<option value="draft" ' . selected($post->post_status, 'draft', false) . '>Conserver en brouillon</option>';
                if (self::can_publish_type($post->post_type) || $post->post_status === 'publish') {
                    echo '<option value="publish" ' . selected($post->post_status, 'publish', false) . '>Publier sur le site</option>';
                } elseif (Dendrila_Simple_Edit_Approvals::required($post->post_type)) {
                    echo '<option value="review">Soumettre à validation pour publier</option>';
                }
                echo '</select></div>';
            }
            if ($type === 'post') { self::illustration_form($post); }
            echo '<div class="dendrila-simple-edit-block-editor" data-dendrila-simple-edit-block-editor data-allow-title="0">';
            echo '<label for="dendrila-simple-edit-paged-body-' . esc_attr((string) $suffix) . '">' . esc_html($content_label) . '</label>';
            echo '<textarea id="dendrila-simple-edit-paged-body-' . esc_attr((string) $suffix) . '" data-dendrila-simple-edit-editor-source name="dendrila_simple_edit_paged_body" rows="10">' .
                esc_textarea($post->post_content) . '</textarea></div>';
            echo '<p><button type="submit" class="button button-primary">Enregistrer les modifications</button></p></form>';
            // WordPress revisions track previous title/body content without storing
            // duplicate copies of posts inside the plugin.
            $revisions = wp_get_post_revisions($id, array('posts_per_page' => 5, 'orderby' => 'date', 'order' => 'DESC'));
            if ($revisions) {
                echo '<details class="dendrila-simple-edit-revision-list"><summary>Historique des modifications</summary><ul>';
                foreach ($revisions as $revision) {
                    $when = get_the_date('d/m/Y H:i', $revision);
                    $who = get_the_author_meta('display_name', (int) $revision->post_author);
                    echo '<li>' . esc_html($when) . ($who ? ' — ' . esc_html($who) : '') . '</li>';
                }
                echo '</ul></details>';
            }
            echo '</details>';
        }
        if (!$shown) { echo '<p class="notice notice-warning">Aucun contenu compatible accessible dans cette rubrique.</p>'; }
        echo '</section>';
    }
}
