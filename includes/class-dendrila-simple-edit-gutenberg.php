<?php
/** Safely edit leaf Gutenberg text without round-tripping the surrounding layout. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Gutenberg
{
    const MAX_BLOCKS = 100;
    const MAX_TEXT = 10000;

    /** Extract text runs only inside known static layouts. Keep all offsets in bytes. */
    public static function editable_blocks($content)
    {
        if (!is_string($content) || strlen($content) > 1000000 || strpos($content, '<!-- wp:') === false) {
            return array();
        }
        // Block delimiters inside an HTML attribute or raw-text element are not HTML comments.
        if (class_exists('WP_HTML_Tag_Processor')) {
            $processor = new WP_HTML_Tag_Processor($content);
            $comments = 0;
            while ($processor->next_token()) {
                if ($processor->get_comment_type() === WP_HTML_Tag_Processor::COMMENT_AS_HTML_COMMENT) { ++$comments; }
            }
            if ($comments !== substr_count($content, '<!--')) { return array(); }
        }
        $layouts = array('group', 'columns', 'column', 'cover', 'list', 'list-item', 'buttons');
        $stack = array();
        $runs = array();
        $cursor = 0;
        while (($start = strpos($content, '<!--', $cursor)) !== false) {
            $end = strpos($content, '-->', $start + 4);
            if ($end === false) { return array(); }
            $comment = trim(substr($content, $start + 4, $end - $start - 4));
            $cursor = $end + 3;
            if (!preg_match('~^(/?)wp:([a-z0-9_/-]+)(?=\s|$)(.*?)$~sD', $comment, $m)) { continue; }
            $type = $m[2];
            $args = trim($m[3]);
            if ($m[1] === '/') {
                $frame = array_pop($stack);
                if (!$frame || $frame['type'] !== $type || $args !== '') { return array(); }
                if ($frame['editable'] && count($runs) < self::MAX_BLOCKS) {
                    self::extract_runs($content, $frame['offset'], $start, $type, $runs);
                }
                continue;
            }
            if (substr($args, -1) === '/') { continue; }
            $attrs = $args === '' ? array() : json_decode($args, true);
            $parent = end($stack);
            $editable = (!$parent || $parent['children']) && is_array($attrs) &&
                !isset($attrs['metadata']['bindings']) && !isset($attrs['templateLock']);
            $stack[] = array('type' => $type, 'offset' => $cursor, 'editable' => $editable,
                'children' => $editable && in_array($type, $layouts, true));
        }
        return $stack ? array() : $runs;
    }

    private static function extract_runs($content, $offset, $end, $type, &$runs)
    {
        $wrappers = array('paragraph' => 'p', 'heading' => 'h[1-6]', 'list-item' => 'li',
            'button' => 'div', 'image' => 'figure');
        if (!isset($wrappers[$type])) { return; }
        $attrs = '(?:[^<>"\']|"[^"]*"|\'[^\']*\')*+';
        $wrapper = '~^\s*<(' . $wrappers[$type] . ')(?=[\s>])' . $attrs . '>(.*?)</\1>\s*$~siD';
        $body = substr($content, $offset, $end - $offset);
        if (strpos($body, '<!--') !== false || !preg_match($wrapper, $body, $m, PREG_OFFSET_CAPTURE)) { return; }
        $level = $type === 'heading' ? (int) substr($m[1][0], 1) : 0;
        $html = $m[2][0];
        $offset += $m[2][1];
        if ($type === 'button' || $type === 'image') {
            $inner = $type === 'button' ? '<a(?=\s|>)' . $attrs . '>(.*?)</a>' :
                '<img(?=\s|>)' . $attrs . '>\s*<figcaption(?=\s|>)' . $attrs . '>(.*?)</figcaption>';
            if (!preg_match('~^\s*' . $inner . '\s*$~siD', $html, $m, PREG_OFFSET_CAPTURE)) { return; }
            $html = $m[1][0];
            $offset += $m[1][1];
        }
        $inline = 'strong|b|em|i|a|span|mark|s|del|sup|sub|u|code|kbd|small';
        $ancestors = array();
        $pending = array();
        $cursor = 0;
        do {
            $next = strpos($html, '<', $cursor);
            $raw = substr($html, $cursor, ($next === false ? strlen($html) : $next) - $cursor);
            if (strpos($raw, '[') !== false) { return; }
            if ($raw !== '' || $html === '') {
                $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strlen($text) > self::MAX_TEXT || strpos($text, "\0") !== false ||
                    count($runs) + count($pending) >= self::MAX_BLOCKS) { return; }
                $pending[] = array('kind' => $type, 'level' => $level, 'text' => $text,
                    'offset' => $offset + $cursor, 'length' => strlen($raw));
            }
            if ($next === false) { break; }
            if (!preg_match('~\G(?:<(' . $inline . ')(?=[\s>])' . $attrs . '>|</(' . $inline .
                ')>|<br\s*/?>)~i', $html, $tag, 0, $next)) { return; }
            if (!empty($tag[2])) {
                if (array_pop($ancestors) !== strtolower($tag[2])) { return; }
            } elseif (!empty($tag[1])) { $ancestors[] = strtolower($tag[1]); }
            $cursor = $next + strlen($tag[0]);
        } while ($cursor < strlen($html));
        if ($ancestors) { return; }
        foreach ($pending as $run) {
            $run['id'] = count($runs);
            $runs[] = $run;
        }
    }

    public static function supports($content)
    {
        return count(self::editable_blocks($content)) > 0;
    }

    /** WordPress also stores plugin-generated pages as a single classic shortcode. */
    public static function uses_block_fields($content)
    {
        return is_string($content) && (strpos($content, '<!-- wp:') !== false ||
            preg_match('~^\s*\[[a-zA-Z][\w-]*(?:\s+[^\[\]<>]*)?/?\]\s*$~D', $content) ||
            (strpos($content, '[') !== false && self::shortcode_sources($content)['fields']));
    }

    /** Read original shortcode spans without running their callbacks. */
    public static function shortcode_sources($content)
    {
        $result = array('fields'=>array(), 'other'=>false);
        if (strpos($content, '[') === false) { return $result; }
        $offset = 0;
        $blocks = array();
        $literal = '';
        foreach (wp_html_split($content) as $part) {
            $start = $offset;
            $offset += strlen($part);
            if ($literal !== '') {
                if (preg_match('~^</' . $literal . '\s*>~i', $part)) { $literal = ''; }
                continue;
            }
            if (preg_match('~^<(pre|code|script|style|textarea)\b~i', $part, $m)) {
                $literal = strtolower($m[1]);
                $result['other'] = true;
                continue;
            }
            if (preg_match('~^<!--\s+(/?)wp:([\w/-]+)~', $part, $m)) {
                if ($m[1]) { array_pop($blocks); }
                elseif (substr($part, -4) === '/-->') { $result['other'] = true; }
                else { $blocks[] = $m[2]; }
                continue;
            }
            if ($part === '' || $part[0] === '<') { continue; }
            $kind = end($blocks);
            if ($kind && !in_array($kind, array('shortcode','paragraph','freeform'), true)) {
                if (trim($part) !== '') { $result['other'] = true; }
                continue;
            }
            if (!preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $part, $tags)) {
                if (trim($part) !== '') { $result['other'] = true; }
                continue;
            }
            preg_match_all('/' . get_shortcode_regex(array_unique($tags[1])) . '/', $part, $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $consumed = 0;
            foreach ($matches as $match) {
                list($code, $pos) = $match[0];
                $tag = $match[2][0];
                if (($match[1][0] === '[' && $match[6][0] === ']') ||
                    ($kind !== 'shortcode' && trim($part) !== $code && !shortcode_exists($tag))) { continue; }
                if (trim(substr($part, $consumed, $pos - $consumed)) !== '') { $result['other'] = true; }
                $result['fields'][] = array('tag'=>$tag, 'code'=>$code, 'offset'=>$start + $pos);
                $consumed = $pos + strlen($code);
            }
            if (trim(substr($part, $consumed)) !== '') { $result['other'] = true; }
        }
        return $result;
    }

    public static function replace_shortcodes($content, $values)
    {
        if (!is_array($values)) { return false; }
        $fields = self::shortcode_sources($content)['fields'];
        if (count($fields) !== count($values)) { return false; }
        foreach (array_reverse($fields, true) as $i => $field) {
            if (!isset($values[$i]) || !is_string($values[$i])) { return false; }
            $value = $values[$i];
            if ($value === $field['code']) { continue; }
            if (strlen($value) > self::MAX_TEXT || preg_match('/[<>\x00-\x1f]/', $value) ||
                !preg_match('@^\[([^<>&/\[\]\x00-\x20=]++)@', $value, $name) ||
                ($name[1] !== $field['tag'] && !shortcode_exists($name[1])) ||
                !preg_match('/^' . get_shortcode_regex(array($name[1])) . '$/D', $value, $match) ||
                strpos($match[3], '[') !== false || strpos($match[5] ?? '', '[') !== false) { return false; }
            $content = substr_replace($content, $value, $field['offset'], strlen($field['code']));
        }
        return $content;
    }

    private static function paragraph($text)
    {
        return '<!-- wp:paragraph --><p>' .
            str_replace('[', '&#91;', htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) .
            '</p><!-- /wp:paragraph -->';
    }

    public static function edit_page($original, $fields, $before, $after)
    {
        foreach (array($before, $after) as $text) {
            if (!is_string($text) || strlen($text) > self::MAX_TEXT || strpos($text, "\0") !== false ||
                !preg_match('//u', $text)) { return false; }
        }
        $body = self::editable_blocks($original) ? self::apply_edits($original, $fields) :
            ($fields === array() ? $original : false);
        if ($body === false) { return false; }
        $result = ($before === '' ? '' : self::paragraph($before) . "\n\n") . $body .
            ($after === '' ? '' : "\n\n" . self::paragraph($after));
        return strlen($result) <= 1000000 ? $result : false;
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
            $text = str_replace(array("\r\n", "\r"), "\n", $submitted[$i]);
            if ($text === str_replace(array("\r\n", "\r"), "\n", $block['text'])) { continue; }
            if (!preg_match('//u', $text)) { return false; }
            $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escaped = str_replace('[', '&#91;', $escaped);
            $result = substr_replace($result, $escaped, $block['offset'], $block['length']);
        }
        return self::is_safe_transition($original, $result) ? $result : false;
    }

    /** Abort before the database write if a WordPress save filter rewrites protected markup. */
    public static function update_post($update)
    {
        $expected = wp_unslash($update['post_content']);
        $blocked = new RuntimeException('Gutenberg layout changed by save filters');
        $guard = static function ($data, $postarr) use ($update, $expected, $blocked) {
            if (!isset($postarr['ID']) || (int) $postarr['ID'] !== (int) $update['ID']) { return $data; }
            if (wp_unslash($data['post_content']) !== $expected) { throw $blocked; }
            // Core records the new state after updating. Also retain the original
            // on the first edit when native revisions are enabled.
            if (is_wp_error(wp_save_post_revision($update['ID']))) { throw $blocked; }
            return $data;
        };
        add_filter('wp_insert_post_data', $guard, PHP_INT_MAX, 2);
        try {
            return wp_update_post($update, true);
        } catch (RuntimeException $exception) {
            if ($exception !== $blocked) { throw $exception; }
            return new WP_Error('layout_changed', 'Un filtre WordPress modifierait la mise en page.');
        } finally {
            remove_filter('wp_insert_post_data', $guard, PHP_INT_MAX);
        }
    }

    /** Verify approved content changes only text nodes of the original leaf blocks. */
    public static function is_safe_transition($original, $proposed)
    {
        if (!is_string($original) || !is_string($proposed) || strlen($proposed) > 1000000) { return false; }
        $variants = array($proposed);
        $paragraph = '<!-- wp:paragraph --><p>([^<]*)</p><!-- /wp:paragraph -->';
        foreach (array('~^(' . $paragraph . ')\n\n~', '~\n\n(' . $paragraph . ')$~D') as $pattern) {
            foreach ($variants as $body) {
                if (!preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE)) { continue; }
                $text = html_entity_decode($match[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strlen($text) <= self::MAX_TEXT && strpos($text, "\0") === false &&
                    self::paragraph($text) === $match[1][0]) {
                    $variants[] = substr_replace($body, '', $match[0][1], strlen($match[0][0]));
                }
            }
        }
        foreach ($variants as $body) {
            if ($original === $body || self::text_transition($original, $body)) { return true; }
        }
        return false;
    }

    private static function text_transition($original, $proposed)
    {
        if (!is_string($original) || !is_string($proposed) || strlen($proposed) > 1000000) {
            return false;
        }
        $old_blocks = self::editable_blocks($original);
        if (!$old_blocks) { return false; }
        $old_cursor = 0;
        $new_cursor = 0;
        foreach ($old_blocks as $block) {
            $fixed = substr($original, $old_cursor, $block['offset'] - $old_cursor);
            if (substr($proposed, $new_cursor, strlen($fixed)) !== $fixed) { return false; }
            $new_cursor += strlen($fixed);
            $end = strpos($proposed, '<', $new_cursor);
            if ($end === false || $end - $new_cursor > self::MAX_TEXT * 6) { return false; }
            $raw = substr($proposed, $new_cursor, $end - $new_cursor);
            if (strpos($raw, '[') !== false || strpos($raw, "\0") !== false ||
                strlen(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) > self::MAX_TEXT) { return false; }
            $old_cursor = $block['offset'] + $block['length'];
            $new_cursor = $end;
        }
        return substr($original, $old_cursor) === substr($proposed, $new_cursor);
    }
}
