<?php
/**
 * Image permissions for Dendrila Simple Edit.
 * Simple editors may upload images and, by default, share them across the same
 * limited role. Administrators can restrict collaboration, allow selected other
 * images or open the full library. No policy makes attachments private URLs.
 * This governs editing/selection, not public media file URLs.
 */
if (!defined('ABSPATH')) { exit; }

final class DSE_Media
{
    const OPTION = 'dse_media_allowlist';
    const ALL_OPTION = 'dse_media_full_library';
    const TEAM_OPTION = 'dse_media_share_simple_editors';

    public static function boot()
    {
        add_action('admin_post_dse_media_configure', array(__CLASS__, 'configure'));
        // Scope the native WordPress media modal and REST media listing on the server.
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'scope_attachment_query'));
        add_filter('rest_attachment_query', array(__CLASS__, 'scope_attachment_query'));
        add_filter('posts_where', array(__CLASS__, 'scope_attachment_where'), 10, 2);
    }

    public static function full_library_enabled()
    {
        return get_option(self::ALL_OPTION, false) === '1';
    }

    /** Sharing is enabled for existing installs until an administrator opts out. */
    public static function team_sharing_enabled()
    {
        return get_option(self::TEAM_OPTION, '1') === '1';
    }

    private static function limited_user()
    {
        return current_user_can('dse_access') && !current_user_can('manage_options');
    }

    /** Share only uploads by users who have the dedicated, non-admin role. */
    private static function is_team_author($user_id)
    {
        if ($user_id < 1 || !function_exists('get_userdata')) { return false; }
        $author = get_userdata($user_id);
        return $author && in_array('dendrila_simple_editor', (array) $author->roles, true) &&
            !user_can($author, 'manage_options');
    }

    /** List eligible authors once per request for server-side media queries. */
    private static function team_author_ids()
    {
        static $ids = null;
        if ($ids !== null) { return $ids; }
        $ids = array(get_current_user_id());
        if (function_exists('get_users')) {
            $accounts = get_users(array('role' => 'dendrila_simple_editor', 'fields' => 'ID'));
            foreach ((array) $accounts as $account_id) {
                $account_id = (int) $account_id;
                if (self::is_team_author($account_id)) { $ids[] = $account_id; }
            }
        }
        return $ids = array_values(array_unique(array_filter($ids)));
    }

    public static function curated_ids()
    {
        $raw = get_option(self::OPTION, array());
        if (!is_array($raw)) { return array(); }
        $results = array();
        foreach (array_slice($raw, 0, 60) as $id) {
            if (is_int($id) && $id > 0 && wp_attachment_is_image($id)) {
                $results[] = $id;
            }
        }
        return array_values(array_unique($results));
    }

    public static function allowed_image($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id < 1 || !wp_attachment_is_image($attachment_id)) { return false; }
        if (current_user_can('manage_options')) { return true; }
        if (!self::limited_user()) { return false; }
        if (self::full_library_enabled() || in_array($attachment_id, self::curated_ids(), true)) {
            return true;
        }
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') { return false; }
        $author_id = (int) $attachment->post_author;
        return $author_id === get_current_user_id() ||
            (self::team_sharing_enabled() && self::is_team_author($author_id));
    }

    public static function sanitize_selection($raw)
    {
        if (!is_string($raw) || ($raw !== '' && !ctype_digit($raw))) {
            throw new InvalidArgumentException('Sélection d’image incorrecte.');
        }
        if ($raw === '') { return ''; }
        $id = (int) $raw;
        if (!self::allowed_image($id)) {
            throw new InvalidArgumentException('Cette image n’est pas accessible à votre compte.');
        }
        return (string) $id;
    }

    /**
     * Prevent the native picker from listing unapproved images. Applies to both
     * WordPress media AJAX and /wp/v2/media collections, not just client JS.
     */
    public static function scope_attachment_query($args)
    {
        if (!self::limited_user() || self::full_library_enabled()) { return $args; }
        if (!is_array($args)) { return $args; }
        $args['dse_media_limited'] = true;
        $args['post_mime_type'] = 'image';
        return $args;
    }

    public static function scope_attachment_where($where, $query)
    {
        if (!self::limited_user() || self::full_library_enabled() ||
            !is_object($query) || !method_exists($query, 'get') ||
            !$query->get('dse_media_limited')) { return $where; }
        global $wpdb;
        $authors = self::team_sharing_enabled() ? self::team_author_ids() :
            array(get_current_user_id());
        $author_ids = array_map('absint', $authors);
        $owner_filter = "{$wpdb->posts}.post_author IN (" . implode(',', $author_ids) . ')';
        $curated = self::curated_ids();
        if ($curated) {
            // Values have already been validated as positive integer image IDs.
            $owner_filter .= " OR {$wpdb->posts}.ID IN (" . implode(',', array_map('absint', $curated)) . ')';
        }
        return $where . ' AND (' . $owner_filter . ')';
    }

    /** A bounded fallback selection; full media navigation is via WordPress's picker. */
    public static function accessible_ids($limit = 80)
    {
        $ids = self::curated_ids();
        if (current_user_can('manage_options') || self::full_library_enabled()) {
            $query = array('post_type' => 'attachment', 'post_status' => 'inherit',
                'post_mime_type' => 'image', 'numberposts' => $limit, 'fields' => 'ids',
                'orderby' => 'date', 'order' => 'DESC');
        } else {
            $query = array('post_type' => 'attachment', 'post_status' => 'inherit',
                'post_mime_type' => 'image', 'numberposts' => $limit, 'fields' => 'ids',
                'author__in' => self::team_sharing_enabled() ? self::team_author_ids() :
                    array(get_current_user_id()), 'orderby' => 'date', 'order' => 'DESC');
        }
        $latest = get_posts($query);
        foreach (is_array($latest) ? $latest : array() as $id) {
            if (wp_attachment_is_image((int) $id) && self::allowed_image((int) $id)) {
                $ids[] = (int) $id;
            }
        }
        return array_slice(array_values(array_unique($ids)), 0, $limit + 60);
    }

    public static function render_image($value)
    {
        if (!is_string($value) || !ctype_digit($value) || !wp_attachment_is_image((int) $value)) {
            return '';
        }
        // Already-published images remain visible after permissions change.
        return (string) wp_get_attachment_image((int) $value, 'large', false,
            array('loading' => 'lazy', 'decoding' => 'async'));
    }

    public static function render_picker($key, $value, $id)
    {
        $ids = self::accessible_ids();
        $current = $value !== '' ? (int) $value : 0;
        $editable = $current && self::allowed_image($current);
        echo '<div class="dse-photo-editor" data-dse-photo-picker>';
        echo '<select id="' . esc_attr($id) . '" name="dse_fields[' . esc_attr($key) .
            ']" aria-describedby="' . esc_attr($id . '-help') . '" data-dse-photo-select>';
        echo '<option value="">Aucune image</option>';
        if ($current && !$editable) {
            // Keep the old selection in the HTML; never accidentally erase it.
            echo '<option value="' . esc_attr((string) $current) . '" selected>' .
                'Image déjà affichée (conservée)</option>';
        } elseif ($current && !in_array($current, $ids, true)) {
            $ids[] = $current;
        }
        foreach ($ids as $attachment_id) {
            echo '<option value="' . esc_attr((string) $attachment_id) . '" ' .
                selected($current, $attachment_id, false) . '>' .
                esc_html(get_the_title($attachment_id)) . '</option>';
        }
        echo '</select>';
        echo '<div class="dse-photo-picker-actions">';
        echo '<button type="button" class="button button-secondary" data-dse-photo-open hidden>' .
            'Choisir ou importer une image</button>';
        echo '<button type="button" class="button button-link-delete" data-dse-photo-clear hidden>' .
            'Retirer cette image</button></div>';
        echo '<div class="dse-image-preview" data-dse-photo-preview>';
        if ($current && wp_attachment_is_image($current)) {
            echo wp_get_attachment_image($current, 'thumbnail', false, array('loading' => 'lazy'));
        }
        echo '</div>';
        if ($current && !$editable) {
            echo '<p class="description">L’image actuelle reste affichée. Vous pouvez choisir une autre image autorisée.</p>';
        }
        echo '</div>';
    }

    public static function render_uploads()
    {
        if (!self::limited_user() || !current_user_can('upload_files')) { return; }
        $team = self::team_sharing_enabled();
        echo '<section class="dse-card dse-own-images"><h2>' .
            ($team ? 'Images de l’équipe' : 'Mes images') . '</h2>';
        echo $team ? '<p>Vous pouvez ajouter et utiliser les images de tous les comptes « Édition simple ». ' .
            'Les images partagées sont disponibles dès leur import.</p>' :
            '<p>Vous pouvez ajouter et utiliser vos propres images immédiatement. ' .
            'Le partage avec les autres comptes a été désactivé par l’administration.</p>';
        echo '<p><button class="button button-secondary" type="button" data-dse-upload-open hidden>' .
            'Ajouter des images</button> <span class="description" data-dse-upload-status></span></p>';
        $latest = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit',
            'post_mime_type' => 'image', 'numberposts' => 18, 'fields' => 'ids',
            'author__in' => $team ? self::team_author_ids() : array(get_current_user_id()),
            'orderby' => 'date', 'order' => 'DESC'));
        if ($latest) {
            echo '<ul class="dse-own-image-list" aria-label="' .
                ($team ? 'Images de notre équipe' : 'Images ajoutées par mon compte') . '">';
            foreach ($latest as $image_id) {
                $image_id = (int) $image_id;
                if (!wp_attachment_is_image($image_id)) { continue; }
                echo '<li>' . wp_get_attachment_image($image_id, 'thumbnail', false,
                    array('loading' => 'lazy')) . '<span>' .
                    esc_html(get_the_title($image_id)) . '</span></li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="description">' . ($team ?
                'Aucune image ajoutée par l’équipe pour le moment.' :
                'Vous n’avez encore ajouté aucune image.') . '</p>';
        }
        echo '</section>';
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_media_configure');
        $raw = isset($_POST['dse_attachment_ids']) ? wp_unslash($_POST['dse_attachment_ids']) : '';
        if (!is_string($raw) || strlen($raw) > 1000) {
            wp_die('Liste d’images incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        $ids = array();
        $segments = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($segments) || count($segments) > 60) {
            wp_die('60 images maximum.', 'Requête incorrecte', array('response' => 400));
        }
        foreach ($segments as $segment) {
            if (!ctype_digit($segment) || (int) $segment < 1 ||
                !wp_attachment_is_image((int) $segment)) {
                wp_die('L’un des identifiants ne correspond pas à une image.', 'Requête incorrecte',
                    array('response' => 400));
            }
            $ids[] = (int) $segment;
        }
        update_option(self::OPTION, array_values(array_unique($ids)), false);
        update_option(self::ALL_OPTION, !empty($_POST['dse_media_full_library']) ? '1' : '0', false);
        update_option(self::TEAM_OPTION, !empty($_POST['dse_media_share_simple_editors']) ? '1' : '0', false);
        wp_safe_redirect(add_query_arg(array('dse_notice' => 'media_configured', 'dse_tab' => 'reglages'),
            admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        $ids = self::curated_ids();
        echo '<form class="dse-card dse-media-allowlist" data-dse-media-picker method="post" action="' .
            esc_url(admin_url('admin-post.php')) . '">';
        echo '<h2>Autorisations pour les images</h2>';
        echo '<p>Les personnes ayant le rôle « Édition simple » peuvent ajouter leurs images ' .
            'sans demander la permission. Choisissez quelles autres images elles pourront utiliser.</p>';
        echo '<input type="hidden" name="action" value="dse_media_configure">';
        wp_nonce_field('dse_media_configure');
        echo '<label class="dse-check dse-media-team-option"><input type="checkbox" ' .
            'name="dse_media_share_simple_editors" value="1" ' .
            checked(self::team_sharing_enabled(), true, false) . '>' .
            '<span><strong>Partager les images entre utilisateurs « Édition simple »</strong>' .
            '<small>Activé par défaut : chacun peut utiliser les images ajoutées par les autres. ' .
            'Décochez pour limiter chaque personne à ses propres images et à celles que vous autorisez.</small>' .
            '</span></label>';
        echo '<label class="dse-check dse-media-all-option"><input type="checkbox" name="dse_media_full_library" ' .
            'value="1" ' . checked(self::full_library_enabled(), true, false) . '>' .
            '<span><strong>Accès à toute la médiathèque</strong>' .
            '<small>Donne également accès aux images des administrateurs et des autres rôles, ' .
            'en plus de celles de l’équipe.</small></span></label>';
        echo '<div class="dse-media-curated"><h3>Ou choisir quelques images supplémentaires</h3>';
        echo '<p>Ajoutez ici les images particulières accessibles à tous les utilisateurs « Édition simple », ' .
            'même si elles ne font pas partie des images partagées par l’équipe.</p>';
        echo '<div class="dse-media-toolbar">';
        echo '<button type="button" class="button button-secondary" data-dse-media-open hidden ' .
            'aria-controls="dse-media-selected"><span class="dashicons dashicons-format-gallery" ' .
            'aria-hidden="true"></span> Choisir des images</button>';
        echo '<span class="dse-media-count" data-dse-media-count role="status" aria-live="polite">' .
            esc_html(sprintf(_n('%d image sélectionnée', '%d images sélectionnées', count($ids),
            'dendrila-simple-edit'), count($ids))) . '</span></div>';
        echo '<div class="dse-media-list-wrap"><h3>Images sélectionnées</h3>';
        echo '<ul class="dse-media-list" data-dse-media-list id="dse-media-selected" aria-label="Images sélectionnées">';
        foreach ($ids as $attachment_id) {
            $name = get_the_title($attachment_id);
            if (!is_string($name) || $name === '') { $name = 'Image #' . $attachment_id; }
            $thumb = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            echo '<li class="dse-media-item" data-dse-media-id="' . esc_attr((string) $attachment_id) .
                '" data-dse-media-title="' . esc_attr($name) . '" data-dse-media-url="' .
                esc_url(is_string($thumb) ? $thumb : '') . '">';
            if ($thumb) {
                echo '<img src="' . esc_url($thumb) . '" alt="" loading="lazy" width="88" height="88">';
            } else {
                echo '<span class="dse-media-placeholder dashicons dashicons-format-image" aria-hidden="true"></span>';
            }
            echo '<span class="dse-media-name">' . esc_html($name) . '</span>';
            echo '<button class="button-link-delete dse-media-remove" type="button" hidden ' .
                'data-dse-media-remove="' . esc_attr((string) $attachment_id) . '" aria-label="' .
                esc_attr('Retirer ' . $name . ' de la sélection') . '">Retirer</button></li>';
        }
        echo '</ul>';
        echo '<p class="dse-media-empty" data-dse-media-empty' . ($ids ? ' hidden' : '') .
            '>Aucune image supplémentaire sélectionnée.</p></div>';
        echo '<div class="dse-media-actions"><button type="button" class="button button-link-delete" ' .
            'data-dse-media-clear hidden>Tout retirer de la sélection</button></div>';
        echo '<details class="dse-media-advanced"><summary>Option avancée : saisir les numéros des images</summary>';
        echo '<div class="dse-field"><label for="dse-media-ids">Identifiants des images</label>';
        echo '<p class="description">À utiliser uniquement si le sélecteur ne fonctionne pas. ' .
            'Séparez les numéros par des virgules.</p>';
        echo '<textarea id="dse-media-ids" name="dse_attachment_ids" rows="2">' .
            esc_textarea(implode(', ', $ids)) . '</textarea></div></details></div>';
        echo '<p class="dse-media-feedback" data-dse-media-feedback role="alert" hidden></p>';
        echo '<p class="dse-media-submit"><button class="button button-primary" type="submit">' .
            'Enregistrer les autorisations</button></p>';
        echo '<p class="description">Retirer une image de cette liste ne la supprime pas du site ' .
            'et ne change pas les images déjà affichées.</p>';
        echo '</form>';
    }
}
