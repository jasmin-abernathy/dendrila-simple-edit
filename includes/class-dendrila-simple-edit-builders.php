<?php
/**
 * Read-only builder diagnostics. No mutation of Elementor JSON, Divi layouts,
 * Divi 5 block attributes, or any builder-specific postmeta.
 */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Builders
{
    /**
     * Elementor's dedicated Shortcode widget uses settings.shortcode.
     * Never count arbitrary text in _elementor_data as a confirmed rendering.
     */
    public static function elementor_shortcode_locations($field)
    {
        if (!is_string($field) || !in_array($field, Dendrila_Simple_Edit_Plugin::enabled_fields(), true)) {
            return array();
        }
        static $request_cache = array();
        if (array_key_exists($field, $request_cache)) { return $request_cache[$field]; }
        global $wpdb;
        $results = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared, bounded search of Elementor JSON; no public API can query this structured value.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value
             FROM %i pm
             INNER JOIN %i p ON pm.post_id = p.ID
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('page', 'post')
               AND pm.meta_key = '_elementor_data'
               AND pm.meta_value LIKE %s
             ORDER BY p.post_title ASC LIMIT 50",
            $wpdb->postmeta, $wpdb->posts, '%' . $wpdb->esc_like('dse_text') . '%'
        ));
        if (!is_array($rows)) { $request_cache[$field] = array(); return array(); }
        foreach ($rows as $row) {
            if (!isset($row->ID, $row->meta_value) || !is_string($row->meta_value) ||
                strlen($row->meta_value) > 3000000) {
                continue;
            }
            $nodes = json_decode($row->meta_value, true);
            if (!is_array($nodes)) { continue; }
            if (self::contains_shortcode_widget($nodes, $field)) {
                $results[] = array('id' => (int) $row->ID,
                    'title' => isset($row->post_title) ? (string) $row->post_title : '');
            }
        }
        $request_cache[$field] = $results;
        return $results;
    }

    public static function contains_shortcode_widget($nodes, $field)
    {
        if (!is_array($nodes) || !is_string($field) ||
            preg_match('/^[a-z0-9_]{1,40}$/', $field) !== 1) { return false; }
        $stack = array(array($nodes, 0));
        $visited = 0;
        while ($stack && $visited < 800) {
            list($current, $depth) = array_pop($stack);
            if (!is_array($current) || $depth > 20) { continue; }
            $visited++;
            if (isset($current['widgetType']) && $current['widgetType'] === 'shortcode' &&
                is_array($current['settings'] ?? null) &&
                is_string($current['settings']['shortcode'] ?? null) &&
                self::exact_shortcode($current['settings']['shortcode'], $field)) {
                return true;
            }
            if (isset($current['elements']) && is_array($current['elements'])) {
                $stack[] = array($current['elements'], $depth + 1);
            }
            // Elementor top-level payloads and descendants are indexed arrays.
            if (array_values($current) === $current) {
                foreach ($current as $child) {
                    if (is_array($child)) { $stack[] = array($child, $depth + 1); }
                }
            }
        }
        return false;
    }

    public static function exact_shortcode($text, $field)
    {
        if (!is_string($text) || !is_string($field)) { return false; }
        // A single, standalone canonical shortcode. No arbitrary PHP/HTML.
        return trim($text) === '[dse_text field="' . $field . '"]';
    }

    public static function render()
    {
        if (!Dendrila_Simple_Edit_Plugin::can_access()) { return; }
        echo '<section class="dendrila-simple-edit-card"><h2>Constructeurs de pages</h2>';
        echo '<p>Les textes sont reliés par shortcode. Le plugin ne réécrit pas la structure des constructeurs.</p>';
        echo '<p><strong>Elementor :</strong> placez le code dans un widget Shortcode dédié. Il apparaîtra dans les emplacements repérés ci-dessous.</p>';
        echo '<p><strong>Divi 4 et Divi 5 :</strong> utilisez un module acceptant les shortcodes et vérifiez son rendu en aperçu public. Ne remplacez pas les sections ou les attributs du constructeur.</p>';
        foreach (Dendrila_Simple_Edit_Plugin::enabled_fields() as $field) {
            $locations = self::elementor_shortcode_locations($field);
            if (!$locations) { continue; }
            $definitions = Dendrila_Simple_Edit_Plugin::definitions();
            echo '<h3>' . esc_html($definitions[$field]['label']) . '</h3>';
            echo '<p>Widgets Elementor Shortcode détectés dans ces pages publiées (rendu public à confirmer) :</p><ul>';
            foreach ($locations as $location) {
                $permalink = get_permalink($location['id']);
                if ($permalink) {
                    echo '<li><a href="' . esc_url($permalink) . '" target="_blank" rel="noopener noreferrer">' .
                        esc_html($location['title'] ? $location['title'] : '(Sans titre)') . '</a></li>';
                }
            }
            echo '</ul>';
        }
        echo '<p class="description">Le repérage Elementor est limité aux pages/articles publics et aux widgets Shortcode canoniques. Les modèles de thème et les autres widgets restent à examiner séparément.</p>';
        echo '</section>';
    }
}
