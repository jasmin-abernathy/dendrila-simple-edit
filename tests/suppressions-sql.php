<?php
/** Request-local SQL probe and uncached consent contracts; no WordPress DB. */
define('ABSPATH', __DIR__);
function sanitize_email($email) { return strtolower(trim((string) $email)); }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function get_option($name, $default = false) { return $default; }
function current_time($type, $gmt = false) { return '2026-11-26 00:00:00'; }
function sql_assert($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
class DSE_Suppression_SQL_Fixture {
    public $prefix = 'wp_';
    public $last_error = '';
    public $table_exists = false;
    public $consent_status = null;
    public $table_probes = 0;
    public $consent_reads = 0;
    public function esc_like($value) { return $value; }
    public function prepare($sql, ...$values) { return array('sql' => $sql, 'values' => $values); }
    public function get_var($query) {
        if (strpos($query['sql'], 'SHOW TABLES LIKE') === 0) {
            $this->table_probes++;
            return $this->table_exists ? $query['values'][0] : null;
        }
        if (strpos($query['sql'], 'SELECT status FROM') === 0) {
            $this->consent_reads++;
            $this->last_error = $this->table_exists ? '' : 'table missing';
            return $this->table_exists ? $this->consent_status : null;
        }
        throw new RuntimeException('Unexpected SQL');
    }
}
$wpdb = new DSE_Suppression_SQL_Fixture();
require dirname(__DIR__) . '/includes/class-dendrila-simple-edit-suppressions.php';
$email = 'example@example.test';
sql_assert(Dendrila_Simple_Edit_Suppressions::is_blocked($email), 'Missing table must block');
sql_assert($wpdb->table_probes === 1, 'Missing table is probed');
$wpdb->table_exists = true;
$wpdb->consent_status = 'suppressed';
sql_assert(Dendrila_Simple_Edit_Suppressions::is_blocked($email), 'A newly created table is detected');
sql_assert($wpdb->table_probes === 2, 'Only positive existence is memoized');
$wpdb->consent_status = 'reconsented';
sql_assert(!Dendrila_Simple_Edit_Suppressions::is_blocked($email), 'Fresh consent is read each time');
sql_assert($wpdb->table_probes === 2 && $wpdb->consent_reads === 2, 'No repeat table probe, no consent cache');
$wpdb->table_exists = false;
sql_assert(Dendrila_Simple_Edit_Suppressions::is_blocked($email), 'Lost table fails closed even after memoization');
sql_assert($wpdb->consent_reads === 3, 'Consent is queried again after table loss');
echo "OK: suppression table memoization never caches consent; fail-closed preserved\n";
