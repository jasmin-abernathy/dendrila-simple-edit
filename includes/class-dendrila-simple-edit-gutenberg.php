<?php
/** Safely edit leaf Gutenberg text without round-tripping the surrounding layout. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Gutenberg
{
    const MAX_BLOCKS = 100;
    const MAX_TEXT = 10000;

    /**
     * Match only complete, simple leaf paragraphs/headings. Inline markup,
     * custom blocks and complex layouts stay exactly as WordPress stored them.
     * The text byte offsets allow surgical replacements with no serialization.
     */
    public static function editable_blocks($content)
    {
        if (!is_string($content) || strlen($content) > 1000000 ||
            strpos($content, '<!-- wp:') === false) { return array(); }
        $pattern = '~<!--\s+wp:(paragraph|heading|list-item)(?:\s+\{[^\r\n]*?\})?\s*-->\s*<(p|h[1-6]|li)\b[^>]*>([^<]*)</\2>\s*<!--\s+/wp:\1\s*-->~is';
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return array();
        }
        $blocks = array();
        foreach ($matches as $match) {
            $type = strtolower($match[1][0]);
            $tag = strtolower($match[2][0]);
            if (($type === 'paragraph' && $tag !== 'p') ||
                ($type === 'heading' && !preg_match('/^h[1-6]$/', $tag)) ||
                ($type === 'list-item' && $tag !== 'li')) { continue; }
            $text = html_entity_decode($match[3][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!is_string($text) || strpos($text, "\0") !== false || strlen($text) > self::MAX_TEXT) {
                continue;
            }
            $blocks[] = array(
                'id' => count($blocks), 'kind' => $type,
                'level' => $type === 'heading' ? (int) substr($tag, 1) : 0,
                'text' => $text, 'offset' => $match[3][1], 'length' => strlen($match[3][0]),
            );
            if (count($blocks) >= self::MAX_BLOCKS) { break; }
        }
        return $blocks;
    }

    public static function supports($content)
    {
        return count(self::editable_blocks($content)) > 0;
    }

    /** Return false for invalid submissions; otherwise preserve all non-text bytes. */
    public static function apply_edits($original, $submitted)
    {
        $blocks = self::editable_blocks($original);
        if (!$blocks || !is_array($submitted) || count($submitted) !== count($blocks)) { return false; }
        $result = $original;
        // Replace from right to left so each original byte offset stays valid.
        for ($i = count($blocks) - 1; $i >= 0; --$i) {
            $block = $blocks[$i];
            if (!array_key_exists($i, $submitted) || !is_string($submitted[$i]) ||
                strlen($submitted[$i]) > self::MAX_TEXT || strpos($submitted[$i], "\0") !== false) {
                return false;
            }
            $text = sanitize_text_field($submitted[$i]);
            if (strlen($text) > self::MAX_TEXT) { return false; }
            if ($text === $block['text']) { continue; }
            $result = substr_replace($result, esc_html($text), $block['offset'], $block['length']);
        }
        return self::is_safe_transition($original, $result) ? $result : false;
    }

    /** Verify approved content changes only text nodes of the original leaf blocks. */
    public static function is_safe_transition($original, $proposed)
    {
        if (!is_string($original) || !is_string($proposed) || strlen($proposed) > 1000000) {
            return false;
        }
        $old_blocks = self::editable_blocks($original);
        $new_blocks = self::editable_blocks($proposed);
        if (!$old_blocks || count($new_blocks) !== count($old_blocks)) { return false; }
        foreach (array(array($original, $old_blocks), array($proposed, $new_blocks)) as $source) {
            $masked = $source[0];
            foreach (array_reverse($source[1]) as $block) {
                $masked = substr_replace($masked, '{{DSE_TEXT_' . $block['id'] . '}}',
                    $block['offset'], $block['length']);
            }
            if (!isset($template)) { $template = $masked; }
            elseif (!hash_equals($template, $masked)) { return false; }
        }
        return true;
    }
}
