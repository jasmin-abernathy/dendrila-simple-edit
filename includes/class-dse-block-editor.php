<?php
/** Shared content block safety and e-mail output for Dendrila Simple Edit. */
if (!defined('ABSPATH')) { exit; }

final class DSE_Block_Editor
{
    public static function clean($value)
    {
        if (!is_string($value)) { return ''; }
        $tags = array_fill_keys(array('p', 'br', 'h1', 'h2', 'h3', 'h4', 'strong', 'b',
            'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'hr'), array());
        $tags['div'] = array('data-dse-block' => true);
        $tags['a'] = array('href' => true, 'title' => true, 'class' => true);
        $tags['img'] = array('src' => true, 'alt' => true, 'width' => true, 'height' => true);
        foreach (array('h1', 'h2', 'h3', 'p', 'a', 'img') as $tag) {
            $tags[$tag]['data-dse-color'] = true;
        }
        $clean = wp_kses($value, $tags, array('https', 'http', 'mailto'));
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

    public static function email_body($source)
    {
        $raw = self::clean($source);
        $html = new WP_HTML_Tag_Processor($raw);
        while ($html->next_tag()) {
            $tag = $html->get_tag();
            $color = self::color($html->get_attribute('data-dse-color'));
            if ($tag === 'A' && $html->has_class('dse-newsletter-cta')) {
                $background = $color ?: '#24543b';
                $html->set_attribute('style', 'display:inline-block;background-color:' .
                    $background . ';color:' . self::contrast_text($background) .
                    ';padding:12px 20px;border-radius:7px;font-weight:700;text-decoration:none;');
            } elseif ($tag === 'IMG') {
                $html->set_attribute('style', 'max-width:100%;height:auto;' . ($color ? 'border:3px solid ' . $color . ';' : ''));
            } elseif ($color !== '') {
                $html->set_attribute('style', 'color:' . $color . ';');
            }
        }
        return $html->get_updated_html();
    }

    public static function document($body)
    {
        $html = self::email_body($body);
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>Newsletter</title></head><body style="margin:0;padding:24px;background:#f5f7f5;color:#1d2327;' .
            'font:16px/1.6 Arial,Helvetica,sans-serif">' .
            '<div style="max-width:640px;margin:0 auto;padding:28px;background:#fff;border-radius:10px">' .
            $html . '</div></body></html>';
    }

    /** Only empty content or blocks created by this editor can be delegated safely.
     * Never convert an existing Gutenberg or page-builder layout implicitly. */
    public static function is_safe_for_editor($html)
    {
        if (!is_string($html)) { return false; }
        if (trim($html) === '') { return true; }
        if (self::has_unsafe_layout($html)) { return false; }
        $remaining = preg_replace_callback('~<div\s+data-dse-block="(title|heading|text|image|button|link)"\s*>([\s\S]*?)</div>~i',
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
        return is_string($remaining) && trim($remaining) === '';
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
