<?php
/**
 * Narrow Advanced Custom Fields adapter.
 * Only explicitly configured published-post text fields can be modified.
 * Never exposes a generic post-meta endpoint to the delegated role.
 */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_ACF
{
    const OPTION = 'dendrila_simple_edit_acf_mappings';
    const TYPES = array('text', 'textarea', 'email', 'url');

    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_acf_add', array(__CLASS__, 'add'));
        add_action('admin_post_dendrila_simple_edit_acf_save', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_acf_remove', array(__CLASS__, 'remove'));
    }

    public static function available()
    {
        return function_exists('acf_get_field') && function_exists('get_field') && function_exists('update_field');
    }

    public static function mappings()
    {
        $mappings = get_option(self::OPTION, array());
        return is_array($mappings) ? $mappings : array();
    }

    /** Resolve the exact permitted object/field. Refuse unknown or complex ACF types. */
    public static function resolve($post_id, $field_key)
    {
        if (!self::available() || !is_int($post_id) || $post_id < 1 ||
            !is_string($field_key) || preg_match('/^field_[a-zA-Z0-9_]+$/', $field_key) !== 1) {
            return false;
        }
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_password !== '' ||
            in_array($post->post_type, array('attachment', 'revision', 'nav_menu_item'), true)) {
            return false;
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || empty($type->public)) {
            return false;
        }
        $field = acf_get_field($field_key);
        if (!is_array($field) || ($field['key'] ?? '') !== $field_key ||
            !in_array($field['type'] ?? '', self::TYPES, true) ||
            !is_string($field['name'] ?? null) ||
            preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,99}$/', $field['name']) !== 1 ||
            !empty($field['conditional_logic']) || !empty($field['readonly']) || !empty($field['disabled'])) {
            return false;
        }
        // Nested fields belong to repeaters/groups/flexible layouts and must never
        // be written as independent postmeta values.
        $parent = $field['parent'] ?? null;
        $parent_is_group = is_string($parent) && strpos($parent, 'group_') === 0;
        $parent_is_post_group = is_numeric($parent) &&
            get_post_type((int) $parent) === 'acf-field-group';
        if (!$parent_is_group && !$parent_is_post_group) {
            return false;
        }
        // A key copied into the advanced form must not bypass the ACF field group's
        // location rules. Match both the applicable group and its direct field.
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return false;
        }
        $groups = acf_get_field_groups(array('post_id' => $post_id, 'post_type' => $post->post_type));
        $allowed_here = false;
        foreach ((array) $groups as $group) {
            if (!is_array($group)) { continue; }
            $same_group = ($parent_is_group && ($group['key'] ?? null) === $parent) ||
                ($parent_is_post_group && isset($group['ID']) && (int) $group['ID'] === (int) $parent);
            if (!$same_group) { continue; }
            foreach ((array) acf_get_fields($group) as $candidate) {
                if (is_array($candidate) && ($candidate['key'] ?? null) === $field_key) {
                    $allowed_here = true;
                    break;
                }
            }
            if ($allowed_here) { break; }
        }
        if (!$allowed_here) { return false; }
        return array('post' => $post, 'field' => $field);
    }

    public static function mapped($mapping_id)
    {
        $all = self::mappings();
        if (!is_string($mapping_id) || !preg_match('/^acf_[a-f0-9]{16}$/', $mapping_id) ||
            !isset($all[$mapping_id]) || !is_array($all[$mapping_id])) {
            return false;
        }
        $mapping = $all[$mapping_id];
        if (!is_int($mapping['post_id'] ?? null) || !is_string($mapping['field_key'] ?? null)) {
            return false;
        }
        return self::resolve($mapping['post_id'], $mapping['field_key']);
    }

    public static function discover($post_id)
    {
        if (!self::available() || !function_exists('acf_get_field_groups') ||
            !function_exists('acf_get_fields') || !is_int($post_id) || $post_id < 1) {
            return array();
        }
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_password !== '') {
            return array();
        }
        $fields = array();
        $groups = acf_get_field_groups(array('post_id' => $post_id, 'post_type' => $post->post_type));
        foreach ((array) $groups as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                $resolved = self::resolve($post_id, $field['key'] ?? null);
                if (!$resolved || self::raw_value($resolved, $post_id) === false) {
                    continue;
                }
                $fields[$resolved['field']['key']] = $resolved['field'];
                if (count($fields) >= 80) {
                    return $fields;
                }
            }
        }
        return $fields;
    }

    private static function editable()
    {
        return Dendrila_Simple_Edit_Plugin::can_edit();
    }

    public static function add()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_acf_add');
        $raw_post = isset($_POST['dendrila_simple_edit_post_id']) ? wp_unslash($_POST['dendrila_simple_edit_post_id']) : '';
        $key = isset($_POST['dendrila_simple_edit_acf_key']) ? wp_unslash($_POST['dendrila_simple_edit_acf_key']) : '';
        if (!is_string($raw_post) || !ctype_digit($raw_post) || !is_string($key)) {
            wp_die('Identifiant ou clé ACF incorrect.', 'Requête incorrecte', array('response' => 400));
        }
        $post_id = (int) $raw_post;
        $target = self::resolve($post_id, $key);
        if (!$target) {
            wp_die('Champ non pris en charge ou contenu non public.', 'Requête incorrecte', array('response' => 400));
        }
        $all = self::mappings();
        if (count($all) >= 30) {
            wp_die('Limite de 30 champs ACF atteinte.', 'Requête incorrecte', array('response' => 400));
        }
        foreach ($all as $mapping) {
            if (is_array($mapping) && ($mapping['post_id'] ?? null) === $post_id &&
                ($mapping['field_key'] ?? null) === $key) {
                self::redirect('acf_duplicate', $post_id);
            }
        }
        $id = 'acf_' . bin2hex(random_bytes(8));
        $all[$id] = array('post_id' => $post_id, 'field_key' => $key);
        if (!update_option(self::OPTION, $all, false)) {
            wp_die('Association non enregistrée.', 'Erreur', array('response' => 500));
        }
        self::redirect('acf_added', $post_id);
    }

    public static function remove()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_acf_remove');
        $id = isset($_POST['dendrila_simple_edit_mapping']) ? wp_unslash($_POST['dendrila_simple_edit_mapping']) : null;
        $all = self::mappings();
        if (!is_string($id) || !preg_match('/^acf_[a-f0-9]{16}$/', $id) ||
            !isset($all[$id])) {
            wp_die('Association non trouvée.', 'Requête incorrecte', array('response' => 400));
        }
        unset($all[$id]);
        update_option(self::OPTION, $all, false);
        self::redirect('acf_removed');
    }

    public static function raw_value($resolved, $post_id)
    {
        // ACF raw, unformatted values only. Avoid HTML formatting / returned arrays.
        $value = get_field($resolved['field']['key'], $post_id, false);
        return is_string($value) ? $value : ($value === null || $value === false ? '' : false);
    }

    public static function version($value)
    {
        return hash('sha256', (string) $value);
    }

    private static function sanitize($field, $input)
    {
        if (!is_string($input)) { return false; }
        $input = wp_unslash($input);
        $kind = $field['type'];
        if ($kind === 'textarea') {
            $out = sanitize_textarea_field($input);
        } elseif ($kind === 'email') {
            $out = sanitize_email($input);
            if ($out !== '' && !is_email($out)) { return false; }
        } elseif ($kind === 'url') {
            $out = esc_url_raw($input, array('https', 'http'));
            if ($out !== '' && (!filter_var($out, FILTER_VALIDATE_URL) ||
                !in_array(strtolower((string) parse_url($out, PHP_URL_SCHEME)), array('http', 'https'), true))) {
                return false;
            }
        } else {
            $out = sanitize_text_field($input);
        }
        if ($out === '' && trim($input) !== '') { return false; }
        $cap = $kind === 'textarea' ? 3000 : 300;
        $max = isset($field['maxlength']) && (int) $field['maxlength'] > 0 ?
            min($cap, (int) $field['maxlength']) : $cap;
        $length = function_exists('mb_strlen') ? mb_strlen($out, 'UTF-8') : strlen($out);
        return $length <= $max && strlen($out) <= $cap * 4 ? $out : false;
    }

    public static function save()
    {
        if (!self::editable()) {
            wp_die('Vous ne pouvez pas modifier ce champ.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_acf_save');
        $id = isset($_POST['dendrila_simple_edit_mapping']) ? wp_unslash($_POST['dendrila_simple_edit_mapping']) : null;
        $expected = isset($_POST['dendrila_simple_edit_acf_version']) ? wp_unslash($_POST['dendrila_simple_edit_acf_version']) : null;
        $input = isset($_POST['dendrila_simple_edit_acf_value']) ? $_POST['dendrila_simple_edit_acf_value'] : null;
        if (!is_string($id) || !is_string($expected) ||
            preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            wp_die('Requête incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        $all = self::mappings();
        $post_id = (int) ($all[$id]['post_id'] ?? 0);
        $field_key = $all[$id]['field_key'] ?? '';
        if ($id === '' && current_user_can('manage_options')) {
            $post_id = absint($_POST['dendrila_simple_edit_post_id'] ?? 0);
            $field_key = isset($_POST['dendrila_simple_edit_acf_key']) ? wp_unslash($_POST['dendrila_simple_edit_acf_key']) : '';
        }
        $resolved = $id === '' && current_user_can('edit_post', $post_id) ?
            self::resolve($post_id, $field_key) : self::mapped($id);
        if (!$resolved) {
            wp_die('Ce texte ne peut pas être modifié.', 'Accès refusé', array('response' => 403));
        }
        $current = self::raw_value($resolved, $post_id);
        if ($current === false || !hash_equals(self::version($current), $expected)) {
            self::redirect('acf_conflict', $post_id);
        }
        $new = self::sanitize($resolved['field'], $input);
        if ($new === false) {
            wp_die('La valeur ne respecte pas le type ou la longueur autorisée.', 'Requête incorrecte', array('response' => 400));
        }
        if ($current === $new) {
            self::redirect('acf_unchanged', $post_id);
        }
        // Re-evaluate just before mutation. This guards stale forms, but does not
        // claim atomicity against a separate editor writing in the same instant.
        if (!($id === '' ? self::resolve($post_id, $field_key) : self::mapped($id)) ||
            self::raw_value($resolved, $post_id) !== $current) {
            self::redirect('acf_conflict', $post_id);
        }
        update_field($resolved['field']['key'], $new, $post_id);
        $observed = self::raw_value($resolved, $post_id);
        if ($observed !== $new) {
            self::redirect('acf_error', $post_id);
        }
        clean_post_cache($post_id);
        do_action('dendrila_simple_edit_acf_field_updated', $post_id, $resolved['field']['key']);
        self::redirect('acf_saved', $post_id);
    }

    private static function redirect($notice, $post_id = 0)
    {
        $tab = in_array($notice, array('acf_added', 'acf_removed', 'acf_duplicate'), true) ? 'reglages' : 'contenus';
        $args = array('dendrila_simple_edit_notice' => $notice, 'dendrila_simple_edit_tab' => $tab);
        if ($post_id) { $args['dendrila_simple_edit_acf_post'] = $post_id; }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=dendrila-simple-edit')) . '#dendrila-simple-edit-acf-content');
        exit;
    }

    private static function render_remove($id)
    {
        if (!current_user_can('manage_options') || !preg_match('/^acf_[a-f0-9]{16}$/', (string) $id)) { return; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_acf_remove">';
        echo '<input type="hidden" name="dendrila_simple_edit_mapping" value="' . esc_attr($id) . '">';
        wp_nonce_field('dendrila_simple_edit_acf_remove');
        echo '<button class="button button-secondary" type="submit">Retirer l’accès à ce champ</button></form>';
    }

    private static function edit_field($id, $post_id, $field, $value)
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_acf_save">';
        echo '<input type="hidden" name="dendrila_simple_edit_mapping" value="' . esc_attr($id) . '">';
        echo '<input type="hidden" name="dendrila_simple_edit_acf_version" value="' . esc_attr(self::version($value)) . '">';
        if ($id === '') {
            echo '<input type="hidden" name="dendrila_simple_edit_post_id" value="' . esc_attr($post_id) . '">';
            echo '<input type="hidden" name="dendrila_simple_edit_acf_key" value="' . esc_attr($field['key']) . '">';
        }
        wp_nonce_field('dendrila_simple_edit_acf_save');
        $element_id = 'dendrila-simple-edit-acf-' . md5($post_id . ':' . $field['key']);
        echo '<label for="' . esc_attr($element_id) . '">' .
            esc_html($field['label'] ?? $field['name']) . '</label>';
        if (!empty($field['instructions']) && is_string($field['instructions'])) {
            echo '<p class="description">' . esc_html($field['instructions']) . '</p>';
        }
        if ($field['type'] === 'textarea') {
            echo '<textarea id="' . esc_attr($element_id) . '" name="dendrila_simple_edit_acf_value" rows="4">' .
                esc_textarea($value) . '</textarea>';
        } else {
            $type = in_array($field['type'], array('email', 'url'), true) ? $field['type'] : 'text';
            echo '<input id="' . esc_attr($element_id) . '" type="' . esc_attr($type) .
                '" name="dendrila_simple_edit_acf_value" value="' . esc_attr($value) . '">';
        }
        echo '<p><button type="submit" class="button button-primary">Enregistrer ce texte</button></p></form>';
    }

    public static function render_fields_for($post_id)
    {
        if (!Dendrila_Simple_Edit_Plugin::can_edit() || !self::available()) { return; }
        foreach (self::mappings() as $id=>$mapping) {
            if ((int)($mapping['post_id'] ?? 0) !== (int)$post_id) { continue; }
            $resolved = self::mapped($id);
            if (!$resolved) { continue; }
            $value = self::raw_value($resolved,$post_id);
            if ($value !== false) { self::edit_field($id,$post_id,$resolved['field'],$value); }
        }
    }

    public static function render($permissions = false)
    {
        $admin = current_user_can('manage_options');
        if (!Dendrila_Simple_Edit_Plugin::can_access() || ($permissions && !$admin)) { return; }
        if (!self::available() && !$permissions) { return; }

        $tab = $permissions ? 'reglages' : 'contenus';
        echo '<section id="dendrila-simple-edit-acf-content" class="dendrila-simple-edit-card">';
        echo '<h2>' . ($permissions ? 'Autoriser les informations complémentaires' : 'Informations complémentaires') . '</h2>';
        if (!$permissions) {
            echo '<p class="description">Vous pouvez modifier ici des informations enregistrées en plus du texte principal. Elles n’apparaissent sur le site que si la page ou le thème les affiche. Pour modifier directement une page ou un article, ouvrez la rubrique <a href="' . esc_url(admin_url('admin.php?page=dendrila-simple-edit&dendrila_simple_edit_tab=pages')) . '">Pages et articles</a>.</p>';
        }
        $selected_post = isset($_GET['dendrila_simple_edit_acf_post']) && is_string($_GET['dendrila_simple_edit_acf_post']) ?
            absint(wp_unslash($_GET['dendrila_simple_edit_acf_post'])) : 0;
        $mappings = self::mappings();
        $mapped_fields = array();
        $posts = array();
        foreach ($mappings as $id => $mapping) {
            $resolved = self::mapped($id);
            if (!$resolved) {
                if ($permissions) {
                    echo '<p>Champ indisponible sur le contenu n°' . esc_html((string) ($mapping['post_id'] ?? 0)) . '.</p>';
                    self::render_remove($id);
                }
                continue;
            }
            $post = $resolved['post'];
            $posts[$post->ID] = $post;
            $mapped_fields[$post->ID][$resolved['field']['key']] = $id;
        }
        if ($admin && self::available()) {
            $recent = get_posts(array('post_type' => array_values(get_post_types(array('public' => true), 'names')),
                'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 40, 'orderby' => 'modified'));
            foreach ($recent as $post) {
                // Keep the chooser focused on posts that actually expose editable ACF fields.
                if (isset($posts[$post->ID]) || self::discover((int) $post->ID)) { $posts[$post->ID] = $post; }
            }
        }
        if (!$posts) {
            echo '<p>Aucun contenu à afficher.</p></section>';
            return;
        }
        echo '<form method="get" action="' . esc_url(admin_url('admin.php') . '#dendrila-simple-edit-acf-content') . '">';
        echo '<input type="hidden" name="page" value="dendrila-simple-edit">';
        echo '<input type="hidden" name="dendrila_simple_edit_tab" value="' . esc_attr($tab) . '">';
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-acf-content-select">Choisir un contenu</label>';
        echo '<select id="dendrila-simple-edit-acf-content-select" name="dendrila_simple_edit_acf_post" required><option value="">Choisir un contenu</option>';
        foreach ($posts as $post) {
            $type = get_post_type_object($post->post_type);
            echo '<option value="' . esc_attr($post->ID) . '"' . selected($selected_post, $post->ID, false) . '>' .
                esc_html(($type->labels->singular_name ?? $post->post_type) . ' · ' . get_the_title($post)) . '</option>';
        }
        echo '</select></div><p><button data-dendrila-simple-edit-acf-open class="button" type="submit">Voir les informations</button></p></form>';
        if (isset($posts[$selected_post])) {
            $post = $posts[$selected_post];
            echo '<h3>' . esc_html(get_the_title($post)) . '</h3>';
            echo '<p><a href="' . esc_url(get_permalink($post)) . '">Voir sur le site</a></p>';
            $notice = isset($_GET['dendrila_simple_edit_notice']) && is_string($_GET['dendrila_simple_edit_notice']) ? sanitize_key(wp_unslash($_GET['dendrila_simple_edit_notice'])) : '';
            if (!$permissions) {
                $feedback = array(
                    'acf_saved' => array('success', 'Enregistré dans WordPress : votre nouvelle valeur a bien été conservée.'),
                    'acf_unchanged' => array('info', 'Aucun changement : cette valeur est déjà enregistrée.'),
                    'acf_conflict' => array('error', 'Cette information a changé entre-temps. Rechargez la page avant de réessayer.'),
                    'acf_error' => array('error', 'L’enregistrement n’a pas pu être confirmé. Réessayez.'),
                );
                if (isset($feedback[$notice])) {
                    echo '<div class="notice notice-' . esc_attr($feedback[$notice][0]) . ' dendrila-simple-edit-acf-feedback" role="status"><p>' . esc_html($feedback[$notice][1]) . '</p></div>';
                }
            }
            $fields = $admin ? self::discover($selected_post) : array();
            foreach ($mapped_fields[$selected_post] ?? array() as $key => $id) {
                $resolved = self::mapped($id);
                if (!isset($fields[$key]) && $resolved) { $fields[$key] = $resolved['field']; }
            }
            if (!$fields) { echo '<p>Aucune information complémentaire modifiable sur ce contenu.</p>'; }
            elseif (!$permissions) { echo '<p class="description">' . count($fields) . (count($fields) === 1 ? ' information modifiable' : ' informations modifiables') . ' pour ce contenu.</p>'; }
            foreach ($fields as $key => $field) {
                $id = $mapped_fields[$selected_post][$key] ?? '';
                echo '<div class="dendrila-simple-edit-field">';
                if ($permissions) {
                    echo '<strong>' . esc_html($field['label'] ?? $field['name']) . '</strong>';
                    if ($id !== '') {
                        self::render_remove($id);
                    } else {
                        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                        echo '<input type="hidden" name="action" value="dendrila_simple_edit_acf_add">';
                        echo '<input type="hidden" name="dendrila_simple_edit_post_id" value="' . esc_attr($selected_post) . '">';
                        echo '<input type="hidden" name="dendrila_simple_edit_acf_key" value="' . esc_attr($key) . '">';
                        wp_nonce_field('dendrila_simple_edit_acf_add');
                        echo '<button class="button" type="submit">Donner accès à ce champ</button></form>';
                    }
                } else {
                    $value = self::raw_value(array('field' => $field), $selected_post);
                    if ($value !== false && self::editable() && ($id !== '' || ($admin && current_user_can('edit_post', $selected_post)))) {
                        self::edit_field($id, $selected_post, $field, $value);
                    } else {
                        echo '<strong>' . esc_html($field['label'] ?? $field['name']) . '</strong>';
                        echo '<p>' . esc_html($value === false ? 'Champ indisponible' : $value) . '</p>';
                    }
                }
                echo '</div>';
            }
        }
        echo '</section>';
    }
}
