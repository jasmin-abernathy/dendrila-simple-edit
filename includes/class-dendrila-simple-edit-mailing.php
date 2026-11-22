<?php
/**
 * Dendrila Simple Edit newsletter delivery (beta). Only administrators manage
 * subscribers and mailing, only confirmed subscribers receive campaigns.
 * FluentSMTP transports ordinary wp_mail() messages and owns SMTP credentials.
 */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Mailing
{
    const DB_VERSION = '5';
    const DB_OPTION = 'dendrila_simple_edit_mailing_schema_version';
    const CRON_HOOK = 'dendrila_simple_edit_mailing_dispatch';
    const BATCH_SIZE = 5;

    private static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'dendrila_simple_edit_ml_' . $name;
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
            owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_user_id (owner_user_id)
        ) $charset;");
        dbDelta("CREATE TABLE $contacts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(254) NOT NULL,
            display_name varchar(160) NOT NULL DEFAULT '',
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
        $reinvites = self::table('reinvites');
        dbDelta("CREATE TABLE $reinvites (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            contact_id bigint(20) unsigned NOT NULL,
            list_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            permission_source varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            confirmed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY contact_id (contact_id),
            KEY expires_at (expires_at)
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
        // Never declare schema ready until legacy unsubscribe history has migrated.
        if (!Dendrila_Simple_Edit_Suppressions::migrate()) { return; }
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function boot()
    {
        add_action('admin_init', array(__CLASS__, 'maybe_install'));
        foreach (array('list', 'import', 'prepare', 'start', 'pause', 'test', 'remove',
            'edit_contact', 'reinvite', 'assign_list') as $task) {
            add_action('admin_post_dendrila_simple_edit_ml_' . $task, array(__CLASS__, $task));
        }
        add_action('admin_post_dendrila_simple_edit_ml_subscribe', array(__CLASS__, 'subscribe'));
        add_action('admin_post_nopriv_dendrila_simple_edit_ml_subscribe', array(__CLASS__, 'subscribe'));
        add_action('admin_post_dendrila_simple_edit_ml_confirm', array(__CLASS__, 'confirm'));
        add_action('admin_post_nopriv_dendrila_simple_edit_ml_confirm', array(__CLASS__, 'confirm'));
        add_action('admin_post_dendrila_simple_edit_ml_reconfirm', array(__CLASS__, 'reconfirm'));
        add_action('admin_post_nopriv_dendrila_simple_edit_ml_reconfirm', array(__CLASS__, 'reconfirm'));
        add_action('admin_post_dendrila_simple_edit_ml_unsubscribe', array(__CLASS__, 'unsubscribe'));
        add_action('admin_post_nopriv_dendrila_simple_edit_ml_unsubscribe', array(__CLASS__, 'unsubscribe'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'dispatch'), 10, 1);
        add_shortcode('dse_newsletter_inscription', array(__CLASS__, 'signup_form'));
    }

    public static function maybe_install()
    {
        if (current_user_can('manage_options') && get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    /** Mailing depends on FluentSMTP, but the other editing modules do not. */
    public static function fluent_smtp_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active('fluent-smtp/fluent-smtp.php');
    }

    public static function transport_ready()
    {
        if (!self::fluent_smtp_active()) { return false; }
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
        check_admin_referer('dendrila_simple_edit_ml_' . $action);
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) { self::install(); }
    }

    private static function input($key)
    {
        $v = isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : '';
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
            'too_many', 'list_assigned', 'contact_removed', 'contact_updated', 'edit_email_confirmation',
            'test_failed', 'smtp_missing',
            'duplicate_email', 'blocked_unsubscribed', 'invite_sent', 'invite_failed',
            'invite_no_permission', 'invite_rate_limited', 'invite_not_allowed',
            'invalid_contact'), true)) { $section = 'listes'; }
        elseif (in_array($notice, array('prepared', 'started', 'paused', 'not_ready', 'cron_failed'), true)) { $section = 'suivi'; }
        elseif (in_array($notice, array('test_accepted', 'test_failed', 'smtp_missing'), true)) { $section = 'connexion'; }
        wp_safe_redirect(add_query_arg(array('page' => 'dendrila-simple-edit',
            'dendrila_simple_edit_tab' => 'newsletters', 'dendrila_simple_edit_nl_section' => $section,
            'dendrila_simple_edit_ml_notice' => $notice), admin_url('admin.php')));
        exit;
    }

    public static function lists()
    {
        global $wpdb;
        $table = self::table('lists');
        if (current_user_can('manage_options')) {
            return $wpdb->get_results($wpdb->prepare('SELECT id,name,owner_user_id FROM %i ORDER BY name ASC', $table));
        }
        // Assigned lists can be used by send-only accounts without exposing others.
        if (!Dendrila_Simple_Edit_Newsletters::can_send() &&
            !Dendrila_Simple_Edit_Modules::allowed('newsletters', 'manage_lists')) { return array(); }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id,name,owner_user_id FROM %i WHERE owner_user_id=%d ORDER BY name ASC',
            $table, get_current_user_id()));
    }

    private static function owns_list($id)
    {
        if ($id < 1) { return false; }
        if (current_user_can('manage_options')) { return self::list_exists($id); }
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id=%d AND owner_user_id=%d',
            self::table('lists'), $id,get_current_user_id()));
    }

    private static function can_manage_lists()
    {
        return current_user_can('manage_options') ||
            Dendrila_Simple_Edit_Modules::allowed('newsletters', 'manage_lists');
    }

    private static function require_lists($action, $list_id = 0)
    {
        if (!self::can_manage_lists() || ($list_id > 0 && !self::owns_list($list_id))) {
            wp_die('Gestion de cette liste non autorisée.', 'Accès refusé', array('response'=>403));
        }
        check_admin_referer('dendrila_simple_edit_ml_' . $action);
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            wp_die('Configuration incomplète : demandez la mise à jour du module à l’administrateur.',
                'Configuration nécessaire',array('response'=>503));
        }
    }

    public static function list()
    {
        self::require_lists('list');
        $name = sanitize_text_field(self::input('name'));
        if ($name === '' || strlen($name) > 160) { self::redirect('bad_list'); }
        global $wpdb;
        $wpdb->insert(self::table('lists'), array('name' => $name,
            'owner_user_id' => current_user_can('manage_options') ? 0 : get_current_user_id(),
            'created_at' => current_time('mysql', true)), array('%s', '%d', '%s'));
        self::redirect($wpdb->insert_id ? 'list_created' : 'error');
    }

    /** Assign an existing list to one delegated editor without changing members. */
    public static function assign_list()
    {
        self::require_admin('assign_list');
        $list_id = self::id('list_id');
        $owner = self::input('owner_user_id');
        if ($owner !== '0' && (!ctype_digit($owner) || (int)$owner < 1 ||
            !user_can((int)$owner,'dendrila_simple_edit_access') || user_can((int)$owner,'manage_options'))) {
            wp_die('Le nouveau responsable doit posséder le rôle Édition simple.',
                'Utilisateur invalide',array('response'=>400));
        }
        if (!self::list_exists($list_id)) { self::redirect('invalid_contact'); }
        global $wpdb;
        $updated = $wpdb->update(self::table('lists'),
            array('owner_user_id'=>(int)$owner),array('id'=>$list_id),array('%d'),array('%d'));
        self::redirect($updated !== false ? 'list_assigned' : 'error');
    }

    private static function list_exists($id)
    {
        global $wpdb;
        return $id > 0 && (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id=%d', self::table('lists'), $id));
    }

    private static function upsert_contact($email, $list_id, $verified, $origin, $self_subscribed = false)
    {
        global $wpdb;
        $table = self::table('contacts');
        $email = strtolower(sanitize_email($email));
        if (!Dendrila_Simple_Edit_Suppressions::ready()) { return false; }
        if (!is_email($email)) { return false; }
        if ($verified && Dendrila_Simple_Edit_Suppressions::is_blocked($email)) { return false; }
        $contact = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE email=%s', $table, $email));
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
            // Keep the global unsubscribe active until a new opt-in is confirmed.
            // In particular imports may never change this suppression status.
            if ($verified && $contact->status === 'pending') {
                $wpdb->update($table, array('status' => 'confirmed',
                    'consent_source' => $origin, 'consent_at' => $now), array('id' => $cid));
            }
        }
        if ($cid < 1) { return false; }
        // Rotate confirmation challenge on an explicit web signup, invalidating old links.
        // The contact stays globally suppressed until the new link is clicked.
        if ($self_subscribed) {
            $challenge = bin2hex(random_bytes(24));
            $wpdb->update($table, array('confirm_hash' => hash('sha256', $challenge)),
                array('id' => $cid));
            set_transient('dendrila_simple_edit_ml_confirmation_' . $cid . '_' . $list_id,
                hash('sha256', $challenge), 2 * DAY_IN_SECONDS);
        }
        $members = self::table('members');
        $membership = $wpdb->get_row($wpdb->prepare(
            'SELECT id,status FROM %i WHERE list_id=%d AND contact_id=%d', $members, $list_id, $cid));
        if (!$membership) {
            $wpdb->insert($members, array('list_id' => $list_id, 'contact_id' => $cid,
                'status' => $verified && $contact && $contact->status === 'unsubscribed' ? 'unsubscribed' :
                    ($verified ? 'confirmed' : 'pending'),
                'consent_source' => $verified && (!$contact || $contact->status !== 'unsubscribed') ? $origin : '',
                'consent_at' => $verified && (!$contact || $contact->status !== 'unsubscribed') ? $now : null));
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
        $list_id = self::id('list_id');
        self::require_lists('import', $list_id);
        $origin = sanitize_text_field(self::input('origin'));
        $raw = self::input('emails');
        if (!self::list_exists($list_id) || !isset($_POST['consent_confirmed']) ||
            strlen($origin) < 8 || strlen($origin) > 255) { self::redirect('consent_required'); }
        $lines = preg_split('/[\r\n;,]+/', $raw);
        if (count($lines) > 200) { self::redirect('too_many'); }
        $unique = array_unique(array_filter(array_map('trim', $lines)));
        $skipped = 0;
        $imported = 0;
        global $wpdb;
        foreach ($unique as $email) {
            $sanitized = strtolower(sanitize_email($email));
            if (!is_email($sanitized)) { continue; }
            $existing = $wpdb->get_row($wpdb->prepare(
                'SELECT id,status FROM %i WHERE email=%s', self::table('contacts'), $sanitized));
            $membership_status = $existing ? $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM %i WHERE contact_id=%d AND list_id=%d',
                self::table('members'), (int)$existing->id,$list_id)) : null;
            if (Dendrila_Simple_Edit_Suppressions::is_blocked($sanitized) ||
                ($existing && ($existing->status === 'unsubscribed' ||
                $membership_status === 'unsubscribed'))) {
                $skipped++;
                continue;
            }
            if (self::upsert_contact($sanitized, $list_id, true, $origin)) { $imported++; }
        }
        self::redirect($skipped ? 'blocked_unsubscribed' : 'contacts_added');
    }

    /** Change a contact's name, or start verification for a replacement email. */
    public static function edit_contact()
    {
        $list_id = self::id('list_id');
        self::require_lists('edit_contact', $list_id);
        global $wpdb;
        $id = self::id('contact_id');
        $table = self::table('contacts');
        $members = self::table('members');
        $contact = $id ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id=%d', $table, $id)) : false;
        $membership = $contact ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE contact_id=%d AND list_id=%d', $members, $id, $list_id)) : false;
        if (!$contact || !$membership || !self::list_exists($list_id)) {
            self::redirect('invalid_contact');
        }
        $display_name = sanitize_text_field(self::input('display_name'));
        if (strlen($display_name) > 160) { self::redirect('invalid_contact'); }
        $email = strtolower(sanitize_email(self::input('email')));
        if (!is_email($email)) { self::redirect('invalid_contact'); }
        if ($email !== strtolower($contact->email)) {
            // Editing an e-mail must never transfer the existing opt-in to someone
            // else. It creates a separate pending contact and requires confirmation.
            if ($contact->status === 'unsubscribed' ||
                $membership->status === 'unsubscribed') {
                self::redirect('blocked_unsubscribed');
            }
            $origin = sanitize_text_field(self::input('email_permission_source'));
            if (self::input('email_permission') !== '1' ||
                strlen($origin) < 10 || strlen($origin) > 255) {
                self::redirect('consent_required');
            }
            if (!self::transport_ready()) { self::redirect('smtp_missing'); }
            if ($wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE email=%s', $table, $email))) {
                self::redirect('duplicate_email');
            }
            $new_id = self::upsert_contact($email, $list_id, false, '', true);
            if (!$new_id) { self::redirect('error'); }
            $wpdb->update($table, array('display_name'=>$display_name), array('id'=>$new_id));
            $new_contact = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM %i WHERE id=%d', $table, $new_id));
            $url = self::recipient_url($new_contact, 'confirm', $list_id);
            $accepted = wp_mail($email, 'Confirmez votre adresse pour la newsletter',
                "Vous avez demandé à confirmer votre nouvelle adresse pour la newsletter.\n" .
                "Cliquez sur ce lien pour accepter de recevoir les messages :\n" . $url .
                "\nSi vous n'avez rien demandé, ignorez ce message.");
            if (!$accepted) { self::redirect('test_failed'); }
            $wpdb->update($members, array('status'=>'unsubscribed'),
                array('contact_id'=>$id, 'list_id'=>$list_id));
            $other = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE contact_id=%d AND status='confirmed'", $members, $id));
            if (!$other) {
                $wpdb->update($table, array('status'=>'unsubscribed'), array('id'=>$id));
            }
            self::redirect('edit_email_confirmation');
        }
        $result = $wpdb->update($table, array('display_name'=>$display_name), array('id'=>$id));
        self::redirect($result !== false ? 'contact_updated' : 'error');
    }

    /**
     * A re-invitation is *not* a marketing subscription. An administrator must
     * attest prior, specific permission to send that one invitation and record
     * its provenance; the recipient then opts in again, using a short-lived link.
     */
    public static function reinvite()
    {
        $lid = self::id('list_id');
        self::require_lists('reinvite', $lid);
        global $wpdb;
        $cid = self::id('contact_id');
        $contacts = self::table('contacts');
        $members = self::table('members');
        $invites = self::table('reinvites');
        $contact = $cid ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id=%d', $contacts, $cid)) : false;
        $member = $contact ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE contact_id=%d AND list_id=%d', $members, $cid,$lid)) : false;
        if (!$contact || !$member || !self::list_exists($lid)) {
            self::redirect('invalid_contact');
        }
        if ($contact->status !== 'unsubscribed' &&
            $member->status !== 'unsubscribed') {
            self::redirect('invite_not_allowed');
        }
        $origin = sanitize_text_field(self::input('permission_source'));
        if (self::input('permission_confirmed') !== '1' ||
            strlen($origin) < 12 || strlen($origin) > 255) {
            self::redirect('invite_no_permission');
        }
        if (!self::transport_ready()) { self::redirect('smtp_missing'); }
        $recent = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE contact_id=%d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)', $invites, $cid));
        if ($recent) { self::redirect('invite_rate_limited'); }
        $raw_token = bin2hex(random_bytes(32));
        $created = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS);
        if (!$wpdb->insert($invites, array('contact_id'=>$cid,'list_id'=>$lid,
            'token_hash'=>hash('sha256',$raw_token),
            'permission_source'=>$origin, 'created_at'=>$created,
            'expires_at'=>$expires))) { self::redirect('error'); }
        $id = (int)$wpdb->insert_id;
        $url = add_query_arg(array('action'=>'dendrila_simple_edit_ml_reconfirm','rid'=>$id,
            'token'=>$raw_token), admin_url('admin-post.php'));
        $list_name = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT name FROM %i WHERE id=%d', self::table('lists'), $lid));
        $accepted = wp_mail($contact->email, 'Invitation à renouveler votre abonnement',
            "Vous avez accepté de recevoir une invitation pour vous réabonner à la liste « " .
            $list_name . " ».\n\nSi vous souhaitez recevoir de nouveau ces newsletters, " .
            "ouvrez ce lien et confirmez explicitement :\n" . $url .
            "\n\nSi vous ne souhaitez pas vous réabonner, ignorez ce message. " .
            "Aucune inscription ne sera effectuée sans votre confirmation.");
        if (!$accepted) {
            $wpdb->delete($invites,array('id'=>$id));
            self::redirect('invite_failed');
        }
        self::redirect('invite_sent');
    }

    /** Explicit, one-time, seven-day subscriber confirmation. No automatic opt-in. */
    public static function reconfirm()
    {
        global $wpdb;
        $raw_rid = isset($_REQUEST['rid']) ? sanitize_text_field(wp_unslash($_REQUEST['rid'])) : '';
        $rid = ctype_digit((string) $raw_rid) ? (int) $raw_rid : 0;
        $token = isset($_REQUEST['token']) && is_string($_REQUEST['token']) ?
            sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
        $table = self::table('reinvites');
        $invite = $rid ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id=%d', $table, $rid)) : false;
        if (!$invite || !preg_match('/^[a-f0-9]{64}$/',$token) ||
            !hash_equals($invite->token_hash,hash('sha256',$token)) ||
            $invite->confirmed_at !== null ||
            strtotime($invite->expires_at . ' UTC') <= time()) {
            wp_die('Ce lien a expiré ou a déjà été utilisé.', 'Lien invalide',
                array('response'=>400));
        }
        $contact = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id=%d', self::table('contacts'), (int)$invite->contact_id));
        $member = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE contact_id=%d AND list_id=%d',
            self::table('members'), (int)$invite->contact_id, (int)$invite->list_id));
        if (!$contact || !$member) {
            wp_die('Cet abonnement n’est plus disponible.', 'Abonnement',
                array('response'=>400));
        }
        if (strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'))) !== 'POST') {
            header('Cache-Control: no-store, private');
            echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Réabonnement</title>';
            echo '<body style="max-width:540px;margin:3em auto;padding:1em;font:17px/1.5 system-ui,sans-serif">';
            echo '<h1>Confirmer mon réabonnement</h1>';
            echo '<p>' . esc_html__('Souhaitez-vous recevoir à nouveau les newsletters de cette liste ?', 'dendrila-simple-edit') . '</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_ml_reconfirm">';
            echo '<input type="hidden" name="rid" value="'.(int)$rid.'">';
            echo '<input type="hidden" name="token" value="'.esc_attr($token).'">';
            echo '<p><label><input type="checkbox" name="confirm_optin" value="yes" required> Oui, ';
            echo 'je souhaite de nouveau recevoir ces newsletters. Je pourrai me désinscrire à tout moment.</label></p>';
            echo '<button type="submit">Confirmer mon abonnement</button></form></body></html>';
            exit;
        }
        if (!isset($_POST['confirm_optin']) || sanitize_text_field(wp_unslash($_POST['confirm_optin'])) !== 'yes') {
            wp_die('Vous devez accepter explicitement le réabonnement.',
                'Confirmation nécessaire',array('response'=>400));
        }
        $now = current_time('mysql',true);
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET confirmed_at=%s WHERE id=%d AND confirmed_at IS NULL AND expires_at > UTC_TIMESTAMP()', $table, $now,$rid));
        if ($updated !== 1) {
            wp_die('Ce lien a déjà été utilisé.', 'Confirmation',array('response'=>400));
        }
        $members = self::table('members');
        if (!Dendrila_Simple_Edit_Suppressions::ready() ||
            !Dendrila_Simple_Edit_Suppressions::reconsent($contact->email)) {
            wp_die('Réabonnement bloqué : historique non migré.', 'Réabonnement',array('response'=>503));
        }
        $wpdb->update($members,array('status'=>'confirmed',
            'consent_source'=>'Réabonnement volontaire confirmé via lien',
            'consent_at'=>$now),
            array('list_id'=>(int)$invite->list_id,'contact_id'=>(int)$invite->contact_id));
        $wpdb->update(self::table('contacts'),array('status'=>'confirmed',
            'consent_source'=>'Réabonnement volontaire confirmé via lien',
            'consent_at'=>$now),array('id'=>(int)$invite->contact_id));
        wp_die('Votre réabonnement a été confirmé. Merci !',
            'Réabonnement',array('response'=>200));
    }

    private static function newsletter_approved($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== Dendrila_Simple_Edit_Newsletters::TYPE || $post->post_status !== 'pending') { return false; }
        $hash = (string) get_post_meta($post->ID, Dendrila_Simple_Edit_Newsletters::APPROVED, true);
        return $hash !== '' && hash_equals(Dendrila_Simple_Edit_Newsletters::fingerprint($post), $hash) ? $post : false;
    }

    /** Author-safe preparation, identical to the newsletter editor's button state. */
    private static function can_prepare_newsletter($post)
    {
        return Dendrila_Simple_Edit_Newsletters::can_prepare_for_send($post);
    }

    /** Prepared and running campaigns belong to the newsletter author. */
    private static function can_manage_campaign($campaign)
    {
        if (!$campaign) { return false; }
        if (current_user_can('manage_options')) { return true; }
        return Dendrila_Simple_Edit_Newsletters::can_send() &&
            Dendrila_Simple_Edit_Newsletters::can_send(get_post((int)$campaign->newsletter_id));
    }

    private static function require_send($action)
    {
        if (!Dendrila_Simple_Edit_Newsletters::can_send()) {
            wp_die('Envoi de newsletters non autorisé.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dendrila_simple_edit_ml_' . $action);
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            wp_die('La configuration des envois doit être terminée par un administrateur.',
                'Envoi indisponible', array('response' => 503));
        }
    }

    public static function prepare()
    {
        self::require_send('prepare');
        global $wpdb;
        $post = get_post(self::id('newsletter_id'));
        $list_id = self::id('list_id');
        if (!self::can_prepare_newsletter($post)) {
            wp_die('Vous ne pouvez pas préparer cette newsletter.', 'Accès refusé', array('response' => 403));
        }
        if (!self::owns_list($list_id)) {
            wp_die('Vous ne pouvez pas utiliser cette liste de diffusion.',
                'Accès refusé',array('response'=>403));
        }
        if (!self::list_exists($list_id)) { self::redirect('empty_list'); }
        $contacts = $wpdb->get_col($wpdb->prepare('SELECT c.id FROM %i c INNER JOIN %i m ON m.contact_id=c.id WHERE m.list_id=%d AND c.status=%s AND m.status=%s ORDER BY c.id ASC',
            self::table('contacts'), self::table('members'), $list_id, 'confirmed', 'confirmed'));
        if (!$contacts) { self::redirect('empty_list'); }
        $html = Dendrila_Simple_Edit_Block_Editor::document($post->post_content,
            Dendrila_Simple_Edit_Newsletter_Templates::for_post($post));
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
        self::require_send('start');
        if (!self::transport_ready()) { self::redirect('smtp_missing'); }
        if (self::input('confirm_send') !== '1') { self::redirect('confirmation_required'); }
        global $wpdb;
        $id = self::id('campaign_id');
        $table = self::table('campaigns');
        $campaign = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $table, $id));
        if (!$campaign || !self::can_manage_campaign($campaign)) {
            wp_die('Vous ne pouvez pas lancer cette campagne.', 'Accès refusé', array('response' => 403));
        }
        if (!in_array($campaign->status, array('prepared', 'paused'), true)) {
            self::redirect('not_ready');
        }
        $when = self::input('send_at');
        if (!Dendrila_Simple_Edit_Suppressions::ready()) { self::redirect('not_ready'); }
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
        self::require_send('pause');
        global $wpdb;
        $id = self::id('campaign_id');
        $table = self::table('campaigns');
        $campaign = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $table, $id));
        if (!self::can_manage_campaign($campaign)) {
            wp_die('Vous ne pouvez pas suspendre cette campagne.', 'Accès refusé', array('response' => 403));
        }
        $wpdb->query($wpdb->prepare("UPDATE %i SET status='paused' WHERE id=%d AND status IN ('sending','scheduled')", $table, $id));
        self::redirect('paused');
    }

    private static function token($contact, $purpose, $list_id = 0)
    {
        $extra = '';
        if ($purpose === 'confirm') {
            $extra = '|' . (string) get_transient('dendrila_simple_edit_ml_confirmation_' .
                (int) $contact->id . '_' . (int) $list_id);
        }
        return hash_hmac('sha256', $purpose . '|' . (int) $contact->id . '|' .
            (int) $list_id . '|' . strtolower($contact->email) . $extra, wp_salt('auth'));
    }

    private static function recipient_url($contact, $action, $list_id = 0)
    {
        return add_query_arg(array('action' => 'dendrila_simple_edit_ml_' . $action,
            'cid' => $contact->id, 'lid' => (int) $list_id,
            'token' => self::token($contact, $action, $list_id)), admin_url('admin-post.php'));
    }

    private static function contact_from_token($action)
    {
        global $wpdb;
        $raw_cid = isset($_REQUEST['cid']) ? sanitize_text_field(wp_unslash($_REQUEST['cid'])) : '';
        $raw_lid = isset($_REQUEST['lid']) ? sanitize_text_field(wp_unslash($_REQUEST['lid'])) : '';
        $cid = ctype_digit((string) $raw_cid) ? (int) $raw_cid : 0;
        $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
        $lid = ctype_digit((string) $raw_lid) ? (int) $raw_lid : 0;
        if (!$cid || !preg_match('/^[a-f0-9]{64}$/', $token) || ($action === 'confirm' && !$lid)) { return false; }
        $contact = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('contacts'), $cid));
        if (!$contact || ($action === 'confirm' &&
            !get_transient('dendrila_simple_edit_ml_confirmation_' . (int) $cid . '_' . (int) $lid)) ||
            !hash_equals(self::token($contact, $action, $lid), $token)) { return false; }
        $contact->requested_list = $lid;
        return $contact;
    }

    public static function unsubscribe()
    {
        $contact = self::contact_from_token('unsubscribe');
        if (!$contact) { wp_die('Ce lien ne peut pas être utilisé.', 'Lien invalide', array('response' => 400)); }
        if ((!isset($_SERVER['REQUEST_METHOD']) || sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) !== 'POST')) {
            $url = admin_url('admin-post.php');
            echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Se désinscrire</title><body style="max-width:500px;margin:4em auto;font:16px Arial,sans-serif">';
            echo '<h1>Se désinscrire</h1><p>Ne plus recevoir les newsletters de ce site ?</p>';
            echo '<form method="post" action="' . esc_url($url) . '"><input type="hidden" name="action" value="dendrila_simple_edit_ml_unsubscribe">';
            echo '<input type="hidden" name="cid" value="' . (int) $contact->id . '">';
            echo '<input type="hidden" name="token" value="' . esc_attr(self::token($contact, 'unsubscribe')) . '">';
            echo '<button type="submit">Confirmer ma désinscription</button></form></body></html>';
            exit;
        }
        global $wpdb;
        if (!Dendrila_Simple_Edit_Suppressions::record($contact->email, 'désinscription e-mail')) {
            wp_die('Désinscription non enregistrée. Contactez le gestionnaire du site.', 'Erreur',array('response'=>503));
        }
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
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE contact_id=%d AND list_id=%d', $members, $contact->id, $contact->requested_list));
        if ($status !== 'pending') {
            wp_die('Lien déjà utilisé ou invalide.', 'Confirmation', array('response' => 400));
        }
        $now = current_time('mysql', true);
        if (!Dendrila_Simple_Edit_Suppressions::ready() ||
            !Dendrila_Simple_Edit_Suppressions::reconsent($contact->email)) {
            wp_die('Migration des désinscriptions incomplète.', 'Inscription indisponible', array('response'=>503));
        }
        $wpdb->update($members, array('status' => 'confirmed',
            'consent_source' => 'Formulaire avec double confirmation', 'consent_at' => $now),
            array('contact_id' => (int) $contact->id, 'list_id' => (int) $contact->requested_list));
        $wpdb->update(self::table('contacts'), array('status' => 'confirmed',
            'consent_source' => 'Formulaire avec double confirmation', 'consent_at' => $now),
            array('id' => (int) $contact->id));
        delete_transient('dendrila_simple_edit_ml_confirmation_' . (int) $contact->id . '_' .
            (int) $contact->requested_list);
        wp_die('Votre abonnement est confirmé. Merci !', 'Inscription', array('response' => 200));
    }

    public static function signup_form($atts)
    {
        $atts = shortcode_atts(array('liste' => 0), $atts, 'dse_newsletter_inscription');
        $id = (int) $atts['liste'];
        if (!Dendrila_Simple_Edit_Modules::enabled('newsletters') || !self::transport_ready() ||
            !Dendrila_Simple_Edit_Suppressions::ready() || $id < 1 || !self::list_exists($id)) { return ''; }
        $action = esc_url(admin_url('admin-post.php'));
        $html = '<form method="post" action="' . $action . '">' .
            '<input type="hidden" name="action" value="dendrila_simple_edit_ml_subscribe">' .
            '<input type="hidden" name="list_id" value="' . $id . '">' .
            wp_nonce_field('dendrila_simple_edit_ml_subscribe_' . $id, '_wpnonce', true, false) .
            '<p><label>Votre adresse e-mail <input type="email" name="email" required autocomplete="email"></label></p>' .
            '<p style="display:none"><label>Site web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>' .
            '<p><label><input type="checkbox" name="consent" value="1" required> Je souhaite recevoir les newsletters et je peux me désinscrire à tout moment.</label></p>' .
            '<button type="submit">M’inscrire</button></form>';
        return $html;
    }

    public static function subscribe()
    {
        $list_id = self::id('list_id');
        if (!self::list_exists($list_id) || !wp_verify_nonce(self::input('_wpnonce'), 'dendrila_simple_edit_ml_subscribe_' . $list_id) ||
            self::input('website') !== '' || self::input('consent') !== '1' || !self::transport_ready()) {
            wp_die('Inscription indisponible. Réessayez plus tard.', 'Inscription', array('response' => 400));
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $key = 'dendrila_simple_edit_ml_signup_' . hash_hmac('sha256', $ip, wp_salt('nonce'));
        if (get_transient($key)) { wp_die('Veuillez réessayer dans quelques minutes.', 'Inscription', array('response' => 429)); }
        set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        $email = strtolower(sanitize_email(self::input('email')));
        if (is_email($email)) {
            $cid = self::upsert_contact($email, $list_id, false, '', true);
            if ($cid) {
                global $wpdb;
                $contact = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('contacts'), $cid));
                $member_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE contact_id=%d AND list_id=%d', self::table('members'), $cid, $list_id));
                if ($contact && $member_status === 'pending') {
                    $url = self::recipient_url($contact, 'confirm', $list_id);
                    wp_mail($email, 'Confirmez votre inscription à la newsletter',
                        'Pour confirmer votre inscription, ouvrez ce lien : ' . $url);
                }
            }
        }
        wp_die('Si votre inscription est possible, vous recevrez un e-mail de confirmation.', 'Inscription', array('response' => 200));
    }

    /** Paid checkout creates a pending opt-in; it never imports a consent as confirmed. */
    public static function request_checkout_confirmation($email, $list_id)
    {
        if (!Dendrila_Simple_Edit_Suppressions::ready() || !self::transport_ready() ||
            !$list_id || !self::list_exists($list_id)) { return false; }
        $email = strtolower(sanitize_email($email));
        if (!is_email($email)) { return false; }
        $cid = self::upsert_contact($email, $list_id, false, '', true);
        if (!$cid) { return false; }
        global $wpdb;
        $contact = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('contacts'), $cid));
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE contact_id=%d AND list_id=%d', self::table('members'), $cid, $list_id));
        if ($contact && $status === 'pending') {
            $link = self::recipient_url($contact, 'confirm', $list_id);
            return (bool) wp_mail($email, 'Confirmez votre abonnement à la newsletter',
                'Vous avez demandé à recevoir la newsletter lors de votre commande. Confirmez votre inscription : '.$link);
        }
        return $status === 'confirmed' && !Dendrila_Simple_Edit_Suppressions::is_blocked($email);
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
        $lid = self::id('list_id');
        self::require_lists('remove', $lid);
        global $wpdb;
        $cid = self::id('contact_id');
        if (!$cid || !$lid) { self::redirect('error'); }
        // Remove membership for this list only; don't silently affect other lists.
        $wpdb->update(self::table('members'), array('status' => 'unsubscribed'),
            array('contact_id' => $cid, 'list_id' => $lid));
        $remaining = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE contact_id=%d AND status=%s', self::table('members'), $cid, 'confirmed'));
        if ($remaining === 0) {
            $email = $wpdb->get_var($wpdb->prepare('SELECT email FROM %i WHERE id=%d', self::table('contacts'), $cid));
            if (!$email || !Dendrila_Simple_Edit_Suppressions::record($email, 'retrait manuel')) { self::redirect('error'); }
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
        // Use the dedicated footer row of the centered email table.
        $marker = '<!--DENDRILA_SIMPLE_EDIT_UNSUBSCRIBE_ROW-->';
        if (strpos($html, $marker) !== false) {
            $row = '<tr><td align="center" valign="top" style="padding:18px 3px 2px;' .
                'border-top:1px solid #e4ece5;text-align:center;font-size:13px;' .
                'line-height:1.5;color:#526457;">' . $footer . '</td></tr>';
            return str_replace($marker, $row, $html);
        }
        $end = stripos($html, '</body>');
        if ($end === false) { $end = stripos($html, '</html>'); }
        if ($end === false) { return $html . $footer; }
        return substr_replace($html, $footer, $end, 0);
    }

    public static function dispatch($campaign_id)
    {
        global $wpdb;
        $campaign_id = (int) $campaign_id;
        if (!$campaign_id || !self::transport_ready() || !Dendrila_Simple_Edit_Suppressions::ready()) { return; }
        $ct = self::table('campaigns');
        $qt = self::table('queue');
        $people = self::table('contacts');
        $campaign = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $ct, $campaign_id));
        if (!$campaign || !in_array($campaign->status, array('scheduled', 'sending'), true)) { return; }
        if ($campaign->status === 'scheduled' && strtotime($campaign->send_at . ' UTC') > time()) { return; }
        // Atomic lock protects against concurrent WP Cron requests on busy sites.
        $claimed = $wpdb->query($wpdb->prepare("UPDATE %i SET locked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND), status='sending' WHERE id=%d AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP()) AND status IN ('scheduled','sending')", $ct, $campaign_id));
        if ($claimed !== 1) { return; }
        try {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT q.id,q.contact_id,c.email,c.status FROM %i q JOIN %i c ON c.id=q.contact_id WHERE q.campaign_id=%d AND q.status='queued' ORDER BY q.id ASC LIMIT %d", $qt, $people, $campaign_id, self::BATCH_SIZE));
            // Uncertain messages are never resent automatically after a worker crash.
            // A crash may happen after SMTP acceptance and before queue acknowledgement.
            $wpdb->query($wpdb->prepare("UPDATE %i SET status='uncertain',error_message='Vérification manuelle requise après interruption' " .
                "WHERE campaign_id=%d AND status='processing' AND " .
                "processing_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)", $qt, $campaign_id));
            foreach ((array) $rows as $row) {
                $wpdb->query($wpdb->prepare("UPDATE %i SET status='processing', processing_started_at=UTC_TIMESTAMP() WHERE id=%d AND status='queued'", $qt, (int) $row->id));
                if ($wpdb->rows_affected !== 1) { continue; }
                $still_sending = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id=%d', $ct, $campaign_id));
                if ($still_sending !== 'sending') {
                    $wpdb->update($qt, array('status' => 'queued'), array('id' => (int) $row->id));
                    break;
                }
                $eligible = (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE list_id=%d AND contact_id=%d AND status=%s',
                    self::table('members'), (int) $campaign->list_id, (int) $row->contact_id, 'confirmed'));
                if ($row->status !== 'confirmed' || !$eligible ||
                    Dendrila_Simple_Edit_Suppressions::is_blocked($row->email)) {
                    $wpdb->update($qt, array('status' => 'skipped'), array('id' => (int) $row->id));
                    continue;
                }
                $contact = (object) array('id' => $row->contact_id, 'email' => $row->email);
                $url = self::recipient_url($contact, 'unsubscribe');
                $footer = '<a href="' . esc_url($url) . '"' .
                    ' style="color:#45634d;font:400 13px/1.5 Arial,Helvetica,sans-serif;text-decoration:underline;">' .
                    'Ne plus recevoir les newsletters de ce site</a>';
                // Convert older prepared campaigns from their immutable content snapshot.
                // Never fetch the current source article or newsletter at send time.
                $prepared_html = Dendrila_Simple_Edit_Block_Editor::modernize_email_document($campaign->html_body);
                $html = self::insert_required_footer($prepared_html, $footer);
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
            $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE campaign_id=%d AND status='queued'", $qt, $campaign_id));
            $current_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id=%d', $ct, $campaign_id));
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
        $admin = current_user_can('manage_options');
        $sender = Dendrila_Simple_Edit_Newsletters::can_send();
        $manager = self::can_manage_lists();
        if ($section === 'connexion' && !$admin) { return; }
        if (!$admin && !(($sender && in_array($section,array('envois','suivi'),true)) ||
            ($manager && $section === 'listes'))) { return; }
        if (!in_array($section, array('listes', 'envois', 'suivi', 'connexion'), true)) { return; }
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            if ($admin) { self::install(); } else { return; }
        }
        global $wpdb;
        $lists = self::lists();
        $notices = array(
            'list_created' => 'Liste créée.', 'list_assigned' => 'Liste attribuée à l’utilisateur sélectionné.',
            'bad_list' => 'Nom de liste incorrect.',
            'contacts_added' => 'Contacts ajoutés avec leur preuve de consentement.',
            'consent_required' => 'Indiquez la provenance du consentement et confirmez-le.',
            'too_many' => '200 contacts au maximum par import.',
            'approval_required' => 'La newsletter doit d’abord être validée.',
            'empty_list' => 'Aucun abonné confirmé dans cette liste.',
            'prepared' => 'Campagne préparée, aucun e-mail envoyé.',
            'started' => 'Campagne programmée. WordPress traitera les e-mails à l’heure prévue.',
            'paused' => 'Campagne suspendue.',
            'smtp_missing' => 'Activez FluentSMTP et configurez une connexion avant tout envoi.',
            'confirmation_required' => 'Confirmez explicitement le lancement.',
            'invalid_date' => 'Choisissez une date future valide.',
            'test_accepted' => 'Test accepté par WordPress/FluentSMTP. Vérifiez sa réception.',
            'test_failed' => 'Test refusé : consultez le journal FluentSMTP.',
            'contact_removed' => 'Contact désinscrit de la liste sélectionnée.',
            'contact_updated' => 'Informations du contact enregistrées.',
            'edit_email_confirmation' => 'Nouvelle adresse enregistrée en attente de sa confirmation. L’ancienne adresse a été désinscrite de cette liste.',
            'duplicate_email' => 'Cette adresse existe déjà. Vérifiez le contact avant de modifier son inscription.',
            'blocked_unsubscribed' => 'Import protégé : les personnes précédemment désabonnées ont été ignorées. Aucun réabonnement automatique.',
            'invalid_contact' => 'Contact introuvable ou modification impossible.',
            'invite_sent' => 'Invitation envoyée pour nouvelle confirmation, sans réabonnement automatique.',
            'invite_failed' => 'L’invitation n’a pas été acceptée par le transport SMTP. Aucun réabonnement.',
            'invite_no_permission' => 'Une permission explicite et sa provenance sont nécessaires avant toute invitation.',
            'invite_rate_limited' => 'Une invitation a déjà été envoyée récemment à cette personne (limite : 30 jours).',
            'invite_not_allowed' => 'Cette personne n’est pas désabonnée de la liste.',
            'smtp_missing' => 'Le transport SMTP doit être configuré avant d’envoyer une invitation.',
            'test_failed' => 'Le transport n’a pas accepté le message. Vérifiez FluentSMTP.',
            'not_ready' => 'Cette campagne n’est pas prête.',
            'cron_failed' => 'La programmation a échoué. Aucun envoi démarré.',
            'error' => 'Opération non enregistrée.',
        );
        $notice = isset($_GET['dendrila_simple_edit_ml_notice']) ? sanitize_key(wp_unslash($_GET['dendrila_simple_edit_ml_notice'])) : '';
        if (isset($notices[$notice])) { echo '<p class="notice notice-info" role="status">' . esc_html($notices[$notice]) . '</p>'; }
        if ($section === 'connexion') {
        echo '<section class="dendrila-simple-edit-card"><h2>Connexion pour envoyer les e-mails</h2><p>' . esc_html__('FluentSMTP assure l’envoi des messages depuis WordPress. Vérifiez sa connexion avant de lancer une newsletter.', 'dendrila-simple-edit') . '</p>';
        echo '<p><strong>Transport :</strong> ' . (self::transport_ready() ?
            'FluentSMTP détecté. Vérifiez sa connexion avant de lancer une campagne.' :
            'FluentSMTP n’a pas encore de connexion prête. Les campagnes ne peuvent pas démarrer.') . '</p>';
        if (self::transport_ready()) { echo '<p><a href="' . esc_url(admin_url('options-general.php?page=fluent-mail')) . '">Ouvrir FluentSMTP</a></p>'; }
        if (self::transport_ready()) { self::form('test', 'Envoyer un e-mail de test à mon adresse', array()); }
        echo '</section>';
        }
        if ($section === 'listes') {
            echo '<section class="dendrila-simple-edit-card"><div class="dendrila-simple-edit-list-heading">';
            echo '<h2>Mes listes</h2><a href="#dendrila-simple-edit-add-list" class="button dendrila-simple-edit-list-add-shortcut" ';
            echo 'aria-label="Ajouter une liste" title="Ajouter une liste" data-dendrila-simple-edit-list-open>+</a></div>';
            if (!$lists) { echo '<p>Aucune liste pour le moment. Utilisez « Ajouter une liste » ci-dessous.</p>'; }
            foreach ($lists as $list) {
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM %i m JOIN %i c ON c.id=m.contact_id WHERE m.list_id=%d AND c.status=%s AND m.status=%s',
                    self::table('members'), self::table('contacts'), $list->id, 'confirmed', 'confirmed'));
                echo '<details class="dendrila-simple-edit-subcard dendrila-simple-edit-list-card"><summary><strong>' .
                    esc_html($list->name) . '</strong> · ' . esc_html((string)$count) .
                    ($count === 1 ? ' abonné confirmé' : ' abonnés confirmés') . '</summary>';
                if ($admin) {
                    echo '<details class="dendrila-simple-edit-list-assignment"><summary>' . esc_html__('Attribuer cette liste à un éditeur', 'dendrila-simple-edit') . '</summary>';
                    $users = get_users(array('role'=>Dendrila_Simple_Edit_Plugin::ROLE,'number'=>100));
                    $options = '<option value="0"' . selected((int)$list->owner_user_id,0,false) .
                        '>Administration (non attribuée)</option>';
                    foreach ($users as $user) {
                        $options .= '<option value="' . (int)$user->ID . '"' .
                            selected((int)$list->owner_user_id,(int)$user->ID,false) . '>' .
                            esc_html($user->display_name) . '</option>';
                    }
                    self::form('assign_list', 'Enregistrer l’attribution', array(
                        '<input type="hidden" name="list_id" value="' . (int)$list->id . '">',
                        '<label>Utilisateur responsable <select name="owner_user_id">' .
                            $options . '</select></label>'
                    ));
                    echo '</details>';
                }
                echo '<details class="dendrila-simple-edit-list-shortcode"><summary>Formulaire d’inscription</summary><p>Code à placer sur une page</p><code id="dendrila-simple-edit-list-code-' . (int)$list->id . '">[dse_newsletter_inscription liste="' .
                    (int)$list->id . '"]</code> <button class="button dendrila-simple-edit-copy" type="button" data-dendrila-simple-edit-copy="dendrila-simple-edit-list-code-' . (int)$list->id . '">Copier le code</button></details>';
                $members = $wpdb->get_results($wpdb->prepare(
                    'SELECT c.id,c.email,c.display_name,c.status AS contact_status,m.status AS membership_status FROM %i m JOIN %i c ON c.id=m.contact_id WHERE m.list_id=%d ORDER BY c.email ASC LIMIT 40',
                    self::table('members'), self::table('contacts'), (int)$list->id));
                if (!$members) { echo '<p>Aucun contact dans cette liste.</p>'; }
                foreach ((array)$members as $member) {
                    $unsubscribed = $member->contact_status === 'unsubscribed' ||
                        $member->membership_status === 'unsubscribed';
                    $label = $unsubscribed ? 'Désabonné·e' :
                        ($member->membership_status === 'confirmed' ? 'Abonné·e' : 'Confirmation en attente');
                    echo '<div class="dendrila-simple-edit-mailing-contact dendrila-simple-edit-mailing-contact-card">';
                    echo '<div class="dendrila-simple-edit-contact-identity"><strong>' . esc_html($member->display_name ?: $member->email) . '</strong>';
                    if ($member->display_name) {
                        echo '<div class="dendrila-simple-edit-contact-email">' . esc_html($member->email) . '</div>';
                    }
                    echo '<span class="dendrila-simple-edit-contact-status' . ($unsubscribed ? ' is-unsubscribed' : '') .
                        '">' . esc_html($label) . '</span></div>';
                    echo '<details class="dendrila-simple-edit-contact-edit"><summary>Modifier</summary>';
                    self::form('edit_contact', 'Enregistrer le contact', array(
                        '<input type="hidden" name="contact_id" value="' . (int)$member->id . '">',
                        '<input type="hidden" name="list_id" value="' . (int)$list->id . '">',
                        '<label>Nom (facultatif) <input type="text" name="display_name" maxlength="160" value="' .
                            esc_attr($member->display_name) . '"></label>',
                        '<label>Adresse e-mail <input type="email" name="email" maxlength="254" required value="' .
                            esc_attr($member->email) . '"' .
                            ($unsubscribed ? ' readonly' : '') . '></label>',
                        '<p class="description">Changer l’adresse ne transfère pas l’ancien consentement : '
                            . 'la nouvelle adresse devra confirmer son inscription.</p>',
                        $unsubscribed ? '' :
                            '<details class="dendrila-simple-edit-contact-change-address"><summary>Pour changer d’adresse e-mail</summary>' .
                            '<label>Origine de la demande <input type="text" name="email_permission_source" maxlength="255" ' .
                            'placeholder="Ex. demande reçue directement de la personne"></label>' .
                            '<label class="dendrila-simple-edit-check"><input type="checkbox" name="email_permission" value="1"> ' .
                            'Cette personne a demandé explicitement la vérification de sa nouvelle adresse.</label>' .
                            '</details>'
                    ));
                    echo '</details>';
                    if ($unsubscribed) {
                        echo '<details class="dendrila-simple-edit-contact-invite"><summary>' . esc_html__('Proposer un réabonnement', 'dendrila-simple-edit') . '</summary>';
                        echo '<p class="description">Envoyez une invitation uniquement si cette personne vous l’a demandé. ' .
                            '' .
                            'Elle pourra confirmer son réabonnement par e-mail.</p>';
                        self::form('reinvite', 'Envoyer l’invitation', array(
                            '<input type="hidden" name="contact_id" value="' . (int)$member->id . '">',
                            '<input type="hidden" name="list_id" value="' . (int)$list->id . '">',
                            '<label>Comment cette personne a-t-elle accepté de recevoir cette invitation ? ' .
                                '<input name="permission_source" type="text" minlength="12" maxlength="255" required ' .
                                'placeholder="Ex. demande par téléphone"></label>',
                            '<label class="dendrila-simple-edit-check"><input type="checkbox" name="permission_confirmed" value="1" required> ' .
                                'Je confirme disposer de son accord explicite pour lui envoyer cette seule invitation.</label>'
                        ));
                        echo '</details>';
                    } else {
                        self::form('remove', 'Désinscrire de cette liste', array(
                            '<input type="hidden" name="contact_id" value="' . (int)$member->id . '">',
                            '<input type="hidden" name="list_id" value="' . (int)$list->id . '">'
                        ));
                    }
                    echo '</div>';
                }
                echo '<p class="description">Affichage des 40 premiers contacts, sans envoi automatique.</p>';
                echo '<details class="dendrila-simple-edit-list-import"><summary>Ajouter des contacts à cette liste</summary>';
                self::form('import', 'Ajouter ces contacts', array(
                    '<input type="hidden" name="list_id" value="' . (int)$list->id . '">',
                    '<label>Adresses e-mail (une par ligne, maximum 200) ' .
                        '<textarea name="emails" rows="5" required></textarea></label>',
                    '<label>Origine du consentement <input name="origin" type="text" minlength="8" maxlength="255" required ' .
                        'placeholder="Ex. inscription validée le 10/11/2026"></label>',
                    '<label class="dendrila-simple-edit-check"><input type="checkbox" name="consent_confirmed" value="1" required> ' .
                        'Je confirme disposer d’un accord valable pour chaque personne.</label>'
                ));
                echo '</details></details>';
            }
            echo '</section>';
            echo '<details class="dendrila-simple-edit-card dendrila-simple-edit-create-list" id="dendrila-simple-edit-add-list">';
            echo '<summary>Ajouter une liste</summary>';
            echo '<p class="description">' . esc_html__('Donnez un nom à la liste. Vous pourrez ensuite y ajouter des contacts.', 'dendrila-simple-edit') . '</p>';
            self::form('list', 'Créer la nouvelle liste', array(
                '<label>Nom de la nouvelle liste <input name="name" type="text" required maxlength="160" ' .
                    'data-dendrila-simple-edit-new-list-name></label>'
            ));
            echo '</details>';
        }
        if ($section === 'envois') {
        $query = array('post_type' => Dendrila_Simple_Edit_Newsletters::TYPE,
            'post_status' => array('draft','pending'), 'posts_per_page' => 80,
            'orderby' => 'modified', 'order' => 'DESC');
        if (!$admin) { $query['author'] = get_current_user_id(); }
        $approved = array_filter(get_posts($query), array(__CLASS__, 'can_prepare_newsletter'));
        echo '<section class="dendrila-simple-edit-card"><h2>' . esc_html__('Préparer une campagne', 'dendrila-simple-edit') . '</h2>';
        if (!$approved || !$lists) {
            echo '<p>Il faut au moins une newsletter que vous êtes autorisé à envoyer et une liste existante.</p>';
        }
        else {
            $selected = isset($_GET['dendrila_simple_edit_send_nid']) ? absint($_GET['dendrila_simple_edit_send_nid']) : 0;
            $choices = '<option value="">Choisir une newsletter</option>';
            foreach ($approved as $post) {
                $choices .= '<option value="' . (int) $post->ID . '"' .
                    selected($selected, (int)$post->ID, false) . '>' .
                    esc_html($post->post_title) . '</option>';
            }
            self::form('prepare', 'Préparer sans envoyer', array(
                '<label>Newsletter <select name="newsletter_id" required>' . $choices . '</select></label>',
                '<label>Destinataires <select name="list_id" required>' . self::options($lists) . '</select></label>'
            ));
        }
        echo '</section>';
        }
        if ($section === 'suivi') {
        $campaigns = $wpdb->get_results($wpdb->prepare('SELECT c.*, l.name AS list_name FROM %i c LEFT JOIN %i l ON l.id=c.list_id ORDER BY c.id DESC LIMIT 100',
            self::table('campaigns'), self::table('lists')));
        if (!$admin) { $campaigns = array_values(array_filter((array)$campaigns,
            array(__CLASS__, 'can_manage_campaign'))); }
        $campaigns = array_slice((array)$campaigns, 0, 15);
        echo '<section class="dendrila-simple-edit-card"><h2>' . esc_html__('Campagnes préparées et envoyées', 'dendrila-simple-edit') . '</h2>';
        foreach ((array) $campaigns as $c) {
            $counts = $wpdb->get_results($wpdb->prepare('SELECT status,COUNT(*) as total FROM %i WHERE campaign_id=%d GROUP BY status', self::table('queue'), $c->id));
            $queue_labels = array(
                'queued' => 'en attente',
                'processing' => 'en cours de traitement',
                'accepted' => 'acceptés par le serveur de messagerie',
                'failed' => 'en échec',
                'skipped' => 'ignorés',
                'uncertain' => 'à vérifier après une interruption',
            );
            $campaign_labels = array(
                'prepared' => 'prête à être lancée',
                'paused' => 'suspendue',
                'sending' => 'en cours',
                'scheduled' => 'programmée',
                'completed' => 'terminée',
            );
            $stats = array();
            foreach ($counts as $row) {
                $label = isset($queue_labels[$row->status]) ? $queue_labels[$row->status] : 'autre état';
                $stats[] = $label . ' : ' . (int) $row->total;
            }
            $campaign_status = isset($campaign_labels[$c->status]) ?
                $campaign_labels[$c->status] : 'état inconnu';
            echo '<div class="dendrila-simple-edit-subcard"><h3>' . esc_html($c->subject) . '</h3>';
            echo '<p>Liste : <strong>' . esc_html($c->list_name ?: 'Liste supprimée') . '</strong></p>';
            echo '<p>État : <strong>' . esc_html($campaign_status) . '</strong>' .
                ($stats ? ' · ' . esc_html(implode(' · ', $stats)) : '') . '</p>';
            if (in_array($c->status, array('prepared','paused'), true)) {
                echo '<p>' . esc_html__('Un clic sur « Lancer » n’envoie rien immédiatement : les messages seront traités par petits lots planifiés.', 'dendrila-simple-edit') . '</p>';
                self::form('start', 'Lancer ou programmer les envois', array(
                    '<input type="hidden" name="campaign_id" value="' . (int) $c->id . '">',
                    '<label>Envoyer à partir du (facultatif) <input type="datetime-local" name="send_at"></label>',
                    '<label class="dendrila-simple-edit-check"><input type="checkbox" name="confirm_send" value="1" required> Je confirme vouloir envoyer cette campagne aux abonnés de la liste.</label>'
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
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dendrila-simple-edit-mailing-form">';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_ml_' . esc_attr($action) . '">';
        wp_nonce_field('dendrila_simple_edit_ml_' . $action);
        foreach ($fields as $field) {
            // Only allow form controls and their expected attributes in generated fragments.
            echo wp_kses($field, array(
                'input' => array('type'=>true,'name'=>true,'value'=>true,'id'=>true,'class'=>true,'required'=>true,'checked'=>true,'readonly'=>true,'disabled'=>true,'min'=>true,'max'=>true,'maxlength'=>true,'placeholder'=>true,'autocomplete'=>true,'step'=>true),
                'textarea' => array('name'=>true,'id'=>true,'rows'=>true,'cols'=>true,'maxlength'=>true,'placeholder'=>true),
                'select' => array('name'=>true,'id'=>true,'required'=>true,'multiple'=>true),
                'option' => array('value'=>true,'selected'=>true,'disabled'=>true),
                'label' => array('for'=>true,'class'=>true),
                'p' => array('class'=>true), 'small'=>array('class'=>true),
                'details'=>array('class'=>true,'open'=>true), 'summary'=>array(),
                'strong'=>array(), 'span'=>array('class'=>true), 'div'=>array('class'=>true),
                'a'=>array('href'=>true,'target'=>true,'rel'=>true),
                'br'=>array(),
            ));
        }
        echo '<p><button type="submit" class="button button-primary">' . esc_html($label) . '</button></p></form>';
    }
}
