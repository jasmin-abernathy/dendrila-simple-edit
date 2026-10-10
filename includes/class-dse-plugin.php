<?php
/**
 * WordPress-owned, explicitly mapped editing surface.
 *
 * Unlike Dendrila CMS, this adapter never owns a second content database.
 * Site fields are stored as one revisioned WordPress option; shortcodes are
 * opt-in placements by a site administrator.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class DSE_Plugin
{
    const ROLE = 'dendrila_site_manager';
    const STATE_OPTION = 'dse_state';
    const ENABLED_OPTION = 'dse_enabled_fields';

    public static function definitions()
    {
        return array(
            'phone' => array(
                'label' => 'Téléphone',
                'help' => 'Numéro de téléphone public, avec indicatif si nécessaire.',
                'type' => 'tel',
                'max' => 80,
            ),
            'hours' => array(
                'label' => 'Horaires',
                'help' => 'Un jour ou une plage d’ouverture par ligne.',
                'type' => 'textarea',
                'max' => 1200,
            ),
            'address' => array(
                'label' => 'Adresse',
                'help' => 'Adresse postale à afficher sur le site.',
                'type' => 'textarea',
                'max' => 600,
            ),
            'presentation' => array(
                'label' => 'Présentation',
                'help' => 'Un court texte de présentation, sans code HTML.',
                'type' => 'textarea',
                'max' => 3000,
            ),
        );
    }

    public static function empty_state()
    {
        return array('revision' => 0, 'values' => array(), 'history' => array());
    }

    public static function activate()
    {
        // Deliberately no edit_posts / edit_pages / edit_theme_options / manage_options.
        add_role(self::ROLE, 'Responsable du site', array(
            'read' => true,
            'dse_access' => true,
            'dse_edit' => true,
            'dse_publish' => true,
        ));
        add_option(self::STATE_OPTION, self::empty_state(), '', false);
        add_option(self::ENABLED_OPTION, array(), '', false);
    }

    public static function boot()
    {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_post_dse_save', array(__CLASS__, 'save'));
        add_action('admin_post_dse_restore', array(__CLASS__, 'restore'));
        add_action('admin_post_dse_configure', array(__CLASS__, 'configure'));
        add_shortcode('dendrila_simple_edit', array(__CLASS__, 'shortcode'));
    }

    public static function can_access()
    {
        return current_user_can('manage_options') || current_user_can('dse_access');
    }

    public static function can_edit()
    {
        return current_user_can('manage_options') ||
            (current_user_can('dse_access') && current_user_can('dse_edit') && current_user_can('dse_publish'));
    }

    public static function enabled_fields()
    {
        $enabled = get_option(self::ENABLED_OPTION, array());
        if (!is_array($enabled)) {
            return array();
        }
        $known = array_keys(self::definitions());
        // Whitelist: never trust saved settings as arbitrary option/post keys.
        return array_values(array_intersect($known, $enabled));
    }

    public static function state()
    {
        $state = get_option(self::STATE_OPTION, self::empty_state());
        if (!is_array($state) || !isset($state['revision'], $state['values'], $state['history']) ||
            !is_int($state['revision']) || !is_array($state['values']) || !is_array($state['history'])) {
            return self::empty_state();
        }
        return $state;
    }

    public static function register_menu()
    {
        if (!self::can_access()) {
            return;
        }
        add_menu_page(
            'Modifier mon site',
            'Modifier mon site',
            'read',
            'dendrila-simple-edit',
            array(__CLASS__, 'render'),
            'dashicons-edit-page',
            28
        );
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_dendrila-simple-edit' || !self::can_access()) {
            return;
        }
        wp_enqueue_style(
            'dse-admin',
            plugins_url('assets/admin.css', DSE_PLUGIN_FILE),
            array(),
            DSE_VERSION
        );
    }

    public static function sanitize_field($key, $value)
    {
        $definitions = self::definitions();
        if (!isset($definitions[$key]) || !is_string($value)) {
            throw new InvalidArgumentException('Champ inconnu ou valeur invalide.');
        }
        $value = wp_unslash($value);
        $value = $definitions[$key]['type'] === 'textarea' ?
            sanitize_textarea_field($value) : sanitize_text_field($value);
        if (strlen($value) > $definitions[$key]['max'] * 4) {
            // Bound bytes as well as characters; avoid unbounded option payloads.
            throw new InvalidArgumentException('La valeur est trop longue.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $definitions[$key]['max']) {
            throw new InvalidArgumentException('La valeur est trop longue.');
        }
        return $value;
    }

    public static function validate_changes($input, $enabled)
    {
        if (!is_array($input) || !$input) {
            throw new InvalidArgumentException('Aucun champ à enregistrer.');
        }
        $allowed = array_fill_keys($enabled, true);
        $changes = array();
        foreach ($input as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Ce champ n’est pas autorisé.');
            }
            $changes[$key] = self::sanitize_field($key, $value);
        }
        return $changes;
    }

    private static function require_editor($nonce_action)
    {
        if (!self::can_edit()) {
            wp_die('Vous ne pouvez pas modifier ces contenus.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer($nonce_action);
    }

    private static function submitted_revision()
    {
        $raw = isset($_POST['dse_revision']) ? wp_unslash($_POST['dse_revision']) : null;
        if (!is_string($raw) || !ctype_digit($raw)) {
            wp_die('Version de contenu manquante.', 'Requête incorrecte', array('response' => 400));
        }
        return (int) $raw;
    }

    /**
     * Compare-and-swap performed as one SQL statement. WordPress's update_option
     * alone cannot reject two concurrent writes that saw the same old revision.
     */
    private static function compare_and_swap($old, $new)
    {
        global $wpdb;
        $old_raw = maybe_serialize($old);
        $new_raw = maybe_serialize($new);
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %s
             WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $new_raw,
            self::STATE_OPTION,
            $old_raw
        ));
        if ($affected === false) {
            return 'error';
        }
        if ($affected !== 1) {
            return 'conflict';
        }
        wp_cache_delete(self::STATE_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        return 'saved';
    }

    private static function commit_change($expected, $changes, $restore_revision = null)
    {
        $old = get_option(self::STATE_OPTION, null);
        if (!is_array($old) || !isset($old['revision'], $old['values'], $old['history']) ||
            !is_int($old['revision']) || !is_array($old['values']) || !is_array($old['history'])) {
            return 'error';
        }
        if ($expected !== $old['revision']) {
            return 'conflict';
        }

        $enabled = self::enabled_fields();
        if (!$enabled) {
            return 'unconfigured';
        }
        if ($restore_revision !== null) {
            $snapshot = null;
            foreach ($old['history'] as $entry) {
                if (is_array($entry) && isset($entry['revision']) &&
                    (int) $entry['revision'] === $restore_revision &&
                    isset($entry['values']) && is_array($entry['values'])) {
                    $snapshot = $entry['values'];
                    break;
                }
            }
            if ($snapshot === null) {
                return 'missing_revision';
            }
            $changes = array();
            // A later change to the allowlist must never unlock hidden fields.
            foreach ($enabled as $key) {
                $changes[$key] = self::sanitize_field($key, isset($snapshot[$key]) && is_string($snapshot[$key]) ?
                    $snapshot[$key] : '');
            }
        } else {
            $changes = self::validate_changes($changes, $enabled);
        }

        $updated_values = array_replace($old['values'], $changes);
        if ($updated_values === $old['values']) {
            return 'unchanged';
        }
        $changed_keys = array();
        foreach ($changes as $key => $value) {
            if (!isset($old['values'][$key]) || $old['values'][$key] !== $value) {
                $changed_keys[] = $key;
            }
        }
        $history = $old['history'];
        array_unshift($history, array(
            'revision' => $old['revision'],
            'values' => $old['values'],
            'changed' => $changed_keys,
            'timestamp' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
        ));

        $new = array(
            'revision' => $old['revision'] + 1,
            'values' => $updated_values,
            'history' => array_slice($history, 0, 15),
        );
        return self::compare_and_swap($old, $new);
    }

    public static function save()
    {
        self::require_editor('dse_save');
        $expected = self::submitted_revision();
        $input = isset($_POST['dse_fields']) ? $_POST['dse_fields'] : array();
        try {
            $result = self::commit_change($expected, $input);
        } catch (InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), 'Champ non autorisé', array('response' => 400));
        }
        self::redirect($result);
    }

    public static function restore()
    {
        self::require_editor('dse_restore');
        $expected = self::submitted_revision();
        $raw = isset($_POST['dse_restore_revision']) ? wp_unslash($_POST['dse_restore_revision']) : null;
        if (!is_string($raw) || !ctype_digit($raw)) {
            wp_die('Révision incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        try {
            $result = self::commit_change($expected, array(), (int) $raw);
        } catch (InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), 'Restauration impossible', array('response' => 400));
        }
        self::redirect($result);
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Configuration réservée à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_configure');
        $input = isset($_POST['dse_enabled']) ? wp_unslash($_POST['dse_enabled']) : array();
        if (!is_array($input)) {
            wp_die('Configuration incorrecte.', 'Requête incorrecte', array('response' => 400));
        }
        foreach ($input as $key) {
            if (!is_string($key) || !array_key_exists($key, self::definitions())) {
                wp_die('Champ de configuration inconnu.', 'Requête incorrecte', array('response' => 400));
            }
        }
        // Saving this option never changes any public content.
        update_option(self::ENABLED_OPTION, array_values(array_intersect(array_keys(self::definitions()), $input)), false);
        self::redirect('configured');
    }

    public static function shortcode($atts)
    {
        $attributes = shortcode_atts(array('field' => ''), $atts, 'dendrila_simple_edit');
        $key = $attributes['field'];
        if (!is_string($key) || !array_key_exists($key, self::definitions())) {
            return '';
        }
        $state = self::state();
        $value = isset($state['values'][$key]) && is_string($state['values'][$key]) ?
            $state['values'][$key] : '';
        return nl2br(esc_html($value), false);
    }

    private static function redirect($notice)
    {
        $allowed = array('saved', 'unchanged', 'conflict', 'unconfigured', 'missing_revision', 'error', 'configured');
        if (!in_array($notice, $allowed, true)) {
            $notice = 'error';
        }
        wp_safe_redirect(add_query_arg('dse_notice', $notice, admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    private static function notices()
    {
        $notice = isset($_GET['dse_notice']) && is_string($_GET['dse_notice']) ?
            sanitize_key(wp_unslash($_GET['dse_notice'])) : '';
        $messages = array(
            'saved' => array('updated', 'Modifications publiées. Les emplacements reliés sont à jour.'),
            'unchanged' => array('updated', 'Aucune modification à enregistrer.'),
            'configured' => array('updated', 'Champs autorisés mis à jour.'),
            'conflict' => array('error', 'Une autre modification a eu lieu. Rechargez la page avant de réessayer.'),
            'unconfigured' => array('error', 'Aucun champ n’a été autorisé par l’administration.'),
            'missing_revision' => array('error', 'Cette révision n’est plus disponible.'),
            'error' => array('error', 'Enregistrement impossible. Aucun contenu n’a été changé.'),
        );
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-' . esc_attr($messages[$notice][0]) .
                '"><p>' . esc_html($messages[$notice][1]) . '</p></div>';
        }
    }

    public static function render()
    {
        if (!self::can_access()) {
            wp_die('Accès refusé.', 'Accès refusé', array('response' => 403));
        }
        $definitions = self::definitions();
        $enabled = self::enabled_fields();
        $state = self::state();
        echo '<div class="wrap dse-wrap"><header class="dse-intro"><h1>Modifier mon site</h1>';
        echo '<p>Modifiez seulement les informations prévues pour vous, sans toucher à la mise en page.</p></header>';
        self::notices();
        echo '<div class="dse-layout"><main class="dse-main">';
        if (!$enabled) {
            echo '<div class="dse-card"><h2>Aucun contenu disponible</h2><p>Un administrateur doit choisir les informations modifiables.</p></div>';
        } elseif (self::can_edit()) {
            echo '<form class="dse-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dse_save">';
            echo '<input type="hidden" name="dse_revision" value="' . esc_attr((string) $state['revision']) . '">';
            wp_nonce_field('dse_save');
            echo '<h2>Informations du site</h2><p>Ces données apparaissent aux endroits reliés par le site.</p>';
            foreach ($enabled as $key) {
                $definition = $definitions[$key];
                $value = isset($state['values'][$key]) && is_string($state['values'][$key]) ?
                    $state['values'][$key] : '';
                $id = 'dse-field-' . $key;
                echo '<div class="dse-field"><label for="' . esc_attr($id) . '">' .
                    esc_html($definition['label']) . '</label>';
                echo '<p class="description" id="' . esc_attr($id . '-help') . '">' .
                    esc_html($definition['help']) . '</p>';
                if ($definition['type'] === 'textarea') {
                    echo '<textarea id="' . esc_attr($id) . '" name="dse_fields[' . esc_attr($key) .
                        ']" rows="4" maxlength="' . esc_attr((string) $definition['max']) .
                        '" aria-describedby="' . esc_attr($id . '-help') . '">' . esc_textarea($value) . '</textarea>';
                } else {
                    echo '<input id="' . esc_attr($id) . '" name="dse_fields[' . esc_attr($key) .
                        ']" type="' . esc_attr($definition['type']) .
                        '" maxlength="' . esc_attr((string) $definition['max']) .
                        '" aria-describedby="' . esc_attr($id . '-help') . '" value="' .
                        esc_attr($value) . '">';
                }
                echo '</div>';
            }
            echo '<p><button type="submit" class="button button-primary button-hero">Publier les modifications</button></p></form>';
        } else {
            echo '<div class="dse-card"><h2>Consultation uniquement</h2><p>Votre compte ne peut pas publier de changements.</p></div>';
        }

        echo '<section class="dse-card" aria-labelledby="dse-history-heading"><h2 id="dse-history-heading">Historique</h2>';
        echo '<p>Les 15 dernières versions précédentes sont conservées. Une restauration ne modifie que les champs actuellement autorisés.</p>';
        $history = array_slice($state['history'], 0, 15);
        if (!$history || !$enabled) {
            echo '<p>Aucune version précédente disponible.</p>';
        } else {
            echo '<ul class="dse-history">';
            foreach ($history as $entry) {
                if (!is_array($entry) || !isset($entry['revision'], $entry['timestamp']) ||
                    !is_scalar($entry['revision']) || !is_string($entry['timestamp'])) {
                    continue;
                }
                $affected = array();
                foreach (isset($entry['changed']) && is_array($entry['changed']) ? $entry['changed'] : array() as $key) {
                    if (in_array($key, $enabled, true)) {
                        $affected[] = $definitions[$key]['label'];
                    }
                }
                echo '<li><div><strong>Version ' . esc_html((string) $entry['revision']) .
                    '</strong><p>' . esc_html($entry['timestamp']) . ' UTC';
                if ($affected) {
                    echo ' — ' . esc_html(implode(', ', $affected));
                }
                echo '</p></div>';
                if (self::can_edit()) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="dse_restore">';
                    echo '<input type="hidden" name="dse_revision" value="' . esc_attr((string) $state['revision']) . '">';
                    echo '<input type="hidden" name="dse_restore_revision" value="' .
                        esc_attr((string) $entry['revision']) . '">';
                    wp_nonce_field('dse_restore');
                    echo '<button class="button" type="submit">Restaurer cette version</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section></main>';

        echo '<aside class="dse-aside"><section class="dse-card"><h2>Où apparaissent ces informations ?</h2>';
        echo '<p>Seuls les emplacements reliés à un champ se mettront à jour. Un texte saisi directement ailleurs ne sera pas remplacé.</p>';
        if ($enabled) {
            echo '<ul>';
            foreach ($enabled as $key) {
                echo '<li><strong>' . esc_html($definitions[$key]['label']) . '</strong>';
                if (current_user_can('manage_options')) {
                    echo '<code>[dendrila_simple_edit field="' . esc_html($key) . '"]</code>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
        if (current_user_can('manage_options')) {
            echo '<form class="dse-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<h2>Configuration administrateur</h2><p>Choisissez les champs confiés au responsable du site. Aucun compte n’est créé automatiquement.</p>';
            echo '<input type="hidden" name="action" value="dse_configure">';
            wp_nonce_field('dse_configure');
            foreach ($definitions as $key => $definition) {
                echo '<label class="dse-check"><input type="checkbox" name="dse_enabled[]" value="' .
                    esc_attr($key) . '" ' . checked(in_array($key, $enabled, true), true, false) . '> ' .
                    esc_html($definition['label']) . '</label>';
            }
            echo '<p><button class="button button-secondary" type="submit">Enregistrer les autorisations</button></p>';
            echo '<p class="description">Insérez chaque code court dans vos pages ou modèles : WordPress reste propriétaire du contenu.</p>';
            echo '</form>';
        }
        echo '</aside></div></div>';
    }
}
