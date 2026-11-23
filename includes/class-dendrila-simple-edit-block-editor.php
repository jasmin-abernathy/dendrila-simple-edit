<?php
/** Shared content block safety and e-mail output for Dendrila Simple Edit. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Block_Editor
{
    /** Allowed rich text used consistently for incoming HTML and stored blocks. */
    public static function allowed_tags()
    {
        $tags = array_fill_keys(array('p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b',
            'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'hr'), array());
        $tags['div'] = array('data-dendrila-simple-edit-block' => true, 'data-dendrila-simple-edit-align' => true,
            'data-dse-block' => true, 'data-dse-align' => true,
            'data-dse-gutenberg-path' => true, 'data-dse-gutenberg-type' => true,
            'data-dse-gutenberg-level' => true);
        $tags['a'] = array('href' => true, 'title' => true, 'class' => true);
        $tags['img'] = array('src' => true, 'alt' => true, 'width' => true, 'height' => true);
        foreach (array('h1', 'h2', 'h3', 'p', 'a', 'img') as $tag) {
            $tags[$tag]['data-dendrila-simple-edit-color'] = true;
            $tags[$tag]['data-dendrila-simple-edit-align'] = true;
            $tags[$tag]['data-dse-color'] = true;
            $tags[$tag]['data-dse-align'] = true;
        }
        return $tags;
    }

    public static function clean($value)
    {
        if (!is_string($value)) { return ''; }
        $clean = wp_kses($value, self::allowed_tags(), array('https', 'http', 'mailto'));
        // There may only be one H1 *in the newsletter body*; posts have a separate title.
        $heading_count = 0;
        return preg_replace_callback('~</?h1(?=[\s>])[^>]*>~i', static function ($match) use (&$heading_count) {
            if ($match[0][1] !== '/') { ++$heading_count; }
            return $heading_count > 1 ? preg_replace('/h1/i', 'h2', $match[0], 1) : $match[0];
        }, $clean);
    }

    public static function color($candidate)
    {
        $preset = array('green' => '#24543b', 'blue' => '#245185',
            'plum' => '#713e6d', 'brick' => '#8c3832', 'slate' => '#394758');
        if (!is_string($candidate)) {return '';}
        if (isset($preset[$candidate])) {return $preset[$candidate];}
        // Reject arbitrary CSS and functions; accept only fixed-length hex colors.
        return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $candidate) === 1 ? strtolower($candidate) : '';
    }

    public static function alignment($candidate)
    {
        return in_array($candidate, array('left','center','right'), true) ?
            $candidate : 'left';
    }

    public static function frontend_colors($html)
    {
        // Allow only explicitly delegated, safe-format posts to receive inline colors.
        return self::email_body($html);
    }

    public static function contrast_text($background)
    {
        $hex = ltrim((string) $background, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) { return '#ffffff'; }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // Contrast relative to black/white: threshold gives readable CTA labels.
        return ($r * 299 + $g * 587 + $b * 114) / 1000 > 155 ? '#1d2327' : '#ffffff';
    }

    public static function email_body($source, $palette = array())
    {
        $raw = self::clean($source);
        $html = new WP_HTML_Tag_Processor($raw);
        while ($html->next_tag()) {
            $tag = $html->get_tag();
            $color = self::color($html->get_attribute('data-dendrila-simple-edit-color'));
            $align = self::alignment($html->get_attribute('data-dendrila-simple-edit-align'));
            if ($tag === 'DIV' && $html->get_attribute('data-dendrila-simple-edit-block')) {
                $html->set_attribute('style', 'text-align:' . $align . ';');
            } elseif ($tag === 'A' && $html->has_class('dendrila-simple-edit-newsletter-cta')) {
                $background = $color ?: ($palette['button'] ?? '#24543b');
                $html->set_attribute('style', 'display:inline-block;background-color:' .
                    $background . ';color:' . self::contrast_text($background) .
                    ';padding:12px 20px;border-radius:7px;font:700 15px/1.4 Arial,Helvetica,sans-serif;text-decoration:none;');
            } elseif ($tag === 'IMG') {
                $html->set_attribute('style', 'display:block;max-width:100%;height:auto;' .
                    ($align === 'center' ? 'margin-left:auto;margin-right:auto;' :
                     ($align === 'right' ? 'margin-left:auto;margin-right:0;' :
                        'margin-left:0;margin-right:auto;')) .
                    ($color ? 'border:3px solid ' . $color . ';' : ''));
                $html->set_attribute('border', '0');
            } elseif ($tag === 'H1') {
                $html->set_attribute('style', 'margin:0;font:700 30px/1.25 Arial,Helvetica,sans-serif;' .
                    'letter-spacing:-.4px;color:' . ($color ?: ($palette['accent'] ?? '#223e2c')) .
                    ';text-align:' . $align . ';');
            } elseif ($tag === 'H2') {
                $html->set_attribute('style', 'margin:0;font:700 23px/1.35 Arial,Helvetica,sans-serif;' .
                    'color:' . ($color ?: ($palette['accent'] ?? '#24543b')) . ';text-align:' . $align . ';');
            } elseif ($tag === 'P') {
                $html->set_attribute('style', 'margin:0;font:400 16px/1.65 Arial,Helvetica,sans-serif;' .
                    'color:' . ($color ?: ($palette['ink'] ?? '#293a30')) . ';text-align:' . $align . ';');
            } elseif ($tag === 'A') {
                $html->set_attribute('style', 'color:' . ($color ?: ($palette['accent'] ?? '#245185')) .
                    ';font:600 15px/1.5 Arial,Helvetica,sans-serif;text-decoration:underline;' .
                    'text-align:' . $align . ';');
            }
        }
        return $html->get_updated_html();
    }

    /**
     * Table layout + inline styles are compatible with Gmail, Outlook and
     * mobile mail apps that ignore max-width and centering on ordinary divs.
     *
     * Only sanitized content may be injected into these structural tables.
     */
    private static function email_rows($body, $palette = array())
    {
        $html = self::email_body($body, $palette);
        if (!class_exists('DOMDocument')) {
            return '<tr><td align="left" valign="top" style="padding:0 0 18px;">' .
                $html . '</td></tr>';
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $old_errors = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"?><html><body>' .
                '<div id="dendrila-simple-edit-email-source">' . $html . '</div></body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old_errors);
        }
        $source = $dom->getElementById('dendrila-simple-edit-email-source');
        if (!$source) {
            return '<tr><td align="left" valign="top" style="padding:0 0 18px;">' .
                $html . '</td></tr>';
        }
        $rows = '';
        foreach ($source->childNodes as $block) {
            if (!($block instanceof DOMElement)) { continue; }
            $kind = $block->getAttribute('data-dendrila-simple-edit-block');
            if ($kind !== '' && $block->firstElementChild) {
                $inner = $block->firstElementChild;
            } else {
                $kind = '';
                $inner = $block;
            }
            $alignment = self::alignment($block->getAttribute('data-dendrila-simple-edit-align') ?:
                $inner->getAttribute('data-dendrila-simple-edit-align'));
            $content = $dom->saveHTML($inner);
            if (!$content) { continue; }
            $padding = $kind === 'image' ? 22 : ($kind === 'title' ? 25 : 19);
            if ($kind === 'button' && $inner->tagName === 'a') {
                $color = self::color($inner->getAttribute('data-dendrila-simple-edit-color')) ?: ($palette['button'] ?? '#24543b');
                // VML-less bulletproof button: background and padding on the cell,
                // so the CTA keeps its shape when link padding is ignored.
                $content = '<table role="presentation" align="' . esc_attr($alignment) .
                    '" border="0" cellspacing="0" cellpadding="0">' .
                    '<tr><td align="center" bgcolor="' . esc_attr($color) . '"' .
                    ' style="background-color:' . esc_attr($color) . ';border-radius:7px;">' .
                    $content . '</td></tr></table>';
            }
            $rows .= '<tr><td align="' . esc_attr($alignment) .
                '" valign="top" style="padding:0 0 ' .
                (int)$padding . 'px;text-align:' . esc_attr($alignment) . ';">' .
                $content . '</td></tr>';
        }
        return $rows === '' ?
            '<tr><td align="left" style="padding:0 0 18px;">' . $html . '</td></tr>' : $rows;
    }

    /** Responsive table-based layouts; no external fonts/assets or remote requests. */
    public static function document($body, $template = array())
    {
        $t = class_exists('Dendrila_Simple_Edit_Newsletter_Templates') ?
            Dendrila_Simple_Edit_Newsletter_Templates::clean($template) : array(
                'layout'=>'classique','background'=>'#f1f5f1','paper'=>'#ffffff',
                'accent'=>'#3f9146','ink'=>'#293a30','button'=>'#24543b');
        $layout = $t['layout'];
        $rows = self::email_rows($body,$t);
        if ($layout === 'catalogue') { $rows = self::catalogue_rows($body,$t); }
        $width = $layout === 'editorial' ? 560 : ($layout === 'magazine' ? 720 : 640);
        $outer_pad = $layout === 'editorial' ? '40px 20px' : '24px 12px';
        $inner_pad = $layout === 'magazine' ? '28px 32px' : ($layout === 'editorial' ? '36px 40px' : '24px 28px');
        $border = $layout === 'editorial' ? 'border:0;' : 'border:1px solid #dce6dc;border-radius:10px;';
        $masthead = '';
        if ($layout === 'classique') {
            $masthead = '<tr><td height="5" bgcolor="'.$t['accent'].'" style="height:5px;line-height:5px;font-size:0;">&nbsp;</td></tr>';
        } elseif ($layout === 'magazine') {
            $masthead = '<tr><td bgcolor="'.$t['accent'].'" align="center" style="background-color:'.$t['accent'].';padding:35px 20px;font:700 32px Arial,sans-serif;color:'.
                self::contrast_text($t['accent']).';letter-spacing:1px;">LE MAGAZINE</td></tr>';
        } elseif ($layout === 'editorial') {
            $masthead = '<tr><td align="center" style="padding:28px 35px 8px;font:italic 18px Georgia,serif;color:'.$t['ink'].';">Une lettre pour vous</td></tr>'.
                '<tr><td align="center" style="padding:12px 35px;"><hr style="border:0;border-top:1px solid '.$t['accent'].';"></td></tr>';
        } else {
            $masthead = '<tr><td bgcolor="'.$t['accent'].'" align="left" style="padding:20px 28px;font:700 25px Arial,sans-serif;color:'.
                self::contrast_text($t['accent']).';">Notre sélection</td></tr>';
        }
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<meta name="x-apple-disable-message-reformatting"><title>Newsletter</title>' .
            '<style>@media screen and (max-width:620px){.dendrila-col{display:block!important;width:100%!important;}.dendrila-inner{padding:18px!important;}}</style></head>' .
            '<body style="margin:0;padding:0;background-color:'.$t['background'].';color:'.$t['ink'].';">' .
            '<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" bgcolor="'.$t['background'].'" ' .
            'style="width:100%;margin:0;background-color:'.$t['background'].';border-collapse:collapse;">' .
            '<tr><td align="center" valign="top" style="padding:'.$outer_pad.';text-align:center;">' .
            '<table role="presentation" align="center" border="0" cellspacing="0" cellpadding="0" width="'.$width.'" bgcolor="'.$t['paper'].'" ' .
            'style="width:100%;max-width:'.$width.'px;margin:0 auto;background-color:'.$t['paper'].';border-spacing:0;'.$border.'">' .
            $masthead . '<tr><td align="left" class="dendrila-inner" style="padding:'.$inner_pad.';text-align:left;">' .
            '<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="width:100%;border-collapse:collapse;">' .
            $rows . '<!--DENDRILA_SIMPLE_EDIT_UNSUBSCRIBE_ROW-->' .
            '</table></td></tr></table></td></tr></table></body></html>';
    }

    private static function catalogue_rows($body,$palette)
    {
        if (!class_exists('DOMDocument')) { return self::email_rows($body,$palette); }
        $html = self::email_body($body,$palette);
        $doc = new DOMDocument('1.0','UTF-8');
        $old = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="dendrila-catalogue-source">'.$html.'</div></body></html>'); }
        finally { libxml_clear_errors();libxml_use_internal_errors($old); }
        $root = $doc->getElementById('dendrila-catalogue-source');
        if (!$root) { return self::email_rows($body,$palette); }
        $blocks = array();
        foreach ($root->childNodes as $node) {
            if ($node instanceof DOMElement) { $blocks[] = $doc->saveHTML($node); }
        }
        if (!$blocks) { return self::email_rows($body,$palette); }
        $output = '';
        foreach (array_chunk($blocks,2) as $pair) {
            $output .= '<tr><td valign="top" style="padding:4px 0 18px;">' .
                '<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%"><tr>';
            foreach ($pair as $content) {
                $output .= '<td class="dendrila-col" width="50%" valign="top" style="width:50%;padding:7px;">' .
                    '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #d9e4d9;">'.
                    self::email_rows($content,$palette).'</table></td>';
            }
            if (count($pair) === 1) { $output .= '<td class="dendrila-col" width="50%" style="width:50%;"></td>'; }
            $output .= '</tr></table></td></tr>';
        }
        return $output;
    }

    /** Rebuild an older prepared campaign from the independent content snapshot.
     * Never pull the current post content: campaigns must not change retroactively.
     */
    public static function modernize_email_document($saved)
    {
        if (!is_string($saved) || strpos($saved, '<!--DENDRILA_SIMPLE_EDIT_UNSUBSCRIBE_ROW-->') !== false) {
            return $saved;
        }
        if (strpos($saved, 'data-dendrila-simple-edit-block=') === false || !class_exists('DOMDocument')) {
            return $saved;
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $before = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadHTML('<?xml encoding="UTF-8"?>' . $saved);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        if (!$ok) { return $saved; }
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//div[@data-dendrila-simple-edit-block]');
        if (!$nodes || $nodes->length < 1) { return $saved; }
        $body = '';
        foreach ($nodes as $node) {
            $body .= $dom->saveHTML($node);
        }
        return self::is_safe_for_editor($body) ? self::document($body) : $saved;
    }

    /** Convert safe Gutenberg text blocks to a view the simplified editor can edit.
     * The original block tree is retained and restored on save. */
    public static function gutenberg_editor_content($html)
    {
        if (!is_string($html)) { return false; }
        if (strpos($html, '<!-- wp:') === false) { return $html; }
        if (!function_exists('parse_blocks')) { return false; }

        $blocks = parse_blocks($html);
        if (!is_array($blocks) || !$blocks) { return false; }
        $converted = '';
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || empty($block['blockName'])) {
                $freeform = is_array($block) ? (string) ($block['innerHTML'] ?? '') : '';
                if (trim($freeform) === '') { continue; }
                return false;
            }
            $name = (string) $block['blockName'];
            $inner = trim((string) ($block['innerHTML'] ?? ''));
            $kind = '';
            $tag = '';
            $level = 2;
            $body = '';

            if ($name === 'core/paragraph' && empty($block['innerBlocks']) &&
                preg_match('~^<p(?:\\s+[^>]*)?>([\\s\\S]*)</p>$~i', $inner, $match)) {
                $kind = 'text'; $tag = 'p'; $body = esc_html(wp_strip_all_tags($match[1]));
            } elseif ($name === 'core/heading' && empty($block['innerBlocks']) &&
                preg_match('~^<h([2-6])(?:\\s+[^>]*)?>([\\s\\S]*)</h\\1>$~i', $inner, $match)) {
                $kind = 'heading'; $level = (int) $match[1]; $tag = 'h' . $level;
                $body = esc_html(wp_strip_all_tags($match[2]));
            } elseif ($name === 'core/list' &&
                preg_match('~^<(ul|ol)(?:\\s+[^>]*)?>([\\s\\S]*)</\\1>$~i', $inner, $match) &&
                ($items = self::gutenberg_list_items($block)) !== false && !empty($items)) {
                // The outer editor wrapper below already supplies the list tag.
                // Keep only its items here to avoid emitting nested <ul><ul> markup.
                $kind = 'list'; $tag = strtolower($match[1]); $body = '';
                foreach ($items as $item) { $body .= '<li>' . esc_html($item) . '</li>'; }
            } elseif ($name === 'core/quote' && !empty($block['innerBlocks'])) {
                $paragraphs = array();
                foreach ($block['innerBlocks'] as $child) {
                    if (!is_array($child) || ($child['blockName'] ?? '') !== 'core/paragraph' || !empty($child['innerBlocks']) ||
                        !preg_match('~^<p(?:\\s+[^>]*)?>([\\s\\S]*)</p>$~i', trim((string) ($child['innerHTML'] ?? '')), $child_match)) {
                        return false;
                    }
                    $paragraphs[] = esc_html(wp_strip_all_tags($child_match[1]));
                }
                if (!$paragraphs) { return false; }
                $kind = 'quote'; $tag = 'blockquote';
                foreach ($paragraphs as $paragraph) { $body .= '<p>' . $paragraph . '</p>'; }
            } else {
                return false;
            }
            $converted .= '<div data-dendrila-simple-edit-block="' . esc_attr($kind) . '" data-dse-gutenberg-path="' .
                esc_attr((string) $index) . '" data-dse-gutenberg-type="' . esc_attr($name) . '" data-dse-gutenberg-level="' .
                (int) $level . '"><' . $tag . '>' . $body . '</' . $tag . '>' . '</div>';
        }
        return $converted !== '' ? $converted : false;
    }

    /** Update editable text while serializing the original Gutenberg block tree. */
    public static function restore_gutenberg_content($original, $edited)
    {
        if (!is_string($original) || strpos($original, '<!-- wp:') === false ||
            !function_exists('parse_blocks') || !function_exists('serialize_blocks')) { return $edited; }
        $blocks = parse_blocks($original);
        if (!is_array($blocks)) { return false; }
        preg_match_all('~<div\\b([^>]*data-dse-gutenberg-path="(\\d+)"[^>]*)>([\\s\\S]*?)</div>~i', $edited, $matches, PREG_SET_ORDER);
        $seen = array();
        foreach ($matches as $match) {
            $index = (int) $match[2];
            if (isset($seen[$index]) || !isset($blocks[$index]) || !is_array($blocks[$index]) ||
                !preg_match('~data-dse-gutenberg-type="(core/(?:paragraph|heading|list|quote))"~i', $match[1], $type_match)) { return false; }
            $type = $type_match[1];
            if (($blocks[$index]['blockName'] ?? '') !== $type) { return false; }
            $inner = trim($match[3]);
            if ($type === 'core/paragraph' && preg_match('~^<p(?:\\s+[^>]*)?>([\\s\\S]*)</p>$~i', $inner, $body)) {
                $source_html = (string) ($blocks[$index]['innerHTML'] ?? '');
                $old = trim(wp_strip_all_tags($source_html));
                $new = trim(wp_strip_all_tags($body[1]));
                if ($new !== $old) { self::set_block_inner_html($blocks[$index], self::replace_block_text($source_html, 'p', $new)); }
            } elseif ($type === 'core/heading' && preg_match('~^<h([2-6])(?:\\s+[^>]*)?>([\\s\\S]*)</h\\1>$~i', $inner, $body)) {
                $source_html = (string) ($blocks[$index]['innerHTML'] ?? '');
                $old = trim(wp_strip_all_tags($source_html));
                $new = trim(wp_strip_all_tags($body[2]));
                if ($new !== $old) {
                    $level = (int) $body[1];
                    if (!isset($blocks[$index]['attrs']) || !is_array($blocks[$index]['attrs'])) { $blocks[$index]['attrs'] = array(); }
                    $blocks[$index]['attrs']['level'] = $level;
                    self::set_block_inner_html($blocks[$index], self::replace_block_text($source_html, 'h' . $level, $new));
                }
            } elseif ($type === 'core/list' && preg_match('~^<(ul|ol)>([\\s\\S]*)</\\1>$~i', $inner, $body) &&
                preg_match_all('~<li>([\\s\\S]*?)</li>~i', $body[2], $items)) {
                $new_items = array_map(static function ($item) { return trim(wp_strip_all_tags($item)); }, $items[1]);
                $old_items = self::gutenberg_list_items($blocks[$index]);
                if ($old_items === false) { return false; }
                if ($new_items !== $old_items || strtolower($body[1]) !== (!empty($blocks[$index]['attrs']['ordered']) ? 'ol' : 'ul')) {
                    if (!isset($blocks[$index]['attrs']) || !is_array($blocks[$index]['attrs'])) { $blocks[$index]['attrs'] = array(); }
                    $tag = strtolower($body[1]);
                    if ($tag === 'ol') { $blocks[$index]['attrs']['ordered'] = true; } else { unset($blocks[$index]['attrs']['ordered']); }
                    if (count($new_items) === count($old_items)) {
                        if (!empty($blocks[$index]['innerBlocks'])) {
                            foreach ($new_items as $item_index => $item_text) {
                                $source_item = (string) ($blocks[$index]['innerBlocks'][$item_index]['innerHTML'] ?? '');
                                self::set_block_inner_html($blocks[$index]['innerBlocks'][$item_index], self::replace_block_text($source_item, 'li', $item_text));
                            }
                        } else {
                            $source_list = (string) ($blocks[$index]['innerHTML'] ?? '');
                            self::set_block_inner_html($blocks[$index], self::replace_list_item_texts($source_list, $new_items));
                        }
                    } else {
                        $html = '<' . $tag . '>';
                        foreach ($new_items as $item) { $html .= '<li>' . esc_html($item) . '</li>'; }
                        $html .= '</' . $tag . '>';
                        $blocks[$index]['innerBlocks'] = array();
                        self::set_block_inner_html($blocks[$index], $html);
                    }
                }
            } elseif ($type === 'core/quote' && !empty($blocks[$index]['innerBlocks']) &&
                preg_match_all('~<p(?:\\s+[^>]*)?>([\\s\\S]*?)</p>~i', $inner, $paragraphs) &&
                count($paragraphs[1]) === count($blocks[$index]['innerBlocks'])) {
                foreach ($paragraphs[1] as $child_index => $paragraph) {
                    $old = trim(wp_strip_all_tags((string) ($blocks[$index]['innerBlocks'][$child_index]['innerHTML'] ?? '')));
                    $new = trim(wp_strip_all_tags($paragraph));
                    if ($new !== $old) { self::set_block_inner_html($blocks[$index]['innerBlocks'][$child_index], '<p>' . esc_html($new) . '</p>'); }
                }
            } else { return false; }
            $seen[$index] = true;
        }
        foreach ($blocks as $index => $block) {
            if (!empty($block['blockName']) && !isset($seen[$index])) { return false; }
        }
        return serialize_blocks($blocks);
    }

    private static function set_block_inner_html(&$block, $html)
    {
        $block['innerHTML'] = $html;
        $block['innerContent'] = array($html);
    }

    /** Keep trusted tag classes and inline layout attributes while changing text. */
    private static function replace_block_text($source_html, $tag, $text)
    {
        $tag = preg_quote($tag, '~');
        // Preserve a simple inline emphasis wrapped around the entire paragraph
        // or heading instead of silently dropping it when the text is edited.
        if (preg_match('~^(<' . $tag . '(?:\\s+[^>]*)?>)(<(strong|b|em|i)(?:\\s+[^>]*)?>)[\\s\\S]*(</\\3>)(</' . $tag . '>)$~i',
            trim((string) $source_html), $formatted)) {
            return $formatted[1] . $formatted[2] . esc_html($text) . $formatted[4] . $formatted[5];
        }
        if (preg_match('~^(<' . $tag . '(?:\\s+[^>]*)?>)[\\s\\S]*(</' . $tag . '>)$~i', trim((string) $source_html), $parts)) {
            return $parts[1] . esc_html($text) . $parts[2];
        }
        return '<' . str_replace('\\', '', $tag) . '>' . esc_html($text) . '</' . str_replace('\\', '', $tag) . '>';
    }

    /** Replace each list item while retaining trusted list-item attributes. */
    private static function replace_list_item_texts($source_html, $texts)
    {
        $index = 0;
        $updated = preg_replace_callback('~(<li(?:\\s+[^>]*)?>)[\\s\\S]*?(</li>)~i', static function ($match) use (&$index, $texts) {
            if (!array_key_exists($index, $texts)) { return $match[0]; }
            return $match[1] . esc_html($texts[$index++]) . $match[2];
        }, (string) $source_html);
        return is_string($updated) && $index === count($texts) ? $updated : (string) $source_html;
    }

    /** Return plain list item text for both legacy HTML lists and nested core/list-item blocks. */
    private static function gutenberg_list_items($block)
    {
        if (empty($block['innerBlocks'])) {
            if (!preg_match('~<(?:ul|ol)(?:\\s+[^>]*)?>([\\s\\S]*)</(?:ul|ol)>~i', (string) ($block['innerHTML'] ?? ''), $list) ||
                !preg_match_all('~<li(?:\\s+[^>]*)?>([\\s\\S]*?)</li>~i', $list[1], $items)) { return false; }
            return array_map(static function ($item) { return trim(wp_strip_all_tags($item)); }, $items[1]);
        }
        $items = array();
        foreach ($block['innerBlocks'] as $child) {
            if (!is_array($child) || ($child['blockName'] ?? '') !== 'core/list-item' || !empty($child['innerBlocks']) ||
                !preg_match('~^<li(?:\\s+[^>]*)?>([\\s\\S]*)</li>$~i', trim((string) ($child['innerHTML'] ?? '')), $item)) { return false; }
            $items[] = trim(wp_strip_all_tags($item[1]));
        }
        return $items;
    }

    /** Only simple paragraphs/headings and blocks created by this editor are editable. */
    public static function is_safe_for_editor($html)
    {
        if (!is_string($html)) { return false; }
        if (trim($html) === '') { return true; }
        $html = self::gutenberg_editor_content($html);
        if (!is_string($html)) { return false; }
        if (self::has_unsafe_layout($html)) { return false; }
        // Preserve ordinary existing pages. Their plain text, simple paragraphs and
        // headings are reconstructed by the JS editor without running shortcodes.
        // More complex HTML and any shortcode remain intentionally out of scope.
        if (preg_match('~\[[a-z][a-z0-9_-]*(?:\s+[^]\r\n]*)?\]~i', $html)) { return false; }
        // Keep both the current wrapper and the legacy data-dse-block wrapper
        // editable. Simple Edit created test and site content with the legacy
        // attribute before the Gutenberg compatibility work was added.
        $remaining = preg_replace_callback('~<div\b([^>]*)\b(?:data-dendrila-simple-edit-block|data-dse-block)="(title|heading|text|image|button|link|list|quote)"([^>]*)>([\s\S]*?)</div>~i',
            static function ($match) {
                $kind = strtolower($match[2]);
                $tag = array('title' => 'h1', 'heading' => 'h2', 'text' => 'p',
                    'image' => 'img', 'button' => 'a', 'link' => 'a', 'list' => 'ul', 'quote' => 'blockquote')[$kind];
                $inner = trim($match[4]);
                if ($tag === 'img') {
                    return preg_match('~^<img\s+[^<>]*\s*/?>$~i', $inner) ? '' : $match[0];
                }
                if ($kind === 'heading' && preg_match('~^<h[2-6](?:\s+[^<>]*)?>[^<>]*</h[2-6]>$~is', $inner)) { return ''; }
                if ($tag === 'p') {
                    return preg_match('~^<p(?:\s+[^<>]*)?>(?:[^<>]|<br\s*/?>)*</p>$~is', $inner) ? '' : $match[0];
                }
                if ($kind === 'list' && preg_match('~^<(?:ul|ol)>(?:\s*<li>[^<>]*</li>\s*)+</(?:ul|ol)>$~is', $inner)) { return ''; }
                if ($kind === 'quote' && preg_match('~^<blockquote>(?:\s*<p>[^<>]*</p>\s*)+</blockquote>$~is', $inner)) { return ''; }
                return preg_match('~^<' . $tag . '(?:\s+[^<>]*)?>[^<>]*</' . $tag . '>$~is', $inner) ? '' : $match[0];
            }, $html);
        if (!is_string($remaining)) { return false; }
        // Legacy content is allowed only when each complete HTML element is one
        // plain block the visual editor understands. Rich nested markup stays
        // protected against destructive round-trips.
        $remaining = preg_replace('~<(p|h1|h[2-6])>(?:[^<>]|<br\s*/?>)*</\1>|<img\s+[^<>]*\s*/?>~is', '', $remaining);
        return is_string($remaining) && strpos($remaining, '<') === false;
    }

    public static function has_unsafe_layout($html)
    {
        if (!is_string($html)) {return true;}
        // Protect Gutenberg, Divi, Elementor and other builder markup from a destructive round-trip.
        return strpos($html, '<!-- wp:') !== false ||
            preg_match('/\[(?:et_pb_|vc_row|elementor-template|fusion_builder)/i', $html) === 1 ||
            strpos($html, '[dendrila_simple_edit') !== false;
    }
}
