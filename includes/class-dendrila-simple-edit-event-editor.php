<?php
/** Explicitly delegated editing of published The Events Calendar events. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Event_Editor
{
    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_event_save', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_event_create_venue', array(__CLASS__, 'create_venue'));
        add_filter('the_content', array(__CLASS__, 'frontend_colors'), 20);
    }

    public static function frontend_colors($html)
    {
        if (!is_singular('tribe_events') || !in_the_loop() || !is_main_query()) { return $html; }
        $post = get_post(get_the_ID());
        if (!$post || !self::eligible((int) $post->ID)) { return $html; }
        return Dendrila_Simple_Edit_Block_Editor::frontend_colors($html);
    }

    public static function available()
    {
        return function_exists('tribe_update_event') && function_exists('tribe_get_event') && post_type_exists('tribe_events');
    }

    private static function can_modify_events()
    {
        return current_user_can('manage_options') ||
            (Dendrila_Simple_Edit_Post_Editor::type_enabled('tribe_events') &&
             Dendrila_Simple_Edit_Modules::allowed('editorial', 'edit_events'));
    }

    private static function compatible($post)
    {
        return $post && Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($post->post_content) &&
            get_post_meta($post->ID, '_elementor_edit_mode', true) !== 'builder' &&
            !get_post_meta($post->ID, '_elementor_data', true) &&
            get_post_meta($post->ID, '_et_pb_use_builder', true) !== 'on';
    }

    private static function eligible($id)
    {
        if (!self::available() || !self::can_modify_events()) { return false; }
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
            'page' => 'dendrila-simple-edit', 'dendrila_simple_edit_tab' => 'evenements',
            'dendrila_simple_edit_enotice' => $notice, 'dendrila_simple_edit_eid' => (int) $id
        ), admin_url('admin.php')));
        exit;
    }

    private static function date_input($value, $timezone)
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/', $value) !== 1) { return false; }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        return $parsed && $parsed->format('Y-m-d\TH:i') === $value ? $parsed : false;
    }

    public static function save()
    {
        if (!self::can_modify_events()) {
            wp_die('Vous ne pouvez pas modifier cet événement.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_event_save');
        $raw_id = isset($_POST['dendrila_simple_edit_eid']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_eid'])) : '';
        $id = ctype_digit($raw_id) ? (int) $raw_id : 0;
        $post = self::eligible($id);
        if (!$post) { self::redirect('forbidden'); }
        // A full-day event must not silently turn into a timed event.
        if (self::date_meta($id, '_EventAllDay') === 'yes') { self::redirect('all_day', $id); }
        $expected = isset($_POST['dendrila_simple_edit_eversion']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_eversion'])) : null;
        $title = isset($_POST['dendrila_simple_edit_etitle']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_etitle'])) : null;
        $body = isset($_POST['dendrila_simple_edit_ebody']) ? wp_kses(wp_unslash($_POST['dendrila_simple_edit_ebody']), Dendrila_Simple_Edit_Block_Editor::allowed_tags(), array('http', 'https', 'mailto')) : null;
        $start_raw = isset($_POST['dendrila_simple_edit_estart']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_estart'])) : null;
        $end_raw = isset($_POST['dendrila_simple_edit_eend']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_eend'])) : null;
        $venue_raw = isset($_POST['dendrila_simple_edit_evenue']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_evenue'])) : null;
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
        $clean = Dendrila_Simple_Edit_Block_Editor::clean($body);
        if (!Dendrila_Simple_Edit_Block_Editor::is_safe_for_editor($clean)) {
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
        // Location must be an existing, published TEC venue; creation is handled separately above.
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


    public static function create_venue()
    {
        if (!self::can_modify_events() || !self::available() || !function_exists('tribe_venues')) {
            wp_die('Création de lieu non autorisée.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_event_create_venue');
        $id = isset($_POST['dendrila_simple_edit_eid']) ? absint(wp_unslash($_POST['dendrila_simple_edit_eid'])) : 0;
        $post = self::eligible($id);
        if (!$post) { self::redirect('forbidden'); }
        $expected = isset($_POST['dendrila_simple_edit_eversion']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_eversion'])) : '';
        if (!is_string($expected) || !hash_equals(self::fingerprint($post), $expected)) {
            self::redirect('conflict', $id);
        }
        $fields = array('venue'=>'name', 'address'=>'address', 'city'=>'city', 'zip'=>'zip', 'country'=>'country', 'phone'=>'phone');
        $venue = array('status' => 'publish', 'show_map' => false, 'show_map_link' => false);
        foreach ($fields as $key => $param) {
            $raw = isset($_POST['dendrila_simple_edit_venue_' . $param]) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_venue_' . $param])) : '';
            if (!is_string($raw) || strlen($raw) > 480) {
                wp_die('Champ du lieu invalide.', 'Requête incorrecte', array('response' => 400));
            }
            $venue[$key] = sanitize_text_field($raw);
        }
        if ($venue['venue'] === '' || strlen($venue['venue']) > 180) {
            wp_die('Indiquez le nom du lieu.', 'Requête incorrecte', array('response' => 400));
        }
        // TEC's ORM applies its own venue metadata and hooks.
        try { $created = tribe_venues()->set_args($venue)->create(); }
        catch (Throwable $error) { self::redirect('venue_error', $id); }
        if (is_wp_error($created) || !$created) { self::redirect('venue_error', $id); }
        $new_id = is_object($created) && isset($created->ID) ? (int) $created->ID : (int) $created;
        if ($new_id <= 0 || get_post_type($new_id) !== 'tribe_venue') {
            self::redirect('venue_error', $id);
        }
        wp_safe_redirect(add_query_arg(array('page' => 'dendrila-simple-edit',
            'dendrila_simple_edit_tab' => 'evenements', 'dendrila_simple_edit_eid' => $id,
            'dendrila_simple_edit_enotice' => 'venue_created',
            'dendrila_simple_edit_new_venue' => $new_id), admin_url('admin.php')));
        exit;
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<section class="dendrila-simple-edit-card"><h2>Événements : droits par type</h2>';
        if (!self::available()) {
            echo '<p>Activez The Events Calendar pour utiliser cette rubrique.</p></section>';
            return;
        }
        echo '<p>L’administrateur voit tous les événements publiés compatibles, sans sélection individuelle.
            Pour les autres rôles, activez le type « Événements » et le droit « Modifier les événements existants » dans les autorisations.</p></section>';
    }

    public static function render()
    {
        if (!self::available()) {
            echo '<section class="dendrila-simple-edit-card"><h3>Calendrier indisponible</h3><p>Activez The Events Calendar.</p></section>';
            return;
        }
        $ids = array();
        if (self::can_modify_events() && self::available()) {
            $events = get_posts(array('post_type'=>'tribe_events','post_status'=>'publish',
                'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'modified','order'=>'DESC'));
            foreach ($events as $event_id) {
                if (self::eligible((int)$event_id)) { $ids[] = (int)$event_id; }
            }
        }
        $selected = Dendrila_Simple_Edit_Plugin::screen_id('dendrila_simple_edit_eid', 0);
        $notices = array('saved' => 'Événement enregistré.', 'permissions' => 'Autorisations mises à jour.',
            'conflict' => 'Cet événement a changé : rechargez avant de recommencer.',
            'forbidden' => 'Événement non autorisé.', 'bad_dates' => 'Vérifiez les dates et les horaires.',
            'bad_venue' => 'Lieu non autorisé.', 'all_day' => 'Cet événement sur toute la journée doit être modifié dans The Events Calendar.',
            'error' => 'Enregistrement non confirmé.',
            'venue_created' => 'Lieu créé. Vérifiez sa sélection, puis enregistrez l’événement pour l’associer.',
            'venue_error' => 'Le lieu n’a pas pu être créé.');
        $notice = Dendrila_Simple_Edit_Plugin::screen_key('dendrila_simple_edit_enotice', '');
        if (isset($notices[$notice])) { echo '<p class="notice notice-info" role="status">' . esc_html($notices[$notice]) . '</p>'; }
        echo '<section class="dendrila-simple-edit-card"><h2>Choisir un événement</h2><div class="dendrila-simple-edit-post-choice">';
        $found = 0;
        foreach ($ids as $id) {
            $post = self::eligible($id);
            if (!$post) { continue; }
            $found++;
            echo '<a class="button ' . ($selected === $id ? 'button-primary' : 'button-secondary') . '" href="' .
                esc_url(add_query_arg(array('page' => 'dendrila-simple-edit', 'dendrila_simple_edit_tab' => 'evenements',
                    'dendrila_simple_edit_eid' => $id), admin_url('admin.php'))) . '">' . esc_html($post->post_title) . '</a> ';
        }
        if (!$found) { echo '<p>Aucun événement autorisé. L’administrateur peut en choisir dans Autorisations.</p>'; }
        echo '</div></section>';
        $post = $selected ? self::eligible($selected) : false;
        if (!$post) { return; }
        if (self::date_meta($post->ID, '_EventAllDay') === 'yes') {
            echo '<section class="dendrila-simple-edit-card"><p>Les événements sur toute la journée restent à modifier dans The Events Calendar pour préserver leurs dates.</p></section>';
            return;
        }
        $tz = self::date_meta($post->ID, '_EventTimezone');
        $start = str_replace(' ', 'T', substr(self::date_meta($post->ID, '_EventStartDate'), 0, 16));
        $end = str_replace(' ', 'T', substr(self::date_meta($post->ID, '_EventEndDate'), 0, 16));
        $current_venue = (int) tribe_get_venue_id($post->ID);
        $new_venue = Dendrila_Simple_Edit_Plugin::screen_id('dendrila_simple_edit_new_venue', 0);
        if ($new_venue && get_post_type($new_venue) === 'tribe_venue' && get_post_status($new_venue) === 'publish') {
            $current_venue = $new_venue;
        }
        echo '<form class="dendrila-simple-edit-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h2>Modifier cet événement</h2>';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_event_save">';
        echo '<input type="hidden" name="dendrila_simple_edit_eid" value="' . (int) $post->ID . '">';
        echo '<input type="hidden" name="dendrila_simple_edit_eversion" value="' . esc_attr(self::fingerprint($post)) . '">';
        wp_nonce_field('dendrila_simple_edit_event_save');
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-event-title">Titre de l’événement</label>';
        echo '<input type="text" id="dendrila-simple-edit-event-title" name="dendrila_simple_edit_etitle" required maxlength="180" value="' .
            esc_attr($post->post_title) . '"></div>';
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-event-start">Début</label>';
        echo '<input type="datetime-local" id="dendrila-simple-edit-event-start" name="dendrila_simple_edit_estart" required value="' .
            esc_attr($start) . '"></div>';
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-event-end">Fin</label>';
        echo '<input type="datetime-local" id="dendrila-simple-edit-event-end" name="dendrila_simple_edit_eend" required value="' .
            esc_attr($end) . '"></div>';
        echo '<p class="description">Fuseau horaire : ' . esc_html($tz ?: wp_timezone_string()) . '. Les événements sur toute la journée ne sont pas encore pris en charge par cet éditeur.</p>';
        $venues = get_posts(array('post_type' => 'tribe_venue', 'post_status' => 'publish',
            'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC'));
        if ($new_venue && !in_array($new_venue, array_map(static function ($venue) {
            return (int)$venue->ID;
        }, $venues), true)) { $venues[] = get_post($new_venue); }
        echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-event-venue">Lieu existant</label>';
        echo '<select id="dendrila-simple-edit-event-venue" name="dendrila_simple_edit_evenue"><option value="0">Aucun lieu / conserver le lieu actuel</option>';
        foreach ($venues as $venue) {
            echo '<option value="' . (int) $venue->ID . '" ' .
                selected($current_venue, $venue->ID, false) . '>' . esc_html($venue->post_title) . '</option>';
        }
        echo '</select><p class="description">Sélectionnez un lieu existant, ou créez-en un nouveau ci-dessous.</p></div>';
        echo '<div class="dendrila-simple-edit-block-editor" data-dendrila-simple-edit-block-editor data-allow-title="0">';
        echo '<label for="dendrila-simple-edit-event-body">Description</label>';
        echo '<textarea id="dendrila-simple-edit-event-body" data-dendrila-simple-edit-editor-source name="dendrila_simple_edit_ebody" rows="10">' .
            esc_textarea($post->post_content) . '</textarea></div>';
        echo '<p><button class="button button-primary" type="submit">Enregistrer l’événement</button></p></form>';
        echo '<details class="dendrila-simple-edit-card"><summary>Créer un lieu pour cet événement</summary>' .
            '<p>Ajoutez le lieu, puis choisissez-le dans le formulaire de l’événement. Aucun lieu n’est créé pendant la simple saisie.</p>' .
            '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_event_create_venue">';
        echo '<input type="hidden" name="dendrila_simple_edit_eid" value="' . (int) $post->ID . '">';
        echo '<input type="hidden" name="dendrila_simple_edit_eversion" value="' . esc_attr(self::fingerprint($post)) . '">';
        wp_nonce_field('dendrila_simple_edit_event_create_venue');
        foreach (array('name'=>'Nom du lieu *', 'address'=>'Adresse', 'city'=>'Ville', 'zip'=>'Code postal', 'country'=>'Pays', 'phone'=>'Téléphone') as $key => $label) {
            echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-venue-' . esc_attr($key) . '">' . esc_html($label) . '</label>';
            echo '<input type="text" id="dendrila-simple-edit-venue-' . esc_attr($key) . '" name="dendrila_simple_edit_venue_' . esc_attr($key) . '" maxlength="180"' .
                ($key === 'name' ? ' required' : '') . '></div>';
        }
        echo '<p><button class="button button-secondary" type="submit">Créer ce lieu</button></p></form></details>';
    }
}
