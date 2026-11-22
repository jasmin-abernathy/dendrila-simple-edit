<?php
/** Shared content block safety and e-mail output for Dendrila Simple Edit. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Block_Editor
{
    /** Allowed rich text used consistently for incoming HTML and stored blocks. */
    public static function allowed_tags()
    {
        $tags = array_fill_keys(array('p', 'br', 'h1', 'h2', 'h3', 'h4', 'strong', 'b',
            'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'hr'), array());
        $tags['div'] = array('data-dendrila-simple-edit-block' => true, 'data-dendrila-simple-edit-align' => true,
            'data-dse-block' => true, 'data-dse-align' => true);
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

    /** Only empty content or blocks created by this editor can be delegated safely.
     * Never convert an existing Gutenberg or page-builder layout implicitly. */
    public static function is_safe_for_editor($html)
    {
        if (!is_string($html)) { return false; }
        if (trim($html) === '') { return true; }
        if (self::has_unsafe_layout($html)) { return false; }
        // Preserve ordinary existing pages. Their plain text, simple paragraphs and
        // headings are reconstructed by the JS editor without running shortcodes.
        // More complex HTML and any shortcode remain intentionally out of scope.
        if (preg_match('~\[[a-z][a-z0-9_-]*(?:\s+[^]\r\n]*)?\]~i', $html)) { return false; }
        $remaining = preg_replace_callback('~<div\s+(?:data-dendrila-simple-edit-block|data-dse-block)="(title|heading|text|image|button|link)"(?:\s+(?:data-dendrila-simple-edit-align|data-dse-align)="(?:left|center|right)")?\s*>([\s\S]*?)</div>~i',
            static function ($match) {
                $tag = array('title' => 'h1', 'heading' => 'h2', 'text' => 'p',
                    'image' => 'img', 'button' => 'a', 'link' => 'a')[strtolower($match[1])];
                $inner = trim($match[2]);
                if ($tag === 'img') {
                    return preg_match('~^<img\s+[^<>]*\s*/?>$~i', $inner) ? '' : $match[0];
                }
                if ($tag === 'p') {
                    return preg_match('~^<p(?:\s+[^<>]*)?>(?:[^<>]|<br\s*/?>)*</p>$~is', $inner) ? '' : $match[0];
                }
                return preg_match('~^<' . $tag . '(?:\s+[^<>]*)?>[^<>]*</' . $tag . '>$~is', $inner) ? '' : $match[0];
            }, $html);
        if (!is_string($remaining)) { return false; }
        // Legacy content is allowed only when each complete HTML element is one
        // plain block the visual editor understands. Rich nested markup stays
        // protected against destructive round-trips.
        $remaining = preg_replace('~<(p|h1|h2)>(?:[^<>]|<br\s*/?>)*</\1>|<img\s+[^<>]*\s*/?>~is', '', $remaining);
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
