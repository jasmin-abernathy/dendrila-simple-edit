<?php
/**
 * Admin-curated WordPress media selector. No upload privilege for site managers.
 */
if (!defined('ABSPATH')) { exit; }

final class DSE_Media
{
    const OPTION = 'dse_media_allowlist';

    public static function boot()
    {
        add_action('admin_post_dse_media_configure', array(__CLASS__, 'configure'));
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

    public static function sanitize_selection($raw)
    {
        if (!is_string($raw) || ($raw !== '' && !ctype_digit($raw))) {
            throw new InvalidArgumentException('Sélection d’image incorrecte.');
        }
        if ($raw === '') { return ''; }
        $id = (int) $raw;
        if (!in_array($id, self::curated_ids(), true)) {
            throw new InvalidArgumentException('Cette image n’est pas autorisée.');
        }
        return (string) $id;
    }

    public static function render_image($value)
    {
        if (!is_string($value) || !ctype_digit($value) || !wp_attachment_is_image((int) $value)) {
            return '';
        }
        // Existing public shortcodes must keep rendering after the administrator
        // narrows the selection list; revocation only blocks future choices.
        return (string) wp_get_attachment_image((int) $value, 'large', false,
            array('loading' => 'lazy', 'decoding' => 'async'));
    }

    public static function render_picker($key, $value, $id)
    {
        $known = self::curated_ids();
        $current = $value !== '' ? (int) $value : 0;
        echo '<select id="' . esc_attr($id) . '" name="dse_fields[' . esc_attr($key) .
            ']" aria-describedby="' . esc_attr($id . '-help') . '">';
        echo '<option value="">Aucune image</option>';
        if ($current && !in_array($current, $known, true)) {
            // Disabled selected option is not submitted: keep existing image
            // instead of clearing it when another field is saved.
            echo '<option value="" disabled selected>Image publiée — conservée, non modifiable</option>';
        }
        foreach ($known as $attachment_id) {
            echo '<option value="' . esc_attr((string) $attachment_id) . '" ' .
                selected($current, $attachment_id, false) . '>' .
                esc_html(get_the_title($attachment_id)) . ' (#' . esc_html((string) $attachment_id) .
                ')</option>';
        }
        echo '</select>';
        if ($current && in_array($current, $known, true)) {
            echo '<div class="dse-image-preview">' .
                wp_get_attachment_image($current, 'thumbnail', false, array('loading' => 'lazy')) . '</div>';
        } elseif ($current) {
            echo '<p class="description">Cette image n’est plus proposée à l’édition. Choisissez une image autorisée.</p>';
        }
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
        wp_safe_redirect(add_query_arg('dse_notice', 'media_configured',
            admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        $ids = self::curated_ids();
        echo '<form class="dse-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h2>Images autorisées</h2>';
        echo '<p>Choisissez d’abord les images dans la médiathèque WordPress. Copiez leurs identifiants ici, séparés par des virgules. Le Responsable du site pourra ensuite les sélectionner sans accéder à toute la médiathèque.</p>';
        echo '<input type="hidden" name="action" value="dse_media_configure">';
        wp_nonce_field('dse_media_configure');
        echo '<div class="dse-field"><label for="dse-media-ids">Identifiants des images</label>';
        echo '<textarea id="dse-media-ids" name="dse_attachment_ids" rows="3">' .
            esc_textarea(implode(', ', $ids)) . '</textarea></div>';
        echo '<p><button class="button" type="submit">Enregistrer la sélection</button> ';
        echo '<a href="' . esc_url(admin_url('upload.php')) . '">Ouvrir la médiathèque</a></p>';
        echo '<p class="description">Les images précédemment publiées restent en place lors d’un changement de liste. Aucun fichier n’est effacé.</p>';
        echo '</form>';
    }
}
