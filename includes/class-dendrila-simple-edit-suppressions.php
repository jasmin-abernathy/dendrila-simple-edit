<?php
/** Permanent, database-backed email suppression with recovery from legacy tables. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Suppressions
{
    const OPTION = 'dendrila_simple_edit_ml_suppression_migrated_v1';

    // Cache only successful table-existence probes for this PHP request.
    // Suppression status and consent are NEVER cached.
    private static $existing_tables = array();

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'dendrila_simple_edit_ml_suppressions';
    }

    public static function hash_email($email)
    {
        return hash('sha256', strtolower(trim(sanitize_email($email))));
    }

    private static function exists($name)
    {
        global $wpdb;
        if (isset(self::$existing_tables[$name])) { return true; }
        // Only a positive probe is memoized. A missing table may be created
        // later by dbDelta() in the same request and must be checked again.
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',
            $wpdb->esc_like($name))) === $name;
        if ($exists) { self::$existing_tables[$name] = true; }
        return $exists;
    }

    public static function ready()
    {
        return get_option(self::OPTION, '') === '1';
    }

    /** Fails closed until all historical opt-outs have been copied. */
    public static function migrate()
    {
        global $wpdb;
        if (self::ready()) { return true; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $name = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $name (
            email_hash char(64) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'suppressed',
            reason varchar(100) NOT NULL DEFAULT '',
            first_seen_at datetime NOT NULL,
            last_consent_at datetime DEFAULT NULL,
            PRIMARY KEY  (email_hash),
            KEY status (status)
        ) $charset;");
        if (!self::exists($name)) { return false; }
        $sources = array(
            array($wpdb->prefix . 'dse_ml_contacts', $wpdb->prefix . 'dse_ml_members'),
            array($wpdb->prefix . 'dendrila_simple_edit_ml_contacts', $wpdb->prefix . 'dendrila_simple_edit_ml_members'),
        );
        foreach ($sources as $pair) {
            list($contacts, $members) = $pair;
            if (!self::exists($contacts)) { continue; }
            // Preserve opt-outs even if a new installation has reimported the same address.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT email FROM %i WHERE status='unsubscribed'", $contacts));
            if ($rows === null) { return false; }
            foreach ($rows as $row) {
                if (!self::record($row->email, 'migration')) { return false; }
            }
            if (!self::exists($members)) { continue; }
            $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT c.email FROM %i c INNER JOIN %i m ON m.contact_id=c.id WHERE m.status='unsubscribed'", $contacts, $members));
            if ($rows === null) { return false; }
            foreach ($rows as $row) {
                if (!self::record($row->email, 'migration-liste')) { return false; }
            }
        }
        // Restore legacy mailing lists and contacts, but never overwrite a newer consent
        // or resubscribe an address. Old campaign evidence remains in the old tables.
        if (!self::recover_legacy_contacts()) { return false; }
        // An earlier upgrade may have reactivated somebody. Enforce persisted opt-outs now.
        $contacts = $wpdb->prefix . 'dendrila_simple_edit_ml_contacts';
        $members = $wpdb->prefix . 'dendrila_simple_edit_ml_members';
        if (self::exists($contacts)) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,email FROM %i', $contacts));
            if ($rows === null) { return false; }
            foreach ($rows as $row) {
                if (!self::is_blocked($row->email)) { continue; }
                if ($wpdb->update($contacts, array('status'=>'unsubscribed'), array('id'=>(int)$row->id)) === false) { return false; }
                if (self::exists($members) && $wpdb->update($members,
                    array('status'=>'unsubscribed'), array('contact_id'=>(int)$row->id)) === false) { return false; }
            }
        }
        update_option(self::OPTION, '1', false);
        return self::ready();
    }

    /** Non-destructive migration of the old dse_ml_* lists and memberships. */
    private static function recover_legacy_contacts()
    {
        global $wpdb;
        $old_lists = $wpdb->prefix . 'dse_ml_lists';
        $old_contacts = $wpdb->prefix . 'dse_ml_contacts';
        $old_members = $wpdb->prefix . 'dse_ml_members';
        $new_lists = $wpdb->prefix . 'dendrila_simple_edit_ml_lists';
        $new_contacts = $wpdb->prefix . 'dendrila_simple_edit_ml_contacts';
        $new_members = $wpdb->prefix . 'dendrila_simple_edit_ml_members';
        if (!self::exists($old_lists) || !self::exists($old_contacts) || !self::exists($old_members)) { return true; }
        if (!self::exists($new_lists) || !self::exists($new_contacts) || !self::exists($new_members)) { return false; }
        $list_map = array();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,name,owner_user_id,created_at FROM %i', $old_lists));
        if ($rows === null) { return false; }
        foreach ($rows as $list) {
            $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE name=%s AND owner_user_id=%d LIMIT 1',
                $new_lists, $list->name, (int)$list->owner_user_id));
            if (!$id) {
                $ok = $wpdb->insert($new_lists, array('name'=>$list->name,
                    'owner_user_id'=>(int)$list->owner_user_id, 'created_at'=>$list->created_at));
                if (!$ok) { return false; }
                $id = (int)$wpdb->insert_id;
            }
            $list_map[(int)$list->id] = $id;
        }
        $contact_map = array();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $old_contacts));
        if ($rows === null) { return false; }
        foreach ($rows as $contact) {
            $email = strtolower(sanitize_email($contact->email));
            if (!is_email($email)) { continue; }
            $id = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE email=%s', $new_contacts, $email));
            if (!$id) {
                $blocked = self::is_blocked($email);
                $ok = $wpdb->insert($new_contacts,array('email'=>$email,
                    'display_name'=>(string)$contact->display_name,
                    'status'=>$blocked?'unsubscribed':($contact->status==='confirmed'?'confirmed':'pending'),
                    'consent_source'=>$blocked?'':(string)$contact->consent_source,
                    'consent_at'=>$blocked?null:$contact->consent_at,
                    'created_at'=>$contact->created_at));
                if (!$ok) { return false; }
                $id = (int)$wpdb->insert_id;
            }
            $contact_map[(int)$contact->id] = $id;
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT list_id,contact_id,status,consent_source,consent_at FROM %i', $old_members));
        if ($rows === null) { return false; }
        foreach ($rows as $old) {
            $lid = $list_map[(int)$old->list_id] ?? 0;
            $cid = $contact_map[(int)$old->contact_id] ?? 0;
            if (!$lid || !$cid) { continue; }
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE list_id=%d AND contact_id=%d',$new_members,$lid,$cid));
            if ($exists) { continue; }
            $email = $wpdb->get_var($wpdb->prepare('SELECT email FROM %i WHERE id=%d',$new_contacts,$cid));
            $blocked = !$email || self::is_blocked($email);
            $status = $blocked?'unsubscribed':($old->status==='confirmed'?'confirmed':'pending');
            if (!$wpdb->insert($new_members,array('list_id'=>$lid,'contact_id'=>$cid,'status'=>$status,
                'consent_source'=>$blocked?'':(string)$old->consent_source,
                'consent_at'=>$blocked?null:$old->consent_at))) { return false; }
        }
        return true;
    }

    /** Insert only if absent, never erase reconsent history accidentally. */
    public static function record($email, $reason = 'désinscription')
    {
        global $wpdb;
        if (!is_email($email) || !self::exists(self::table())) { return false; }
        $hash = self::hash_email($email);
        // A second unsubscribe must suppress again even after voluntary re-consent.
        // Keep the first opt-out date and the last consent date for auditability.
        return $wpdb->query($wpdb->prepare(
            "INSERT INTO %i (email_hash,status,reason,first_seen_at) VALUES (%s,%s,%s,%s) ON DUPLICATE KEY UPDATE status='suppressed', reason=VALUES(reason)",
            self::table(), $hash, 'suppressed', sanitize_text_field($reason), current_time('mysql', true)
        )) !== false;
    }

    public static function is_blocked($email)
    {
        global $wpdb;
        // If the protection table is missing or SQL fails, treat addresses as
        // suppressed rather than accidentally sending marketing mail.
        if (!self::exists(self::table())) { return true; }
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE email_hash=%s', self::table(), self::hash_email($email)));
        if (!empty($wpdb->last_error)) { return true; }
        return $status === 'suppressed';
    }

    /** Only call after a fresh, confirmed opt-in. The previous opt-out remains documented. */
    public static function reconsent($email)
    {
        global $wpdb;
        if (!self::ready()) { return false; }
        return $wpdb->update(self::table(), array('status'=>'reconsented',
            'last_consent_at'=>current_time('mysql', true)),
            array('email_hash'=>self::hash_email($email))) !== false;
    }
}
