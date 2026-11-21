<?php
/** Explicitly delegated editing of published The Events Calendar events. */
if (!defined('ABSPATH')) { exit; }

final class DSE_Event_Editor
{
    const OPTION = 'dse_visual_editable_events';

    public static function boot()
    {
        add_action('admin_post_dse_event_permissions', array(__CLASS__, 'configure'));
        add_action('admin_post_dse_event_save', array(__CLASS__, 'save'));
        add_filter('the_content', array(__CLASS__, 'frontend_colors'), 20);
    }

    public static function frontend_colors($html)
    {
        if (!is_singular('tribe_events') || !in_the_loop() || !is_main_query()) { return $html; }
        $post = get_post(get_the_ID());
        if (!$post || !self::eligible((int) $post->ID)) { return $html; }
        return DSE_Block_Editor::frontend_colors($html);
    }

    public static function available()
    {
        return function_exists('tribe_update_event') && function_exists('tribe_get_event') && post_type_exists('tribe_events');
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

    private static function eligible($id, $check_allowlist = true)
    {
        if (!self::available() || ($check_allowlist && !in_array($id, self::allowed_ids(), true))) { return false; }
        $post = get_post((int) $id);
        return $post && $post->post_type === 'tribe_events' && $post->post_status === 'publish' &&
            $post->post_password === '' && self::compatible($post) ? $post : false;
    }

    private static function date_meta($post_id, $field)
    {
        return (string) get_post_meta($post_id, $field, true);
    }

    public static function fingerprint($post)
    {
        return hash('sha256', wp_json_encode(array(
            $post->ID, $post->post_title, $post->post_content, $post->post_modified_gmt,
            self::date_meta($post->ID, '_EventStartDate'),
            self::date_meta($post->ID, '_EventEndDate'),
            self::date_meta($post->ID, '_EventTimezone'),
            self::date_meta($post->ID, '_EventAllDay'),
            (int) tribe_get_venue_id($post->ID)
        )));
    }

    private static function redirect($notice, $id = 0)
    {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'dendrila-simple-edit', 'dse_tab' => 'evenements',
            'dse_enotice' => $notice, 'dse_eid' => (int) $id
        ), admin_url('admin.php')));
        exit;
    }

    public static function configure()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Réservé à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_event_permissions');
        $submitted = isset($_POST['dse_event_ids']) ? wp_unslash($_POST['dse_event_ids']) : array();
        if (!is_array($submitted) || count($submitted) > 100) {
            wp_die('Sélection invalide.', 'Requête incorrecte', array('response' => 400));
        }
        $ids = array();
        foreach ($submitted as $id) {
            if (is_string($id) && ctype_digit($id) && self::eligible((int) $id, false)) {
                $ids[] = (int) $id;
            }
        }
        update_option(self::OPTION, array_values(array_unique($ids)), false);
        self::redirect('permissions');
    }

    private static function date_input($value, $timezone)
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/', $value) !== 1) { return false; }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        return $parsed && $parsed->format('Y-m-d\TH:i') === $value ? $parsed : false;
    }

    public static function save()
    {
        if (!DSE_Plugin::can_edit()) {
            wp_die('Vous ne pouvez pas modifier cet événement.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_event_save');
        $id = isset($_POST['dse_eid']) && ctype_digit((string) $_POST['dse_eid']) ? (int) $_POST['dse_eid'] : 0;
        $post = self::eligible($id);
        if (!$post) { self::redirect('forbidden'); }
        // A full-day event must not silently turn into a timed event.
        if (self::date_meta($id, '_EventAllDay') === 'yes') { self::redirect('all_day', $id); }
        $expected = isset($_POST['dse_eversion']) ? wp_unslash($_POST['dse_eversion']) : null;
        $title = isset($_POST['dse_etitle']) ? wp_unslash($_POST['dse_etitle']) : null;
        $body = isset($_POST['dse_ebody']) ? wp_unslash($_POST['dse_ebody']) : null;
        $start_raw = isset($_POST['dse_estart']) ? wp_unslash($_POST['dse_estart']) : null;
        $end_raw = isset($_POST['dse_eend']) ? wp_unslash($_POST['dse_eend']) : null;
        $venue_raw = isset($_POST['dse_evenue']) ? wp_unslash($_POST['dse_evenue']) : null;
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/', $expected) ||
            !is_string($title) || strlen($title) > 800 ||
            !is_string($body) || strlen($body) > 100000 ||
            !is_string($venue_raw) || !ctype_digit($venue_raw)) {
            wp_die('Formulaire invalide.', 'Requête incorrecte', array('response' => 400));
        }
        if (!hash_equals(self::fingerprint($post), $expected)) { self::redirect('conflict', $id); }
        $title = sanitize_text_field($title);
        if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 180) {
            wp_die('Titre manquant ou trop long.', 'Requête incorrecte', array('response' => 400));
        }
        $clean = DSE_Block_Editor::clean($body);
        if (!DSE_Block_Editor::is_safe_for_editor($clean)) {
            wp_die('Contenu incompatible avec cet éditeur.', 'Requête incorrecte', array('response' => 400));
        }
        $timezone_label = self::date_meta($id, '_EventTimezone');
        try { $tz = new DateTimeZone($timezone_label ?: wp_timezone_string()); }
        catch (Exception $e) { $tz = wp_timezone(); }
        $start = self::date_input($start_raw, $tz);
        $end = self::date_input($end_raw, $tz);
        if (!$start || !$end || $end->getTimestamp() <= $start->getTimestamp()) {
            self::redirect('bad_dates', $id);
        }
        // Location is chosen from existing, public venues only. No delegated venue creation or editing.
        $venue_id = (int) $venue_raw;
        if ($venue_id && (get_post_type($venue_id) !== 'tribe_venue' ||
            get_post_status($venue_id) !== 'publish' || post_password_required($venue_id))) {
            self::redirect('bad_venue', $id);
        }
        if (!self::eligible($id) || !hash_equals(self::fingerprint(get_post($id)), $expected)) {
            self::redirect('conflict', $id);
        }
        $format = Tribe__Date_Utils::datepicker_formats(tribe_get_option('datepickerFormat'));
        $args = array(
            'post_title' => wp_slash($title),
            'post_content' => wp_slash($clean),
            'EventStartDate' => $start->format($format),
            'EventStartTime' => $start->format('H:i'),
            'EventEndDate' => $end->format($format),
            'EventEndTime' => $end->format('H:i'),
            'EventTimezone' => $tz->getName(),
            'EventAllDay' => 'no'
        );
        if ($venue_id) { $args['venue'] = array('id' => $venue_id); }
        // Use The Events Calendar API to update the derived UTC fields and duration.
        $result = tribe_update_event($id, $args);
        clean_post_cache($id);
        $updated = get_post($id);
        if (!$result || !$updated || $updated->post_title !== $title || $updated->post_content !== $clean ||
            self::date_meta($id, '_EventStartDate') !== $start->format('Y-m-d H:i:s') ||
            self::date_meta($id, '_EventEndDate') !== $end->format('Y-m-d H:i:s')) {
            self::redirect('error', $id);
        }
        self::redirect('saved', $id);
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<section class="dse-card"><h2>Événements autorisés</h2>';
        if (!self::available()) {
            echo '<p>Activez The Events Calendar pour utiliser cette rubrique.</p></section>';
            return;
        }
        $ids = self::allowed_ids();
        $posts = get_posts(array('post_type' => 'tribe_events', 'post_status' => 'publish',
            'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC'));
        echo '<p>Choisissez les événements que les comptes Édition simple pourront modifier. Les modèles complexes restent protégés.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dse_event_permissions">';
        wp_nonce_field('dse_event_permissions');
        foreach ($posts as $post) {
            if (!self::eligible($post->ID, false)) { continue; }
            echo '<label class="dse-check"><input type="checkbox" name="dse_event_ids[]" value="' . (int) $post->ID . '" ' .
                checked(in_array((int) $post->ID, $ids, true), true, false) . '> ' .
                esc_html($post->post_title) . '</label>';
        }
        echo '<p><button class="button button-primary" type="submit">Enregistrer les autorisations</button></p></form></section>';
    }

    public static function render()
    {
        if (!self::available()) {
            echo '<section class="dse-card"><h3>Calendrier indisponible</h3><p>Activez The Events Calendar.</p></section>';
            return;
        }
        $ids = self::allowed_ids();
        $selected = isset($_GET['dse_eid']) ? absint($_GET['dse_eid']) : 0;
        $notices = array('saved' => 'Événement enregistré.', 'permissions' => 'Autorisations mises à jour.',
            'conflict' => 'Cet événement a changé : rechargez avant de recommencer.',
            'forbidden' => 'Événement non autorisé.', 'bad_dates' => 'Vérifiez les dates et les horaires.',
            'bad_venue' => 'Lieu non autorisé.', 'all_day' => 'Cet événement sur toute la journée doit être modifié dans The Events Calendar.',
            'error' => 'Enregistrement non confirmé.');
        $notice = isset($_GET['dse_enotice']) ? sanitize_key(wp_unslash($_GET['dse_enotice'])) : '';
        if (isset($notices[$notice])) { echo '<p class="notice notice-info" role="status">' . esc_html($notices[$notice]) . '</p>'; }
        echo '<section class="dse-card"><h2>Choisir un événement</h2><div class="dse-post-choice">';
        $found = 0;
        foreach ($ids as $id) {
            $post = self::eligible($id);
            if (!$post) { continue; }
            $found++;
            echo '<a class="button ' . ($selected === $id ? 'button-primary' : 'button-secondary') . '" href="' .
                esc_url(add_query_arg(array('page' => 'dendrila-simple-edit', 'dse_tab' => 'evenements',
                    'dse_eid' => $id), admin_url('admin.php'))) . '">' . esc_html($post->post_title) . '</a> ';
        }
        if (!$found) { echo '<p>Aucun événement autorisé. L’administrateur peut en choisir dans Autorisations.</p>'; }
        echo '</div></section>';
        $post = $selected ? self::eligible($selected) : false;
        if (!$post) { return; }
        if (self::date_meta($post->ID, '_EventAllDay') === 'yes') {
            echo '<section class="dse-card"><p>Les événements sur toute la journée restent à modifier dans The Events Calendar pour préserver leurs dates.</p></section>';
            return;
        }
        $tz = self::date_meta($post->ID, '_EventTimezone');
        $start = str_replace(' ', 'T', substr(self::date_meta($post->ID, '_EventStartDate'), 0, 16));
        $end = str_replace(' ', 'T', substr(self::date_meta($post->ID, '_EventEndDate'), 0, 16));
        $current_venue = (int) tribe_get_venue_id($post->ID);
        echo '<form class="dse-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h2>Modifier cet événement</h2>';
        echo '<input type="hidden" name="action" value="dse_event_save">';
        echo '<input type="hidden" name="dse_eid" value="' . (int) $post->ID . '">';
        echo '<input type="hidden" name="dse_eversion" value="' . esc_attr(self::fingerprint($post)) . '">';
        wp_nonce_field('dse_event_save');
        echo '<div class="dse-field"><label for="dse-event-title">Titre de l’événement</label>';
        echo '<input type="text" id="dse-event-title" name="dse_etitle" required maxlength="180" value="' .
            esc_attr($post->post_title) . '"></div>';
        echo '<div class="dse-field"><label for="dse-event-start">Début</label>';
        echo '<input type="datetime-local" id="dse-event-start" name="dse_estart" required value="' .
            esc_attr($start) . '"></div>';
        echo '<div class="dse-field"><label for="dse-event-end">Fin</label>';
        echo '<input type="datetime-local" id="dse-event-end" name="dse_eend" required value="' .
            esc_attr($end) . '"></div>';
        echo '<p class="description">Fuseau horaire : ' . esc_html($tz ?: wp_timezone_string()) . '. Les événements sur toute la journée ne sont pas encore pris en charge par cet éditeur.</p>';
        $venues = get_posts(array('post_type' => 'tribe_venue', 'post_status' => 'publish',
            'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC'));
        echo '<div class="dse-field"><label for="dse-event-venue">Lieu existant</label>';
        echo '<select id="dse-event-venue" name="dse_evenue"><option value="0">Aucun lieu / conserver le lieu actuel</option>';
        foreach ($venues as $venue) {
            echo '<option value="' . (int) $venue->ID . '" ' .
                selected($current_venue, $venue->ID, false) . '>' . esc_html($venue->post_title) . '</option>';
        }
        echo '</select><p class="description">Les lieux sont créés et gérés dans The Events Calendar par l’administrateur.</p></div>';
        echo '<div class="dse-block-editor" data-dse-block-editor data-allow-title="0">';
        echo '<label for="dse-event-body">Description</label>';
        echo '<textarea id="dse-event-body" data-dse-editor-source name="dse_ebody" rows="10">' .
            esc_textarea($post->post_content) . '</textarea></div>';
        echo '<p><button class="button button-primary" type="submit">Enregistrer l’événement</button></p></form>';
    }
}
