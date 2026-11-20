<?php
/**
 * Narrow Advanced Custom Fields adapter.
 * Only explicitly configured published-post text fields can be modified.
 * Never exposes a generic post-meta endpoint to the delegated role.
 */
if (!defined('ABSPATH')) { exit; }

final class DSE_ACF
{
    const OPTION = 'dse_acf_mappings';
    const TYPES = array('text', 'textarea', 'email', 'url');

    public static function boot()
    {
        add_action('admin_post_dse_acf_add', array(__CLASS__, 'add'));
        add_action('admin_post_dse_acf_save', array(__CLASS__, 'save'));
        add_action('admin_post_dse_acf_remove', array(__CLASS__, 'remove'));
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
        return DSE_Plugin::can_edit();
    }

    public static function add()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_acf_add');
        $raw_post = isset($_POST['dse_post_id']) ? wp_unslash($_POST['dse_post_id']) : '';
        $key = isset($_POST['dse_acf_key']) ? wp_unslash($_POST['dse_acf_key']) : '';
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
                self::redirect('acf_duplicate');
            }
        }
        $id = 'acf_' . bin2hex(random_bytes(8));
        $all[$id] = array('post_id' => $post_id, 'field_key' => $key);
        if (!update_option(self::OPTION, $all, false)) {
            wp_die('Association non enregistrée.', 'Erreur', array('response' => 500));
        }
        self::redirect('acf_added');
    }

    public static function remove()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_acf_remove');
        $id = isset($_POST['dse_mapping']) ? wp_unslash($_POST['dse_mapping']) : null;
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
        check_admin_referer('dse_acf_save');
        $id = isset($_POST['dse_mapping']) ? wp_unslash($_POST['dse_mapping']) : null;
        $expected = isset($_POST['dse_acf_version']) ? wp_unslash($_POST['dse_acf_version']) : null;
        $input = isset($_POST['dse_acf_value']) ? $_POST['dse_acf_value'] : null;
        if (!is_string($id) || !is_string($expected) ||
            preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            wp_die('Requête incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        $resolved = self::mapped($id);
        if (!$resolved) {
            wp_die('Cette association n’est plus autorisée.', 'Accès refusé', array('response' => 403));
        }
        $all = self::mappings();
        $post_id = $all[$id]['post_id'];
        $current = self::raw_value($resolved, $post_id);
        if ($current === false || !hash_equals(self::version($current), $expected)) {
            self::redirect('acf_conflict');
        }
        $new = self::sanitize($resolved['field'], $input);
        if ($new === false) {
            wp_die('La valeur ne respecte pas le type ou la longueur autorisée.', 'Requête incorrecte', array('response' => 400));
        }
        if ($current === $new) {
            self::redirect('acf_unchanged');
        }
        // Re-evaluate just before mutation. This guards stale forms, but does not
        // claim atomicity against a separate editor writing in the same instant.
        if (!self::mapped($id) ||
            self::raw_value($resolved, $post_id) !== $current) {
            self::redirect('acf_conflict');
        }
        $updated = update_field($resolved['field']['key'], $new, $post_id);
        $observed = self::raw_value($resolved, $post_id);
        if ($observed !== $new) {
            self::redirect('acf_error');
        }
        clean_post_cache($post_id);
        do_action('dse_acf_field_updated', $post_id, $resolved['field']['key']);
        self::redirect('acf_saved');
    }

    private static function redirect($notice)
    {
        wp_safe_redirect(add_query_arg('dse_notice', $notice, admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function render()
    {
        if (!DSE_Plugin::can_access()) { return; }
        echo '<section class="dse-card"><h2>Champs personnalisés ACF</h2>';
        if (!self::available()) {
            echo '<p>Advanced Custom Fields n’est pas actif. Activez ACF pour relier un champ de texte existant.</p></section>';
            return;
        }
        $all = self::mappings();
        if (!$all) {
            echo '<p>Aucun champ ACF autorisé pour le moment.</p>';
        }
        foreach ($all as $id => $mapping) {
            $resolved = self::mapped($id);
            if (!$resolved) {
                echo '<p class="notice-inline">Une association ACF est devenue indisponible ou non prise en charge. Aucune écriture n’est possible.</p>';
                continue;
            }
            $value = self::raw_value($resolved, $mapping['post_id']);
            if ($value === false) {
                echo '<p>Valeur complexe : écriture désactivée pour ce champ.</p>';
                continue;
            }
            $field = $resolved['field'];
            echo '<h3>' . esc_html($field['label'] ?? $field['name']) . '</h3>';
            echo '<p>Contenu : <a href="' . esc_url(get_permalink($mapping['post_id'])) .
                '" target="_blank" rel="noopener noreferrer">' .
                esc_html(get_the_title($mapping['post_id'])) . '</a></p>';
            if (current_user_can('manage_options')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="dse_acf_remove">';
                echo '<input type="hidden" name="dse_mapping" value="' . esc_attr($id) . '">';
                wp_nonce_field('dse_acf_remove');
                echo '<button class="button button-secondary" type="submit">Retirer cette autorisation</button></form>';
            }
            if (!self::editable()) { continue; }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dse_acf_save">';
            echo '<input type="hidden" name="dse_mapping" value="' . esc_attr($id) . '">';
            echo '<input type="hidden" name="dse_acf_version" value="' . esc_attr(self::version($value)) . '">';
            wp_nonce_field('dse_acf_save');
            $element_id = 'dse-acf-' . $id;
            echo '<label class="screen-reader-text" for="' . esc_attr($element_id) . '">' .
                esc_html($field['label'] ?? $field['name']) . '</label>';
            if ($field['type'] === 'textarea') {
                echo '<textarea id="' . esc_attr($element_id) . '" name="dse_acf_value" rows="4">' .
                    esc_textarea($value) . '</textarea>';
            } else {
                $type = in_array($field['type'], array('email', 'url'), true) ? $field['type'] : 'text';
                echo '<input id="' . esc_attr($element_id) . '" type="' . esc_attr($type) .
                    '" name="dse_acf_value" value="' . esc_attr($value) . '">';
            }
            echo '<p><button type="submit" class="button button-primary">Enregistrer ce champ</button></p></form>';
        }
        if (current_user_can('manage_options')) {
            echo '<hr><h3>Autoriser un champ ACF</h3>';
            echo '<p>Choisissez d’abord le contenu. Les champs textuels prévus pour ce contenu seront proposés, même s’ils sont vides. Les clés peuvent aussi être ajoutées manuellement.</p>';
            $types = get_post_types(array('public' => true), 'names');
            $recent = get_posts(array(
                'post_type' => array_values($types),
                'post_status' => 'publish',
                'posts_per_page' => 40,
                'no_found_rows' => true,
                'orderby' => 'modified',
                'order' => 'DESC',
            ));
            echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
            echo '<input type="hidden" name="page" value="dendrila-simple-edit">';
            echo '<div class="dse-field"><label for="dse-acf-discover">Contenu à examiner</label>';
            $requested = isset($_GET['dse_acf_post']) ? wp_unslash($_GET['dse_acf_post']) : null;
            $chosen = is_string($requested) && ctype_digit($requested) && (int) $requested > 0 ?
                (string) (int) $requested : '';
            echo '<select id="dse-acf-discover" name="dse_acf_post">';
            echo '<option value="">Choisir un contenu public…</option>';
            if (is_array($recent)) {
                foreach ($recent as $entry) {
                    if ($entry->post_password !== '') { continue; }
                    echo '<option value="' . esc_attr((string) $entry->ID) . '"' .
                        selected((string) $entry->ID, $chosen, false) . '>' .
                        esc_html(get_the_title($entry->ID) . ' (#' . $entry->ID . ')') . '</option>';
                }
            }
            echo '</select></div><p><button class="button" type="submit">Afficher ses champs ACF</button></p></form>';
            if ($chosen !== '') {
                $post_id = (int) $chosen;
                $fields = self::discover($post_id);
                echo '<h4>Champs disponibles pour ce contenu</h4>';
                foreach ($fields as $field) {
                    echo '<form class="dse-acf-discovered" method="post" action="' .
                        esc_url(admin_url('admin-post.php')) . '">';
                    echo '<span>' . esc_html($field['label'] ?? $field['name']) . '</span>';
                    echo '<input type="hidden" name="action" value="dse_acf_add">';
                    echo '<input type="hidden" name="dse_post_id" value="' .
                        esc_attr((string) $post_id) . '">';
                    echo '<input type="hidden" name="dse_acf_key" value="' .
                        esc_attr($field['key']) . '">';
                    wp_nonce_field('dse_acf_add');
                    echo '<button class="button" type="submit">Autoriser ce champ</button></form>';
                }
                if (!$fields) {
                    echo '<p>Aucun champ simple autorisable n’a été trouvé. Vous pouvez utiliser sa clé manuellement ci-dessous.</p>';
                }
            }
            echo '<p>Saisissez l’identifiant d’un contenu public et la clé ACF (commençant par field_). Types admis : texte, zone de texte, e-mail, URL.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dse_acf_add">';
            wp_nonce_field('dse_acf_add');
            echo '<div class="dse-field"><label for="dse-acf-post">Identifiant du contenu</label>';
            echo '<input id="dse-acf-post" type="number" min="1" name="dse_post_id" required></div>';
            echo '<div class="dse-field"><label for="dse-acf-key">Clé du champ ACF</label>';
            echo '<input id="dse-acf-key" type="text" name="dse_acf_key" pattern="field_[A-Za-z0-9_]+" required placeholder="field_..."></div>';
            echo '<p><button type="submit" class="button">Autoriser ce champ</button></p></form>';
        }
        echo '<p class="description">Les répéteurs, champs relationnels, galeries, mises en page flexibles et champs privés ne sont pas modifiables ici. L’enregistrement ne modifie pas le modèle de page.</p></section>';
    }
}
