<?php
/** Organize the existing primary WordPress menu without modifying page URLs or hierarchy. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Navigation
{
    const CLASS_OPEN = 'dendrila-simple-edit-mobile-open';
    const CLASS_CLOSED = 'dendrila-simple-edit-mobile-closed';
    const CLASSIC_PREFS = 'dendrila_simple_edit_nav_mobile_prefs';
    const AUTO_ADD = 'dendrila_simple_edit_nav_auto_add';
    const TITLE_ONLY = 'dendrila-simple-edit-nav-heading';

    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_nav_reorder', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_nav_edit', array(__CLASS__, 'edit_items'));
        add_action('transition_post_status', array(__CLASS__, 'auto_add_page'), 10, 3);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_mobile'));
        add_filter('render_block_core/navigation-submenu', array(__CLASS__, 'render_submenu'), 10, 2);
        add_filter('nav_menu_css_class', array(__CLASS__, 'classic_item_classes'), 10, 2);
        add_filter('nav_menu_link_attributes', array(__CLASS__, 'classic_heading_link_attributes'), 10, 2);
    }

    public static function allowed()
    {
        return current_user_can('manage_options') ||
            (Dendrila_Simple_Edit_Plugin::can_access() && Dendrila_Simple_Edit_Modules::allowed('editorial', 'reorder_menu'));
    }

    private static function mobile_state($block)
    {
        $class = isset($block['attrs']['className']) ? (string) $block['attrs']['className'] : '';
        return strpos(' ' . $class . ' ', ' ' . self::CLASS_OPEN . ' ') !== false ? 'open' : 'closed';
    }

    private static function flatten_blocks($blocks, &$items, &$sources, $parent = 0, $depth = 0)
    {
        // A fixed two-level hierarchy prevents accidental destructive conversions.
        if ($depth > 1) { return false; }
        foreach ($blocks as $block) {
            if (empty($block['blockName']) || !in_array($block['blockName'],
                array('core/navigation-link', 'core/navigation-submenu'), true)) { return false; }
            $kind = $block['blockName'];
            $children = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : array();
            if (($kind === 'core/navigation-link' && $children) || ($depth === 1 && $children)) { return false; }
            $id = count($items) + 1; // IDs identify the immutable pre-save snapshot, not WordPress posts.
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
            $label = isset($attrs['label']) && is_string($attrs['label']) ? $attrs['label'] : '';
            $url = isset($attrs['url']) && is_string($attrs['url']) ? $attrs['url'] : '';
            $items[] = array('id'=>$id, 'label'=>$label !== '' ? $label : ($url ?: 'Lien sans titre'),
                'parent'=>$parent, 'mobile'=>self::mobile_state($block));
            $sources[$id] = $block;
            if ($children && !self::flatten_blocks($children, $items, $sources, $id, $depth + 1)) { return false; }
        }
        return true;
    }

    /** Inspect exactly one existing block navigation or one assigned classic menu. */
    public static function detect()
    {
        $navs = get_posts(array('post_type'=>'wp_navigation', 'post_status'=>'publish',
            'posts_per_page'=>20, 'orderby'=>'ID', 'order'=>'ASC'));
        if (count($navs) === 1) {
            $nav = $navs[0];
            $blocks = parse_blocks($nav->post_content);
            $blocks = array_values(array_filter($blocks, static function ($block) { return !empty($block['blockName']); }));
            if (count($blocks) === 1 && $blocks[0]['blockName'] === 'core/page-list') {
                $pages = get_pages(array('parent'=>0, 'sort_column'=>'menu_order,post_title',
                    'sort_order'=>'ASC', 'post_status'=>'publish'));
                $items = array();
                foreach ($pages as $page) {
                    $items[] = array('id'=>(int)$page->ID, 'label'=>$page->post_title,
                        'parent'=>0, 'mobile'=>'closed');
                }
                return array('type'=>'page-list', 'target'=>(int)$nav->ID, 'items'=>$items,
                    'content'=>$nav->post_content, 'modified'=>$nav->post_modified_gmt);
            }
            if ($blocks) {
                $items = array(); $sources = array();
                if (self::flatten_blocks($blocks, $items, $sources)) {
                    return array('type'=>'block-links', 'target'=>(int)$nav->ID, 'items'=>$items,
                        'sources'=>$sources, 'content'=>$nav->post_content, 'modified'=>$nav->post_modified_gmt);
                }
            }
            return false; // Never overwrite mixed navigation or unknown blocks.
        }
        if (count($navs) > 1) { return false; }
        $locations = get_nav_menu_locations();
        if (!is_array($locations) || !$locations) { return false; }
        $menu_id = isset($locations['primary']) ? (int)$locations['primary'] : (int)reset($locations);
        if (!$menu_id) { return false; }
        $all = wp_get_nav_menu_items($menu_id);
        if (!$all || is_wp_error($all)) { return false; }
        $items = array(); $prefs = get_option(self::CLASSIC_PREFS, array());
        $ids = array();
        foreach ($all as $item) { $ids[(int)$item->ID] = true; }
        foreach ($all as $item) {
            $parent = (int)$item->menu_item_parent;
            if ($parent && !isset($ids[$parent])) { return false; }
            $items[] = array('id'=>(int)$item->ID, 'label'=>$item->title,
                'parent'=>$parent, 'mobile'=>(isset($prefs[$item->ID]) && $prefs[$item->ID] === 'open') ? 'open' : 'closed');
        }
        // Only one nesting level is supported by this simplified editor.
        $parents = array(); foreach ($items as $i) { $parents[$i['id']] = $i['parent']; }
        foreach ($items as $i) { if ($i['parent'] && !empty($parents[$i['parent']])) { return false; } }
        return array('type'=>'classic', 'target'=>$menu_id, 'items'=>$items,
            'content'=>'', 'modified'=>wp_json_encode($items));
    }

    private static function is_heading($menu, $id)
    {
        if ($menu['type'] === 'classic') {
            return get_post_meta($id, '_dendrila_simple_edit_nav_heading', true) === '1';
        }
        return isset($menu['sources'][$id]['attrs']['className']) &&
            strpos(' ' . $menu['sources'][$id]['attrs']['className'] . ' ', ' ' . self::TITLE_ONLY . ' ') !== false;
    }

    public static function fingerprint($menu)
    {
        return $menu ? hash('sha256', wp_json_encode(array($menu['type'], $menu['target'],
            $menu['items'], $menu['content'], $menu['modified']))) : '';
    }

    private static function redirect($notice)
    {
        wp_safe_redirect(add_query_arg(array('page'=>'dendrila-simple-edit',
            'dendrila_simple_edit_tab'=>'menu', 'dendrila_simple_edit_nav_notice'=>$notice), admin_url('admin.php')));
        exit;
    }

    private static function block_for_page($id)
    {
        $page = get_post($id);
        if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish') { return false; }
        return array('blockName'=>'core/navigation-link',
            'attrs'=>array('label'=>$page->post_title, 'url'=>get_permalink($page),
                'kind'=>'post-type', 'type'=>'page', 'id'=>(int)$page->ID),
            'innerBlocks'=>array(), 'innerHTML'=>'', 'innerContent'=>array());
    }

    private static function block_tree($order, $by_id, $sources)
    {
        $children = array(); $roots = array();
        foreach ($order as $id) {
            $parent = $by_id[$id]['parent'];
            if ($parent) { $children[$parent][] = $id; }
            else { $roots[] = $id; }
        }
        $result = array();
        foreach ($roots as $id) {
            $item = isset($sources[$id]) ? $sources[$id] : self::block_for_page($id);
            if (!$item) { return false; }
            $attrs = isset($item['attrs']) ? $item['attrs'] : array();
            $classnames = isset($attrs['className']) ? preg_split('/\s+/', trim((string)$attrs['className'])) : array();
            $classnames = array_values(array_diff($classnames, array(self::CLASS_OPEN, self::CLASS_CLOSED)));
            if (!empty($children[$id]) || ($item['blockName'] === 'core/navigation-submenu' &&
                strpos(' ' . (isset($attrs['className']) ? $attrs['className'] : '') . ' ', ' ' . self::TITLE_ONLY . ' ') !== false)) {
                $attrs['className'] = trim(implode(' ', $classnames) . ' ' .
                    ($by_id[$id]['mobile'] === 'open' ? self::CLASS_OPEN : self::CLASS_CLOSED));
                $item['blockName'] = 'core/navigation-submenu';
                $item['attrs'] = $attrs;
                $item['innerBlocks'] = array();
                $item['innerHTML'] = '';
                $item['innerContent'] = array();
                foreach ($children[$id] as $child_id) {
                    $child = isset($sources[$child_id]) ? $sources[$child_id] : self::block_for_page($child_id);
                    if (!$child) { return false; }
                    $child['blockName'] = 'core/navigation-link';
                    $child['innerBlocks'] = array();
                    $item['innerBlocks'][] = $child;
                    $item['innerContent'][] = null;
                }
            } else {
                $item['blockName'] = 'core/navigation-link';
                $item['attrs'] = $attrs;
                $item['innerBlocks'] = array();
                // Keep existing link markup; WordPress can render its attributes dynamically.
            }
            $result[] = $item;
        }
        return $result;
    }

    public static function save()
    {
        if (!self::allowed()) { wp_die('Réorganisation du menu non autorisée.', 'Accès refusé', array('response'=>403)); }
        check_admin_referer('dendrila_simple_edit_nav_reorder');
        $menu = self::detect();
        $raw = isset($_POST['dendrila_simple_edit_nav_ids']) ? wp_unslash($_POST['dendrila_simple_edit_nav_ids']) : null;
        $parents = isset($_POST['dendrila_simple_edit_nav_parents']) ? wp_unslash($_POST['dendrila_simple_edit_nav_parents']) : null;
        $mobile = isset($_POST['dendrila_simple_edit_nav_mobile']) ? wp_unslash($_POST['dendrila_simple_edit_nav_mobile']) : null;
        $expected = isset($_POST['dendrila_simple_edit_nav_version']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nav_version'])) : '';
        if (!$menu || !is_array($raw) || !is_array($parents) || !is_array($mobile) ||
            count($raw) !== count($menu['items']) || count($raw) > 100 ||
            count($raw) !== count($parents) || count($raw) !== count($mobile) ||
            !preg_match('/^[a-f0-9]{64}$/', $expected)) { wp_die('Liste invalide.', 'Requête incorrecte', array('response'=>400)); }
        if (!hash_equals(self::fingerprint($menu), $expected)) { self::redirect('conflict'); }
        $order = array(); $values = array();
        $old = array(); foreach ($menu['items'] as $i) { $old[$i['id']] = $i; }
        foreach ($raw as $i => $v) {
            if (!is_string($v) || !ctype_digit($v) || !isset($parents[$i], $mobile[$i]) ||
                !is_string($parents[$i]) || !ctype_digit($parents[$i]) ||
                !in_array($mobile[$i], array('open', 'closed'), true)) {
                wp_die('Structure invalide.', 'Requête incorrecte', array('response'=>400));
            }
            $id=(int)$v; $parent=(int)$parents[$i];
            if (!isset($old[$id]) || isset($values[$id]) || $id === $parent ||
                ($parent && self::is_heading($menu, $id)) ||
                ($parent && !isset($old[$parent]))) { wp_die('Lien ou parent inconnu.', 'Requête incorrecte', array('response'=>400)); }
            $order[]=$id; $values[$id]=array('parent'=>$parent, 'mobile'=>$mobile[$i]);
        }
        // Enforce exactly two levels and consecutive children. No self-parenting or cycles.
        $last_group = 0; $seen_roots = array(); $closed_groups = array();
        foreach ($order as $id) {
            $parent = $values[$id]['parent'];
            if ($parent === 0) {
                if ($last_group) { $closed_groups[$last_group] = true; }
                $last_group=$id; $seen_roots[$id]=true;
            } elseif (!isset($seen_roots[$parent]) || $parent !== $last_group ||
                $values[$parent]['parent'] !== 0 || isset($closed_groups[$parent])) {
                wp_die('Les sous-menus doivent être placés directement sous leur groupe.', 'Requête incorrecte', array('response'=>400));
            }
        }
        $original_order = array_map(static function ($v) { return $v['id']; }, $menu['items']);
        $changed = $order !== $original_order;
        foreach ($menu['items'] as $entry) {
            if ($values[$entry['id']]['parent'] !== $entry['parent'] ||
                ($entry['mobile'] !== $values[$entry['id']]['mobile'] && $values[$entry['id']]['parent'] === 0)) {
                $changed = true;
            }
        }
        if (!$changed) { self::redirect('unchanged'); }
        $has_submenus = false;
        foreach ($values as $entry) { if ($entry['parent']) { $has_submenus=true; break; } }
        if ($menu['type'] === 'page-list' && $has_submenus &&
            (!isset($_POST['dendrila_simple_edit_nav_convert_confirm']) ||
            sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nav_convert_confirm'])) !== '1')) {
            wp_die('Confirmez la conversion du menu automatique avant de créer des sous-menus.',
                'Confirmation nécessaire', array('response'=>400));
        }
        if ($menu['type'] === 'page-list' && !$has_submenus) {
            foreach ($order as $position=>$id) {
                $updated=wp_update_post(array('ID'=>$id, 'menu_order'=>($position+1)*10), true);
                if (is_wp_error($updated) || !$updated) { self::redirect('error'); }
            }
        } elseif ($menu['type'] === 'classic') {
            // Classic nav items are posts, not pages. Preserve their target URLs and labels.
            foreach ($order as $position=>$id) {
                $updated=wp_update_post(array('ID'=>$id, 'menu_order'=>($position+1)*10), true);
                if (is_wp_error($updated) || !$updated) { self::redirect('error'); }
                update_post_meta($id, '_menu_item_menu_item_parent', $values[$id]['parent']);
                if ((int)get_post_meta($id, '_menu_item_menu_item_parent', true) !== $values[$id]['parent']) {
                    self::redirect('error');
                }
            }
            $prefs=array(); foreach ($order as $id) { if ($values[$id]['parent'] === 0) { $prefs[$id]=$values[$id]['mobile']; } }
            update_option(self::CLASSIC_PREFS, $prefs, false);
            clean_term_cache($menu['target'], 'nav_menu');
        } else {
            $post=get_post($menu['target']);
            if (!$post || $post->post_type !== 'wp_navigation') { self::redirect('conflict'); }
            $sources=isset($menu['sources']) ? $menu['sources'] : array();
            $blocks=self::block_tree($order, $values, $sources);
            if (!$blocks) { self::redirect('error'); }
            $updated=wp_update_post(array('ID'=>$post->ID,
                'post_content'=>wp_slash(serialize_blocks($blocks))), true);
            if (is_wp_error($updated) || !$updated) { self::redirect('error'); }
        }
        self::redirect('saved');
    }


    /** Add/remove links without changing the underlying pages. */
    public static function edit_items()
    {
        if (!self::allowed()) { wp_die('Accès refusé.', 'Accès refusé', array('response'=>403)); }
        check_admin_referer('dendrila_simple_edit_nav_edit');
        $menu = self::detect();
        $expected = isset($_POST['dendrila_simple_edit_nav_version']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nav_version'])) : '';
        if (!$menu || !preg_match('/^[a-f0-9]{64}$/', $expected) ||
            !hash_equals(self::fingerprint($menu), $expected)) { self::redirect('conflict'); }
        $operation = isset($_POST['dendrila_simple_edit_nav_operation']) ? sanitize_key(wp_unslash($_POST['dendrila_simple_edit_nav_operation'])) : '';
        if ($operation === 'settings') {
            update_option(self::AUTO_ADD, isset($_POST['dendrila_simple_edit_nav_auto_add']) ? 'yes' : 'no', false);
            self::redirect('saved');
        }
        if ($menu['type'] === 'page-list') {
            // The automatic page list is never silently converted or altered.
            self::redirect('automatic');
        }
        $items = $menu['items'];
        $sources = isset($menu['sources']) ? $menu['sources'] : array();
        $order = array();
        $values = array();
        foreach ($items as $item) {
            $order[] = $item['id'];
            $values[$item['id']] = array('parent'=>$item['parent'], 'mobile'=>$item['mobile']);
        }
        $remove = 0;
        $new_page = 0;
        $new_url = '';
        $new_title = '';
        $parent = isset($_POST['dendrila_simple_edit_nav_parent']) ? absint($_POST['dendrila_simple_edit_nav_parent']) : 0;
        if ($operation === 'remove') {
            $remove = isset($_POST['dendrila_simple_edit_nav_item']) ? absint($_POST['dendrila_simple_edit_nav_item']) : 0;
            if (!$remove || !isset($values[$remove])) { self::redirect('error'); }
            foreach ($values as $id=>$value) {
                if ($value['parent'] === $remove) { self::redirect('has_children'); }
            }
            $order = array_values(array_diff($order, array($remove)));
            unset($values[$remove]);
        } elseif ($operation === 'add_page') {
            $new_page = isset($_POST['dendrila_simple_edit_nav_page']) ? absint($_POST['dendrila_simple_edit_nav_page']) : 0;
            $page = get_post($new_page);
            if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish') { self::redirect('error'); }
            foreach ($items as $item) {
                if ($menu['type'] === 'classic') {
                    $existing = get_post_meta($item['id'], '_menu_item_object_id', true);
                    if ((int)$existing === $new_page && get_post_meta($item['id'], '_menu_item_type', true) === 'post_type') { self::redirect('duplicate'); }
                } elseif (isset($sources[$item['id']]['attrs']['id']) &&
                    (int)$sources[$item['id']]['attrs']['id'] === $new_page &&
                    isset($sources[$item['id']]['attrs']['type']) && $sources[$item['id']]['attrs']['type'] === 'page') {
                    self::redirect('duplicate');
                }
            }
            $new_title = $page->post_title;
            $new_url = get_permalink($page);
        } elseif ($operation === 'add_heading') {
            $new_title = isset($_POST['dendrila_simple_edit_nav_heading']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nav_heading'])) : '';
            if ($new_title === '' || $parent !== 0) { self::redirect('error'); }
        } elseif ($operation === 'add_link') {
            $new_title = isset($_POST['dendrila_simple_edit_nav_title']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_nav_title'])) : '';
            $new_url = isset($_POST['dendrila_simple_edit_nav_url']) ? esc_url_raw(wp_unslash($_POST['dendrila_simple_edit_nav_url'])) : '';
            $scheme = wp_parse_url($new_url, PHP_URL_SCHEME);
            if ($new_title === '' || !$new_url || !in_array(strtolower((string)$scheme), array('https', 'http'), true)) { self::redirect('error'); }
        } else { self::redirect('error'); }
        if ($operation !== 'remove') {
            if ($parent && (!isset($values[$parent]) || $values[$parent]['parent'] !== 0)) { self::redirect('error'); }
        }
        if ($menu['type'] === 'classic') {
            if ($operation === 'remove') {
                $deleted = wp_delete_post($remove, true); // nav_menu_item only; never the target page.
                if (!$deleted) { self::redirect('error'); }
            } else {
                $args = array('menu-item-title'=>$new_title, 'menu-item-status'=>'publish',
                    'menu-item-parent-id'=>$parent);
                if ($new_page) {
                    $args['menu-item-type'] = 'post_type';
                    $args['menu-item-object'] = 'page';
                    $args['menu-item-object-id'] = $new_page;
                } else {
                    $args['menu-item-type'] = 'custom';
                    $args['menu-item-url'] = $operation === 'add_heading' ? '#' : $new_url;
                }
                $created = wp_update_nav_menu_item($menu['target'], 0, $args);
                if (is_wp_error($created) || !$created) { self::redirect('error'); }
                if ($operation === 'add_heading') { update_post_meta($created, '_dendrila_simple_edit_nav_heading', '1'); }
            }
            self::redirect('saved');
        }
        if ($operation !== 'remove') {
            $new_id = count($order) ? max($order) + 1 : 1;
            $values[$new_id] = array('parent'=>$parent, 'mobile'=>'closed');
            $sources[$new_id] = array('blockName'=>$operation === 'add_heading' ? 'core/navigation-submenu' : 'core/navigation-link',
                'attrs'=>$operation === 'add_heading'
                    ? array('label'=>$new_title, 'className'=>self::TITLE_ONLY)
                    : ($new_page
                        ? array('label'=>$new_title, 'url'=>$new_url, 'kind'=>'post-type', 'type'=>'page', 'id'=>$new_page)
                        : array('label'=>$new_title, 'url'=>$new_url, 'kind'=>'custom')),
                'innerBlocks'=>array(), 'innerHTML'=>'', 'innerContent'=>array());
            if (!$parent) { $order[] = $new_id; }
            else {
                $last = array_search($parent, $order, true);
                foreach ($order as $position=>$id) {
                    if ($values[$id]['parent'] === $parent) { $last = $position; }
                }
                array_splice($order, $last + 1, 0, array($new_id));
            }
        }
        $blocks = self::block_tree($order, $values, $sources);
        if ($blocks === false) { self::redirect('error'); }
        $updated = wp_update_post(array('ID'=>$menu['target'], 'post_content'=>wp_slash(serialize_blocks($blocks))), true);
        if (is_wp_error($updated) || !$updated) { self::redirect('error'); }
        self::redirect('saved');
    }

    /** Attach newly published pages to an explicit menu only when opted in. */
    public static function auto_add_page($new_status, $old_status, $post)
    {
        if ($new_status !== 'publish' || $old_status === 'publish' || !$post ||
            $post->post_type !== 'page' || get_option(self::AUTO_ADD, 'no') !== 'yes') { return; }
        $menu = self::detect();
        if (!$menu || $menu['type'] === 'page-list') { return; }
        foreach ($menu['items'] as $item) {
            if ($menu['type'] === 'classic') {
                if ((int)get_post_meta($item['id'], '_menu_item_object_id', true) === (int)$post->ID &&
                    get_post_meta($item['id'], '_menu_item_type', true) === 'post_type') { return; }
            } elseif (isset($menu['sources'][$item['id']]['attrs']['id']) &&
                (int)$menu['sources'][$item['id']]['attrs']['id'] === (int)$post->ID &&
                isset($menu['sources'][$item['id']]['attrs']['type']) &&
                $menu['sources'][$item['id']]['attrs']['type'] === 'page') { return; }
        }
        if ($menu['type'] === 'classic') {
            wp_update_nav_menu_item($menu['target'], 0, array('menu-item-title'=>$post->post_title,
                'menu-item-object-id'=>$post->ID, 'menu-item-object'=>'page',
                'menu-item-type'=>'post_type', 'menu-item-status'=>'publish'));
            return;
        }
        $block = self::block_for_page($post->ID);
        if (!$block) { return; }
        $blocks = parse_blocks($menu['content']);
        $blocks[] = $block;
        wp_update_post(array('ID'=>$menu['target'], 'post_content'=>wp_slash(serialize_blocks($blocks))));
    }

    public static function enqueue_mobile()
    {
        $assets=plugin_dir_path(DENDRILA_SIMPLE_EDIT_PLUGIN_FILE) . 'assets/';
        $base=plugin_dir_url(DENDRILA_SIMPLE_EDIT_PLUGIN_FILE) . 'assets/';
        foreach (array('navigation-mobile.css'=>'style', 'navigation-mobile.js'=>'script') as $name=>$type) {
            $version=is_file($assets.$name) ? (string)filemtime($assets.$name) : DENDRILA_SIMPLE_EDIT_VERSION;
            if ($type === 'style') { wp_enqueue_style('dendrila-simple-edit-nav-mobile', $base.$name, array(), $version); }
            else {
                wp_enqueue_script('dendrila-simple-edit-nav-mobile', $base.$name, array(), $version, true);
                wp_localize_script('dendrila-simple-edit-nav-mobile', 'dendrilaSimpleEditNavigationStrings',
                    array('toggle' => __('Afficher ou masquer le sous-menu', 'dendrila-simple-edit')));
            }
        }
    }

    /** Defensive class on WordPress submenu markup; avoid relying on theme classes. */
    public static function render_submenu($html, $block)
    {
        $attrs = is_object($block) && isset($block->parsed_block['attrs']) ? $block->parsed_block['attrs'] : array();
        $classes = isset($attrs['className']) ? ' ' . $attrs['className'] . ' ' : '';
        $state = strpos($classes, ' ' . self::CLASS_OPEN . ' ') !== false ? self::CLASS_OPEN :
            (strpos($classes, ' ' . self::CLASS_CLOSED . ' ') !== false ? self::CLASS_CLOSED : '');
        if (!$state || !class_exists('WP_HTML_Tag_Processor')) { return $html; }
        $processor=new WP_HTML_Tag_Processor($html);
        if ($processor->next_tag(array('tag_name'=>'li'))) {
            $processor->add_class($state);
            return $processor->get_updated_html();
        }
        return $html;
    }

    public static function classic_heading_link_attributes($atts, $item)
    {
        if (get_post_meta($item->ID, '_dendrila_simple_edit_nav_heading', true) === '1') {
            unset($atts['href']);
            $atts['role'] = 'button';
            $atts['aria-haspopup'] = 'true';
        }
        return $atts;
    }

    public static function classic_item_classes($classes, $item)
    {
        if (get_post_meta($item->ID, '_dendrila_simple_edit_nav_heading', true) === '1') {
            $classes[] = self::TITLE_ONLY;
        }
        $prefs=get_option(self::CLASSIC_PREFS, array());
        if (isset($prefs[$item->ID]) && in_array($prefs[$item->ID], array('open','closed'), true)) {
            $classes[]= $prefs[$item->ID] === 'open' ? self::CLASS_OPEN : self::CLASS_CLOSED;
        }
        return $classes;
    }

    public static function render()
    {
        if (!self::allowed()) { wp_die('Accès refusé.', 'Accès refusé', array('response'=>403)); }
        $notice=isset($_GET['dendrila_simple_edit_nav_notice']) && is_string($_GET['dendrila_simple_edit_nav_notice']) ?
            sanitize_key(wp_unslash($_GET['dendrila_simple_edit_nav_notice'])) : '';
        $messages=array('saved'=>__('Le menu et ses sous-menus ont été enregistrés.', 'dendrila-simple-edit'),
            'unchanged'=>__('Aucune modification à enregistrer.', 'dendrila-simple-edit'),
            'conflict'=>__('Le menu a changé depuis son ouverture. Rechargez la page.', 'dendrila-simple-edit'),
            'error'=>__('La réorganisation n’a pas été enregistrée. Réessayez.', 'dendrila-simple-edit'),
            'duplicate'=>__('Cette page figure déjà dans le menu.', 'dendrila-simple-edit'),
            'has_children'=>__('Déplacez ou retirez les sous-menus avant de retirer leur parent.', 'dendrila-simple-edit'),
            'automatic'=>__('Le menu automatique se gère dans les pages WordPress.', 'dendrila-simple-edit'));
        if (isset($messages[$notice])) {
            echo '<p class="notice notice-info" role="status">'.esc_html($messages[$notice]).'</p>';
        }
        echo '<section class="dendrila-simple-edit-card"><h2>' . esc_html__('Organiser le menu principal', 'dendrila-simple-edit') . '</h2>';
        $menu=self::detect();
        if (!$menu) {
            echo '<p>' . esc_html__('Cette navigation comporte plusieurs menus ou des blocs complexes. Vous pouvez les organiser depuis l’éditeur WordPress du site, sans risquer d’en perdre le contenu.', 'dendrila-simple-edit') . '</p></section>';
            return;
        }
        echo '<p>' . esc_html__('Glissez la poignée pour réorganiser les liens. Déposez sur « Sous-menu » pour créer un groupe, ou utilisez les boutons et la liste « Parent ». Vous pouvez également choisir si chaque groupe est ouvert ou fermé sur mobile.', 'dendrila-simple-edit') . '</p>';
        if ($menu['type'] === 'page-list') {
            echo '<p class="description">' . esc_html__('Votre menu est actuellement automatique : tant que vous ne créez pas de groupe, les nouvelles pages s’y ajoutent. La création d’un sous-menu le transformera en menu explicite : les nouvelles pages devront alors être ajoutées depuis WordPress.', 'dendrila-simple-edit') . '</p>';
        }
        if (!$menu['items']) { echo '<p>' . esc_html__('Aucun lien à organiser.', 'dendrila-simple-edit') . '</p></section>'; return; }
        echo '<form class="dendrila-simple-edit-nav-reorder" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_nav_reorder">';
        echo '<input type="hidden" name="dendrila_simple_edit_nav_version" value="'.esc_attr(self::fingerprint($menu)).'">';
        wp_nonce_field('dendrila_simple_edit_nav_reorder');
        echo '<ol class="dendrila-simple-edit-nav-sort-list" data-dendrila-simple-edit-nav-sort>';
        foreach ($menu['items'] as $entry) {
            $id=$entry['id']; $parent=$entry['parent'];
            echo '<li class="dendrila-simple-edit-nav-sort-item'.($parent?' is-child':'').'" data-dendrila-simple-edit-nav-id="'.(int)$id.'"'.(self::is_heading($menu, $id) ? ' data-dendrila-simple-edit-nav-heading="1"' : '').'>';
            echo '<input type="hidden" name="dendrila_simple_edit_nav_ids[]" value="'.(int)$id.'">';
            echo '<span class="dendrila-simple-edit-nav-sort-label">'.esc_html($entry['label']).'</span>';
            echo '<span class="dendrila-simple-edit-nav-group-drop" data-dendrila-simple-edit-nav-group-drop aria-hidden="true">' . esc_html__('↳ Placer en sous-page', 'dendrila-simple-edit') . '</span>';
            echo '<div class="dendrila-simple-edit-nav-sort-actions">';
            echo '<button type="button" class="dendrila-simple-edit-nav-move" data-dendrila-simple-edit-nav-move="up" aria-label="'.esc_attr(sprintf(esc_html__('Monter : %s', 'dendrila-simple-edit'), $entry['label'])).'">↑</button>';
            echo '<button type="button" class="dendrila-simple-edit-nav-move" data-dendrila-simple-edit-nav-move="down" aria-label="'.esc_attr(sprintf(esc_html__('Descendre : %s', 'dendrila-simple-edit'), $entry['label'])).'">↓</button>';
            echo '<button type="button" class="dendrila-simple-edit-nav-drag-handle" data-dendrila-simple-edit-nav-handle aria-label="'.esc_attr(sprintf(esc_html__('Déplacer : %s', 'dendrila-simple-edit'), $entry['label'])).'" aria-keyshortcuts="ArrowUp ArrowDown" title="Maintenir pour déplacer">';
            echo '<span class="dashicons dashicons-move" aria-hidden="true"></span></button></div>';
            echo '<div class="dendrila-simple-edit-nav-advanced">';
            echo '<label>' . esc_html__('Sous-menu de', 'dendrila-simple-edit') . ' <select name="dendrila_simple_edit_nav_parents[]" data-dendrila-simple-edit-nav-parent>';
            echo '<option value="0">' . esc_html__('— Niveau principal —', 'dendrila-simple-edit') . '</option>';
            foreach ($menu['items'] as $candidate) {
                if ($candidate['id'] === $id || self::is_heading($menu, $id)) { continue; }
                echo '<option value="'.(int)$candidate['id'].'"'.selected($candidate['id'], $parent, false).'>'.esc_html($candidate['label']).'</option>';
            }
            echo '</select></label>';
            echo '<label data-dendrila-simple-edit-nav-mobile-wrap>' . esc_html__('Sur mobile', 'dendrila-simple-edit') . ' <select name="dendrila_simple_edit_nav_mobile[]" data-dendrila-simple-edit-nav-mobile>';
            echo '<option value="closed"'.selected($entry['mobile'], 'closed', false).'>' . esc_html__('Fermé par défaut', 'dendrila-simple-edit') . '</option>';
            echo '<option value="open"'.selected($entry['mobile'], 'open', false).'>' . esc_html__('Ouvert par défaut', 'dendrila-simple-edit') . '</option>';
            echo '</select></label></div></li>';
        }
        echo '</ol>';
        echo '<p role="status" class="dendrila-simple-edit-nav-sort-status" data-dendrila-simple-edit-nav-status aria-live="polite"></p>';
        if ($menu['type'] === 'page-list') {
            echo '<label class="dendrila-simple-edit-nav-convert"><input type="checkbox" name="dendrila_simple_edit_nav_convert_confirm" value="1"> ' . esc_html__('Je confirme la conversion en menu manuel si je crée un sous-menu (les nouvelles pages ne seront plus ajoutées automatiquement).', 'dendrila-simple-edit') . '</label>';
        }
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Enregistrer le menu et les sous-menus', 'dendrila-simple-edit') . '</button></p></form>';
        if ($menu['type'] !== 'page-list') {
            $pages = get_pages(array('post_status'=>'publish', 'sort_column'=>'post_title', 'hierarchical'=>false));
            $taken = array();
            foreach ($menu['items'] as $entry) {
                if ($menu['type'] === 'classic') {
                    if (get_post_meta($entry['id'], '_menu_item_type', true) === 'post_type') {
                        $taken[] = (int)get_post_meta($entry['id'], '_menu_item_object_id', true);
                    }
                } elseif (isset($menu['sources'][$entry['id']]['attrs']['type']) &&
                    $menu['sources'][$entry['id']]['attrs']['type'] === 'page') {
                    $taken[] = (int)$menu['sources'][$entry['id']]['attrs']['id'];
                }
            }
            echo '<h3>'.esc_html__('Ajouter ou retirer des liens', 'dendrila-simple-edit').'</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('dendrila_simple_edit_nav_edit');
            echo '<input type="hidden" name="action" value="dendrila_simple_edit_nav_edit">';
            echo '<input type="hidden" name="dendrila_simple_edit_nav_version" value="'.esc_attr(self::fingerprint($menu)).'">';
            echo '<label>'.esc_html__('Page existante', 'dendrila-simple-edit').' <select name="dendrila_simple_edit_nav_page"><option value="0">—</option>';
            foreach ($pages as $page) {
                if (!in_array((int)$page->ID, $taken, true)) {
                    echo '<option value="'.(int)$page->ID.'">'.esc_html($page->post_title).'</option>';
                }
            }
            echo '</select></label> ';
            echo '<label>'.esc_html__('Titre du lien personnalisé', 'dendrila-simple-edit').' <input type="text" name="dendrila_simple_edit_nav_title"></label> ';
            echo '<label>'.esc_html__('URL du lien personnalisé', 'dendrila-simple-edit').' <input type="url" name="dendrila_simple_edit_nav_url" placeholder="https://"></label> ';
            echo '<label>'.esc_html__('Emplacement', 'dendrila-simple-edit').' <select name="dendrila_simple_edit_nav_parent"><option value="0">'.esc_html__('Menu principal', 'dendrila-simple-edit').'</option>';
            foreach ($menu['items'] as $entry) {
                if (!$entry['parent']) {
                    echo '<option value="'.(int)$entry['id'].'">'.esc_html($entry['label']).'</option>';
                }
            }
            echo '</select></label> ';
            echo '<button class="button" type="submit" name="dendrila_simple_edit_nav_operation" value="add_page">'.esc_html__('Ajouter la page', 'dendrila-simple-edit').'</button> ';
            echo '<button class="button" type="submit" name="dendrila_simple_edit_nav_operation" value="add_link">'.esc_html__('Ajouter le lien', 'dendrila-simple-edit').'</button>';
            echo '<p><label>'.esc_html__('Titre de regroupement (sans page ni lien)', 'dendrila-simple-edit').' <input type="text" name="dendrila_simple_edit_nav_heading"></label> ';
            echo '<button class="button" type="submit" name="dendrila_simple_edit_nav_operation" value="add_heading">'.esc_html__('Ajouter un titre au menu', 'dendrila-simple-edit').'</button></p>';
            echo '<p><label>'.esc_html__('Retirer du menu', 'dendrila-simple-edit').' <select name="dendrila_simple_edit_nav_item"><option value="0">—</option>';
            foreach ($menu['items'] as $entry) {
                echo '<option value="'.(int)$entry['id'].'">'.esc_html($entry['label']).'</option>';
            }
            echo '</select></label> ';
            echo '<button class="button" type="submit" name="dendrila_simple_edit_nav_operation" value="remove" onclick="return confirm('.esc_attr(wp_json_encode(__('Retirer ce lien du menu sans supprimer sa page ?', 'dendrila-simple-edit'))).')">'.esc_html__('Retirer le lien', 'dendrila-simple-edit').'</button></p></form>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('dendrila_simple_edit_nav_edit');
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_nav_edit">';
        echo '<input type="hidden" name="dendrila_simple_edit_nav_operation" value="settings">';
        echo '<input type="hidden" name="dendrila_simple_edit_nav_version" value="'.esc_attr(self::fingerprint($menu)).'">';
        echo '<label><input type="checkbox" name="dendrila_simple_edit_nav_auto_add" value="1"'.checked(get_option(self::AUTO_ADD, 'no'), 'yes', false).'> '.esc_html__('Ajouter automatiquement au menu principal les nouvelles pages publiées', 'dendrila-simple-edit').'</label> ';
        echo '<button type="submit" class="button">'.esc_html__('Enregistrer cette préférence', 'dendrila-simple-edit').'</button></form></section>';
    }
}
