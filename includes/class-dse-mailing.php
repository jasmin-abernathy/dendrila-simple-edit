<?php
/**
 * Dendrila Simple Edit newsletter delivery (beta). Only administrators manage
 * subscribers and mailing, only confirmed subscribers receive campaigns.
 * FluentSMTP transports ordinary wp_mail() messages and owns SMTP credentials.
 */
if (!defined('ABSPATH')) { exit; }

final class DSE_Mailing
{
    const DB_VERSION = '2';
    const DB_OPTION = 'dse_mailing_schema_version';
    const CRON_HOOK = 'dse_mailing_dispatch';
    const BATCH_SIZE = 5;

    private static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'dse_ml_' . $name;
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $lists = self::table('lists');
        $contacts = self::table('contacts');
        $members = self::table('members');
        $campaigns = self::table('campaigns');
        $queue = self::table('queue');
        dbDelta("CREATE TABLE $lists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(160) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;");
        dbDelta("CREATE TABLE $contacts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(254) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            consent_source varchar(255) NOT NULL DEFAULT '',
            consent_at datetime DEFAULT NULL,
            confirm_hash char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY status (status)
        ) $charset;");
        dbDelta("CREATE TABLE $members (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            list_id bigint(20) unsigned NOT NULL,
            contact_id bigint(20) unsigned NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            consent_source varchar(255) NOT NULL DEFAULT '',
            consent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_member (list_id,contact_id),
            KEY contact_id (contact_id)
        ) $charset;");
        dbDelta("CREATE TABLE $campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            list_id bigint(20) unsigned NOT NULL,
            subject varchar(255) NOT NULL,
            html_body longtext NOT NULL,
            status varchar(18) NOT NULL DEFAULT 'prepared',
            send_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            started_at datetime DEFAULT NULL,
            finished_at datetime DEFAULT NULL,
            locked_until datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY newsletter_id (newsletter_id)
        ) $charset;");
        dbDelta("CREATE TABLE $queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            contact_id bigint(20) unsigned NOT NULL,
            status varchar(18) NOT NULL DEFAULT 'queued',
            error_message varchar(255) NOT NULL DEFAULT '',
            accepted_at datetime DEFAULT NULL,
            processing_started_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_delivery (campaign_id,contact_id),
            KEY next_delivery (campaign_id,status)
        ) $charset;");
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function boot()
    {
        add_action('admin_init', array(__CLASS__, 'maybe_install'));
        foreach (array('list', 'import', 'prepare', 'start', 'pause', 'test', 'remove') as $task) {
            add_action('admin_post_dse_ml_' . $task, array(__CLASS__, $task));
        }
        add_action('admin_post_dse_ml_subscribe', array(__CLASS__, 'subscribe'));
        add_action('admin_post_nopriv_dse_ml_subscribe', array(__CLASS__, 'subscribe'));
        add_action('admin_post_dse_ml_confirm', array(__CLASS__, 'confirm'));
        add_action('admin_post_nopriv_dse_ml_confirm', array(__CLASS__, 'confirm'));
        add_action('admin_post_dse_ml_unsubscribe', array(__CLASS__, 'unsubscribe'));
        add_action('admin_post_nopriv_dse_ml_unsubscribe', array(__CLASS__, 'unsubscribe'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'dispatch'), 10, 1);
        add_shortcode('dse_newsletter_inscription', array(__CLASS__, 'signup_form'));
    }

    public static function maybe_install()
    {
        if (current_user_can('manage_options') && get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function transport_ready()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active('fluent-smtp/fluent-smtp.php')) { return false; }
        // FluentSMTP 2.4.5 stores configured connections in this setting.
        // The application never reads or stores SMTP passwords.
        $settings = get_option('fluentmail-settings', array());
        return is_array($settings) && isset($settings['connections']) &&
            is_array($settings['connections']) && count($settings['connections']) > 0;
    }

    private static function require_admin($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die('Réservé à l’administration.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_ml_' . $action);
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) { self::install(); }
    }

    private static function input($key)
    {
        $v = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        return is_string($v) ? trim($v) : '';
    }

    private static function id($key)
    {
        $raw = self::input($key);
        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : 0;
    }

    private static function redirect($notice)
    {
        $section = 'envois';
        if (in_array($notice, array('list_created', 'bad_list', 'contacts_added', 'consent_required',
            'too_many', 'contact_removed'), true)) { $section = 'listes'; }
        elseif (in_array($notice, array('started', 'paused', 'not_ready', 'cron_failed'), true)) { $section = 'suivi'; }
        elseif (in_array($notice, array('test_accepted', 'test_failed', 'smtp_missing'), true)) { $section = 'connexion'; }
        wp_safe_redirect(add_query_arg(array('page' => 'dendrila-simple-edit',
            'dse_tab' => 'newsletters', 'dse_nl_section' => $section,
            'dse_ml_notice' => $notice), admin_url('admin.php')));
        exit;
    }

    public static function lists()
    {
        global $wpdb;
        return $wpdb->get_results('SELECT id,name FROM ' . self::table('lists') . ' ORDER BY name ASC');
    }

    public static function list()
    {
        self::require_admin('list');
        $name = sanitize_text_field(self::input('name'));
        if ($name === '' || strlen($name) > 160) { self::redirect('bad_list'); }
        global $wpdb;
        $wpdb->insert(self::table('lists'), array('name' => $name, 'created_at' => current_time('mysql', true)), array('%s', '%s'));
        self::redirect($wpdb->insert_id ? 'list_created' : 'error');
    }

    private static function list_exists($id)
    {
        global $wpdb;
        return $id > 0 && (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('lists') . ' WHERE id=%d', $id));
    }

    private static function upsert_contact($email, $list_id, $verified, $origin, $self_subscribed = false)
    {
        global $wpdb;
        $table = self::table('contacts');
        $email = strtolower(sanitize_email($email));
        if (!is_email($email)) { return false; }
        $contact = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email=%s", $email));
        $now = current_time('mysql', true);
        if (!$contact) {
            $wpdb->insert($table, array('email' => $email,
                'status' => $verified ? 'confirmed' : 'pending',
                'consent_source' => $verified ? $origin : '',
                'consent_at' => $verified ? $now : null,
                'created_at' => $now));
            $cid = (int) $wpdb->insert_id;
        } else {
            $cid = (int) $contact->id;
            // Previously unsubscribed people are never silently reactivated by import.
            if ($self_subscribed && $contact->status === 'unsubscribed') {
                $wpdb->update($table, array('status' => 'pending', 'consent_source' => '', 'consent_at' => null), array('id' => $cid));
            }
            if ($verified && $contact->status === 'pending') {
                $wpdb->update($table, array('status' => 'confirmed',
                    'consent_source' => $origin, 'consent_at' => $now), array('id' => $cid));
            }
        }
        if ($cid < 1) { return false; }
        $members = self::table('members');
        $membership = $wpdb->get_row($wpdb->prepare(
            "SELECT id,status FROM $members WHERE list_id=%d AND contact_id=%d", $list_id, $cid));
        if (!$membership) {
            $wpdb->insert($members, array('list_id' => $list_id, 'contact_id' => $cid,
                'status' => $verified && $contact && $contact->status === 'unsubscribed' ? 'unsubscribed' :
                    ($verified ? 'confirmed' : 'pending'),
                'consent_source' => $verified ? $origin : '',
                'consent_at' => $verified ? $now : null));
        } elseif ($self_subscribed && $membership->status === 'unsubscribed') {
            $wpdb->update($members, array('status' => 'pending', 'consent_source' => '',
                'consent_at' => null), array('id' => (int) $membership->id));
        } elseif ($verified && $membership->status === 'pending' &&
            (!$contact || $contact->status !== 'unsubscribed')) {
            $wpdb->update($members, array('status' => 'confirmed',
                'consent_source' => $origin, 'consent_at' => $now),
                array('id' => (int) $membership->id));
        }
        return $cid;
    }

    public static function import()
    {
        self::require_admin('import');
        $list_id = self::id('list_id');
        $origin = sanitize_text_field(self::input('origin'));
        $raw = self::input('emails');
        if (!self::list_exists($list_id) || !isset($_POST['consent_confirmed']) ||
            strlen($origin) < 8 || strlen($origin) > 255) { self::redirect('consent_required'); }
        $lines = preg_split('/[\r\n;,]+/', $raw);
        if (count($lines) > 200) { self::redirect('too_many'); }
        $unique = array_unique(array_filter(array_map('trim', $lines)));
        foreach ($unique as $email) {
            self::upsert_contact($email, $list_id, true, $origin);
        }
        self::redirect('contacts_added');
    }

    private static function newsletter_approved($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== DSE_Newsletters::TYPE || $post->post_status !== 'pending') { return false; }
        $hash = (string) get_post_meta($post->ID, DSE_Newsletters::APPROVED, true);
        return $hash !== '' && hash_equals(DSE_Newsletters::fingerprint($post), $hash) ? $post : false;
    }

    public static function prepare()
    {
        self::require_admin('prepare');
        global $wpdb;
        $post = self::newsletter_approved(self::id('newsletter_id'));
        $list_id = self::id('list_id');
        if (!$post || !self::list_exists($list_id)) { self::redirect('approval_required'); }
        $contacts = $wpdb->get_col($wpdb->prepare('SELECT c.id FROM ' . self::table('contacts') . ' c ' .
            'INNER JOIN ' . self::table('members') . ' m ON m.contact_id=c.id ' .
            'WHERE m.list_id=%d AND c.status=%s AND m.status=%s ORDER BY c.id ASC', $list_id, 'confirmed', 'confirmed'));
        if (!$contacts) { self::redirect('empty_list'); }
        $html = DSE_Block_Editor::document($post->post_content);
        $ok = $wpdb->insert(self::table('campaigns'), array(
            'newsletter_id' => $post->ID, 'list_id' => $list_id,
            'subject' => $post->post_title, 'html_body' => $html, 'status' => 'prepared',
            'created_at' => current_time('mysql', true)));
        if (!$ok) { self::redirect('error'); }
        $campaign_id = (int) $wpdb->insert_id;
        foreach ($contacts as $cid) {
            $wpdb->insert(self::table('queue'), array('campaign_id' => $campaign_id,
                'contact_id' => (int) $cid, 'status' => 'queued'));
        }
        self::redirect('prepared');
    }

    public static function start()
    {
        self::require_admin('start');
        if (!self::transport_ready()) { self::redirect('smtp_missing'); }
        if (self::input('confirm_send') !== '1') { self::redirect('confirmation_required'); }
        global $wpdb;
        $id = self::id('campaign_id');
        $table = self::table('campaigns');
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$campaign || !in_array($campaign->status, array('prepared', 'paused'), true)) {
            self::redirect('not_ready');
        }
        $when = self::input('send_at');
        $timestamp = time() + 15;
        if ($when !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $when, wp_timezone());
            if (!$date || $date->getTimestamp() < time() + 60) { self::redirect('invalid_date'); }
            $timestamp = $date->getTimestamp();
        }
        $now = current_time('mysql', true);
        $changed = $wpdb->update($table, array('status' => $when === '' ? 'sending' : 'scheduled',
            'started_at' => $now, 'send_at' => gmdate('Y-m-d H:i:s', $timestamp)),
            array('id' => $id, 'status' => $campaign->status));
        if ($changed !== 1) { self::redirect('not_ready'); }
        if (!wp_schedule_single_event($timestamp, self::CRON_HOOK, array($id))) {
            $wpdb->update($table, array('status' => 'paused'), array('id' => $id));
            self::redirect('cron_failed');
        }
        self::redirect('started');
    }

    public static function pause()
    {
        self::require_admin('pause');
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('campaigns') .
            " SET status='paused' WHERE id=%d AND status IN ('sending','scheduled')", self::id('campaign_id')));
        self::redirect('paused');
    }

    private static function token($contact, $purpose, $list_id = 0)
    {
        return hash_hmac('sha256', $purpose . '|' . (int) $contact->id . '|' .
            (int) $list_id . '|' . strtolower($contact->email), wp_salt('auth'));
    }

    private static function recipient_url($contact, $action, $list_id = 0)
    {
        return add_query_arg(array('action' => 'dse_ml_' . $action,
            'cid' => $contact->id, 'lid' => (int) $list_id,
            'token' => self::token($contact, $action, $list_id)), admin_url('admin-post.php'));
    }

    private static function contact_from_token($action)
    {
        global $wpdb;
        $cid = isset($_REQUEST['cid']) && ctype_digit((string) $_REQUEST['cid']) ? (int) $_REQUEST['cid'] : 0;
        $token = isset($_REQUEST['token']) && is_string($_REQUEST['token']) ? $_REQUEST['token'] : '';
        $lid = isset($_REQUEST['lid']) && ctype_digit((string) $_REQUEST['lid']) ? (int) $_REQUEST['lid'] : 0;
        if (!$cid || !preg_match('/^[a-f0-9]{64}$/', $token) || ($action === 'confirm' && !$lid)) { return false; }
        $contact = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('contacts') . ' WHERE id=%d', $cid));
        if (!$contact || !hash_equals(self::token($contact, $action, $lid), $token)) { return false; }
        $contact->requested_list = $lid;
        return $contact;
    }

    public static function unsubscribe()
    {
        $contact = self::contact_from_token('unsubscribe');
        if (!$contact) { wp_die('Ce lien ne peut pas être utilisé.', 'Lien invalide', array('response' => 400)); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $url = esc_url(admin_url('admin-post.php'));
            echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Se désinscrire</title><body style="max-width:500px;margin:4em auto;font:16px Arial,sans-serif">';
            echo '<h1>Se désinscrire</h1><p>Ne plus recevoir les newsletters de ce site ?</p>';
            echo '<form method="post" action="' . $url . '"><input type="hidden" name="action" value="dse_ml_unsubscribe">';
            echo '<input type="hidden" name="cid" value="' . (int) $contact->id . '">';
            echo '<input type="hidden" name="token" value="' . esc_attr(self::token($contact, 'unsubscribe')) . '">';
            echo '<button type="submit">Confirmer ma désinscription</button></form></body></html>';
            exit;
        }
        global $wpdb;
        $wpdb->update(self::table('contacts'), array('status' => 'unsubscribed'), array('id' => (int) $contact->id));
        $wpdb->update(self::table('members'), array('status' => 'unsubscribed'), array('contact_id' => (int) $contact->id));
        wp_die('Votre désinscription a bien été enregistrée.', 'Désinscription', array('response' => 200));
    }

    public static function confirm()
    {
        $contact = self::contact_from_token('confirm');
        if (!$contact) {
            wp_die('Lien de confirmation expiré ou invalide.', 'Confirmation', array('response' => 400));
        }
        global $wpdb;
        $members = self::table('members');
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $members .
            ' WHERE contact_id=%d AND list_id=%d', $contact->id, $contact->requested_list));
        if ($status !== 'pending') {
            wp_die('Lien déjà utilisé ou invalide.', 'Confirmation', array('response' => 400));
        }
        $now = current_time('mysql', true);
        $wpdb->update($members, array('status' => 'confirmed',
            'consent_source' => 'Formulaire avec double confirmation', 'consent_at' => $now),
            array('contact_id' => (int) $contact->id, 'list_id' => (int) $contact->requested_list));
        $wpdb->update(self::table('contacts'), array('status' => 'confirmed',
            'consent_source' => 'Formulaire avec double confirmation', 'consent_at' => $now),
            array('id' => (int) $contact->id));
        wp_die('Votre abonnement est confirmé. Merci !', 'Inscription', array('response' => 200));
    }

    public static function signup_form($atts)
    {
        $atts = shortcode_atts(array('liste' => 0), $atts, 'dse_newsletter_inscription');
        $id = (int) $atts['liste'];
        if (!DSE_Modules::enabled('newsletters') || $id < 1 || !self::list_exists($id)) { return ''; }
        $action = esc_url(admin_url('admin-post.php'));
        $html = '<form method="post" action="' . $action . '">' .
            '<input type="hidden" name="action" value="dse_ml_subscribe">' .
            '<input type="hidden" name="list_id" value="' . $id . '">' .
            wp_nonce_field('dse_ml_subscribe_' . $id, '_wpnonce', true, false) .
            '<p><label>Votre adresse e-mail <input type="email" name="email" required autocomplete="email"></label></p>' .
            '<p style="display:none"><label>Site web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>' .
            '<p><label><input type="checkbox" name="consent" value="1" required> Je souhaite recevoir les newsletters et je peux me désinscrire à tout moment.</label></p>' .
            '<button type="submit">M’inscrire</button></form>';
        return $html;
    }

    public static function subscribe()
    {
        $list_id = self::id('list_id');
        if (!self::list_exists($list_id) || !wp_verify_nonce(self::input('_wpnonce'), 'dse_ml_subscribe_' . $list_id) ||
            self::input('website') !== '' || self::input('consent') !== '1' || !self::transport_ready()) {
            wp_die('Inscription indisponible. Réessayez plus tard.', 'Inscription', array('response' => 400));
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $key = 'dse_ml_signup_' . hash_hmac('sha256', $ip, wp_salt('nonce'));
        if (get_transient($key)) { wp_die('Veuillez réessayer dans quelques minutes.', 'Inscription', array('response' => 429)); }
        set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        $email = strtolower(sanitize_email(self::input('email')));
        if (is_email($email)) {
            $cid = self::upsert_contact($email, $list_id, false, '', true);
            if ($cid) {
                global $wpdb;
                $contact = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('contacts') . ' WHERE id=%d', $cid));
                $member_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . self::table('members') .
                    ' WHERE contact_id=%d AND list_id=%d', $cid, $list_id));
                if ($contact && $member_status === 'pending') {
                    $url = self::recipient_url($contact, 'confirm', $list_id);
                    wp_mail($email, 'Confirmez votre inscription à la newsletter',
                        'Pour confirmer votre inscription, ouvrez ce lien : ' . $url);
                }
            }
        }
        wp_die('Si votre inscription est possible, vous recevrez un e-mail de confirmation.', 'Inscription', array('response' => 200));
    }

    public static function test()
    {
        self::require_admin('test');
        if (!self::transport_ready()) { self::redirect('smtp_missing'); }
        $user = wp_get_current_user();
        $sent = wp_mail($user->user_email, 'Test Simple Edit / FluentSMTP',
            'Ce message confirme le passage de Simple Edit par la fonction wp_mail de WordPress.');
        self::redirect($sent ? 'test_accepted' : 'test_failed');
    }

    public static function remove()
    {
        self::require_admin('remove');
        global $wpdb;
        $cid = self::id('contact_id');
        $lid = self::id('list_id');
        if (!$cid || !$lid) { self::redirect('error'); }
        // Remove membership for this list only; don't silently affect other lists.
        $wpdb->update(self::table('members'), array('status' => 'unsubscribed'),
            array('contact_id' => $cid, 'list_id' => $lid));
        $remaining = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('members') .
            ' WHERE contact_id=%d AND status=%s', $cid, 'confirmed'));
        if ($remaining === 0) {
            $wpdb->update(self::table('contacts'), array('status' => 'unsubscribed'), array('id' => $cid));
        }
        self::redirect('contact_removed');
    }

    /**
     * Always append the mandatory unsubscribe footer even when the e-mail
     * template is missing a literal </body>, or uses a differently cased tag.
     */
    public static function insert_required_footer($html, $footer)
    {
        $html = is_string($html) ? $html : '';
        $footer = is_string($footer) ? $footer : '';
        if ($footer === '') { return $html; }
        $end = stripos($html, '</body>');
        if ($end === false) { $end = stripos($html, '</html>'); }
        if ($end === false) { return $html . $footer; }
        return substr_replace($html, $footer, $end, 0);
    }

    public static function dispatch($campaign_id)
    {
        global $wpdb;
        $campaign_id = (int) $campaign_id;
        if (!$campaign_id || !self::transport_ready()) { return; }
        $ct = self::table('campaigns');
        $qt = self::table('queue');
        $people = self::table('contacts');
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ct WHERE id=%d", $campaign_id));
        if (!$campaign || !in_array($campaign->status, array('scheduled', 'sending'), true)) { return; }
        if ($campaign->status === 'scheduled' && strtotime($campaign->send_at . ' UTC') > time()) { return; }
        // Atomic lock protects against concurrent WP Cron requests on busy sites.
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $ct SET locked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND), status='sending' WHERE id=%d AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP()) AND status IN ('scheduled','sending')", $campaign_id));
        if ($claimed !== 1) { return; }
        try {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT q.id,q.contact_id,c.email,c.status FROM $qt q JOIN $people c ON c.id=q.contact_id WHERE q.campaign_id=%d AND q.status='queued' ORDER BY q.id ASC LIMIT %d", $campaign_id, self::BATCH_SIZE));
            // Uncertain messages are never resent automatically after a worker crash.
            // A crash may happen after SMTP acceptance and before queue acknowledgement.
            $wpdb->query("UPDATE $qt SET status='uncertain',error_message='Vérification manuelle requise après interruption' " .
                "WHERE campaign_id=" . (int) $campaign_id . " AND status='processing' AND " .
                "processing_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)");
            foreach ((array) $rows as $row) {
                $wpdb->query($wpdb->prepare("UPDATE $qt SET status='processing', processing_started_at=UTC_TIMESTAMP() WHERE id=%d AND status='queued'", (int) $row->id));
                if ($wpdb->rows_affected !== 1) { continue; }
                $still_sending = $wpdb->get_var($wpdb->prepare("SELECT status FROM $ct WHERE id=%d", $campaign_id));
                if ($still_sending !== 'sending') {
                    $wpdb->update($qt, array('status' => 'queued'), array('id' => (int) $row->id));
                    break;
                }
                $eligible = (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . self::table('members') .
                    ' WHERE list_id=%d AND contact_id=%d AND status=%s',
                    (int) $campaign->list_id, (int) $row->contact_id, 'confirmed'));
                if ($row->status !== 'confirmed' || !$eligible) {
                    $wpdb->update($qt, array('status' => 'skipped'), array('id' => (int) $row->id));
                    continue;
                }
                $contact = (object) array('id' => $row->contact_id, 'email' => $row->email);
                $url = self::recipient_url($contact, 'unsubscribe');
                $footer = '<footer style="margin:30px auto 0;padding:18px 0;text-align:center;color:#555;font-size:13px">' .
                    '<a href="' . esc_url($url) . '">Ne plus recevoir les newsletters de ce site</a></footer>';
                $html = self::insert_required_footer($campaign->html_body, $footer);
                $headers = array('Content-Type: text/html; charset=UTF-8',
                    'List-Unsubscribe: <' . esc_url_raw($url) . '>',
                    'List-Unsubscribe-Post: List-Unsubscribe=One-Click');
                try {
                    $ok = (bool) wp_mail($row->email, $campaign->subject, $html, $headers);
                    $error = $ok ? '' : 'WordPress/SMTP a refusé le message.';
                } catch (Throwable $error_object) {
                    $ok = false;
                    $error = 'Erreur du transport de messagerie.';
                }
                // "accepted" means SMTP accepted the message, not inbox delivery.
                $wpdb->update($qt, array('status' => $ok ? 'accepted' : 'failed',
                    'error_message' => $error,
                    'accepted_at' => $ok ? current_time('mysql', true) : null),
                    array('id' => (int) $row->id));
            }
            $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $qt WHERE campaign_id=%d AND status='queued'", $campaign_id));
            $current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $ct WHERE id=%d", $campaign_id));
            if ($current_status === 'paused') { return; }
            if ($remaining > 0) {
                wp_schedule_single_event(time() + 60, self::CRON_HOOK, array($campaign_id));
            } else {
                $wpdb->update($ct, array('status' => 'completed',
                    'finished_at' => current_time('mysql', true)), array('id' => $campaign_id));
            }
        } finally {
            $wpdb->update($ct, array('locked_until' => null), array('id' => $campaign_id));
        }
    }

    public static function render_admin($section = 'envois')
    {
        if (!current_user_can('manage_options')) { return; }
        if (!in_array($section, array('listes', 'envois', 'suivi', 'connexion'), true)) { return; }
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) { self::install(); }
        global $wpdb;
        $lists = self::lists();
        $notices = array(
            'list_created' => 'Liste créée.', 'bad_list' => 'Nom de liste incorrect.',
            'contacts_added' => 'Contacts ajoutés avec leur preuve de consentement.',
            'consent_required' => 'Indiquez la provenance du consentement et confirmez-le.',
            'too_many' => '200 contacts au maximum par import.',
            'approval_required' => 'La newsletter doit d’abord être validée.',
            'empty_list' => 'Aucun abonné confirmé dans cette liste.',
            'prepared' => 'Campagne préparée, aucun e-mail envoyé.',
            'started' => 'Campagne programmée. Le traitement dépend de WP-Cron.',
            'paused' => 'Campagne suspendue.',
            'smtp_missing' => 'Activez FluentSMTP et configurez une connexion avant tout envoi.',
            'confirmation_required' => 'Confirmez explicitement le lancement.',
            'invalid_date' => 'Choisissez une date future valide.',
            'test_accepted' => 'Test accepté par WordPress/FluentSMTP. Vérifiez sa réception.',
            'test_failed' => 'Test refusé : consultez le journal FluentSMTP.',
            'contact_removed' => 'Contact retiré de la liste sélectionnée.',
            'not_ready' => 'Cette campagne n’est pas prête.',
            'cron_failed' => 'La programmation a échoué. Aucun envoi démarré.',
            'error' => 'Opération non enregistrée.',
        );
        $notice = isset($_GET['dse_ml_notice']) ? sanitize_key(wp_unslash($_GET['dse_ml_notice'])) : '';
        if (isset($notices[$notice])) { echo '<p class="notice notice-info" role="status">' . esc_html($notices[$notice]) . '</p>'; }
        if ($section === 'connexion') {
        echo '<section class="dse-card"><h2>Connexion pour envoyer les e-mails</h2><p>FluentSMTP assure l’envoi des messages depuis WordPress. Vérifiez sa connexion avant de lancer une newsletter.</p>';
        echo '<p><strong>Transport :</strong> ' . (self::transport_ready() ?
            'FluentSMTP détecté. Vérifiez sa connexion avant de lancer une campagne.' :
            'FluentSMTP n’a pas encore de connexion prête. Les campagnes ne peuvent pas démarrer.') . '</p>';
        if (self::transport_ready()) { echo '<p><a href="' . esc_url(admin_url('options-general.php?page=fluent-mail')) . '">Ouvrir FluentSMTP</a></p>'; }
        if (self::transport_ready()) { self::form('test', 'Envoyer un e-mail de test à mon adresse', array()); }
        echo '</section>';
        }
        if ($section === 'listes') {
        echo '<section class="dse-card"><h2>Créer une liste</h2>';
        self::form('list', 'Créer la liste', array('<label>Nom de la liste <input name="name" type="text" required maxlength="160"></label>'));
        echo '</section>';
        echo '<section class="dse-card"><h2>Listes et contacts</h2>';
        foreach ($lists as $list) {
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('members') .
                ' m JOIN ' . self::table('contacts') . ' c ON c.id=m.contact_id WHERE m.list_id=%d AND c.status=%s AND m.status=%s',
                $list->id, 'confirmed', 'confirmed'));
            echo '<details class="dse-subcard"><summary><strong>' . esc_html($list->name) . '</strong> · ' .
                (int) $count . ' abonnés confirmés</summary>';
            echo '<p>Formulaire à placer sur une page : <code>[dse_newsletter_inscription liste="' .
                (int) $list->id . '"]</code></p>';
            $members = $wpdb->get_results($wpdb->prepare('SELECT c.id,c.email,m.status FROM ' .
                self::table('members') . ' m JOIN ' . self::table('contacts') .
                ' c ON c.id=m.contact_id WHERE m.list_id=%d ORDER BY c.email ASC LIMIT 30', (int) $list->id));
            foreach ((array) $members as $member) {
                echo '<div class="dse-mailing-contact"><span>' . esc_html($member->email) .
                    ' — ' . esc_html($member->status) . '</span>';
                if ($member->status !== 'unsubscribed') {
                    self::form('remove', 'Retirer de cette liste', array(
                        '<input type="hidden" name="contact_id" value="' . (int) $member->id . '">',
                        '<input type="hidden" name="list_id" value="' . (int) $list->id . '">'
                    ));
                }
                echo '</div>';
            }
            echo '<p class="description">Affichage limité aux 30 premiers contacts. Aucun envoi n’a lieu ici.</p></details>';
        }
        if ($lists) {
            echo '<details><summary>Importer des contacts ayant déjà donné leur accord</summary>';
            $options = self::options($lists);
            self::form('import', 'Ajouter des contacts', array(
                '<label>Liste <select name="list_id" required>' . $options . '</select></label>',
                '<label>Adresses e-mail (une par ligne, maximum 200) <textarea name="emails" rows="5" required></textarea></label>',
                '<label>Origine du consentement <input name="origin" type="text" minlength="8" maxlength="255" required placeholder="Ex. formulaire du site, le 10/11/2026"></label>',
                '<label class="dse-check"><input type="checkbox" name="consent_confirmed" value="1" required> Je confirme disposer d’un accord valable pour chacun de ces contacts.</label>'
            ));
            echo '</details>';
        }
        echo '</section>';
        }
        if ($section === 'envois') {
        $approved = array_filter(get_posts(array('post_type' => DSE_Newsletters::TYPE,
            'post_status' => 'pending', 'posts_per_page' => 50)), array(__CLASS__, 'is_approved'));
        echo '<section class="dse-card"><h2>Préparer une campagne</h2>';
        if (!$approved || !$lists) { echo '<p>Il faut au moins une newsletter validée et une liste.</p>'; }
        else {
            $choices = '<option value="">Choisir une newsletter validée</option>';
            foreach ($approved as $post) { $choices .= '<option value="' . (int) $post->ID . '">' . esc_html($post->post_title) . '</option>'; }
            self::form('prepare', 'Préparer sans envoyer', array(
                '<label>Newsletter <select name="newsletter_id" required>' . $choices . '</select></label>',
                '<label>Destinataires <select name="list_id" required>' . self::options($lists) . '</select></label>'
            ));
        }
        echo '</section>';
        }
        if ($section === 'suivi') {
        $campaigns = $wpdb->get_results('SELECT * FROM ' . self::table('campaigns') . ' ORDER BY id DESC LIMIT 15');
        echo '<section class="dse-card"><h2>Campagnes préparées et envoyées</h2>';
        foreach ((array) $campaigns as $c) {
            $counts = $wpdb->get_results($wpdb->prepare('SELECT status,COUNT(*) as total FROM ' .
                self::table('queue') . ' WHERE campaign_id=%d GROUP BY status', $c->id));
            $stats = array();
            foreach ($counts as $row) { $stats[] = $row->status . ' : ' . (int) $row->total; }
            echo '<div class="dse-subcard"><h3>' . esc_html($c->subject) . '</h3>';
            echo '<p>État : <strong>' . esc_html($c->status) . '</strong> · ' . esc_html(implode(' · ', $stats)) . '</p>';
            if (in_array($c->status, array('prepared','paused'), true)) {
                echo '<p>Un clic sur « Lancer » n’envoie rien immédiatement : les messages seront traités par petits lots planifiés.</p>';
                self::form('start', 'Lancer ou programmer les envois', array(
                    '<input type="hidden" name="campaign_id" value="' . (int) $c->id . '">',
                    '<label>Envoyer à partir du (facultatif) <input type="datetime-local" name="send_at"></label>',
                    '<label class="dse-check"><input type="checkbox" name="confirm_send" value="1" required> Je confirme vouloir envoyer cette campagne aux abonnés de la liste.</label>'
                ));
            } elseif (in_array($c->status, array('sending','scheduled'), true)) {
                self::form('pause', 'Suspendre les envois', array('<input type="hidden" name="campaign_id" value="' . (int) $c->id . '">'));
            }
            echo '</div>';
        }
        echo '</section>';
        }
    }

    public static function is_approved($post)
    {
        return $post && self::newsletter_approved((int) $post->ID);
    }

    private static function options($lists)
    {
        $str = '';
        foreach ($lists as $list) {
            $str .= '<option value="' . (int) $list->id . '">' . esc_html($list->name) . '</option>';
        }
        return $str;
    }

    private static function form($action, $label, $fields)
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dse-mailing-form">';
        echo '<input type="hidden" name="action" value="dse_ml_' . esc_attr($action) . '">';
        wp_nonce_field('dse_ml_' . $action);
        foreach ($fields as $field) { echo $field; } // Static, carefully escaped strings.
        echo '<p><button type="submit" class="button button-primary">' . esc_html($label) . '</button></p></form>';
    }
}
