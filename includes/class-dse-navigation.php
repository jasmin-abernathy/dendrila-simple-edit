<?php
/** Delegated ordering of an existing primary WordPress navigation menu. */
if (!defined('ABSPATH')) { exit; }

final class DSE_Navigation
{
    public static function boot()
    {
        add_action('admin_post_dse_nav_reorder', array(__CLASS__, 'save'));
    }

    public static function allowed()
    {
        return current_user_can('manage_options') ||
            (DSE_Plugin::can_access() && DSE_Modules::allowed('editorial', 'reorder_menu'));
    }

    /** Find one unambiguous existing navigation; never create/change menus implicitly. */
    public static function detect()
    {
        $navs = get_posts(array('post_type' => 'wp_navigation', 'post_status' => 'publish',
            'posts_per_page' => 20, 'orderby' => 'ID', 'order' => 'ASC'));
        if (count($navs) === 1) {
            $nav = $navs[0];
            $blocks = parse_blocks($nav->post_content);
            $kinds = array();
            foreach ($blocks as $block) {
                if (empty($block['blockName'])) { continue; }
                $kinds[] = $block['blockName'];
            }
            if ($kinds === array('core/page-list')) {
                $pages = get_pages(array('parent' => 0, 'sort_column' => 'menu_order,post_title',
                    'sort_order' => 'ASC', 'post_status' => 'publish'));
                $items = array();
                foreach ($pages as $page) {
                    $items[] = array('id' => (int) $page->ID, 'label' => $page->post_title);
                }
                return array('type' => 'page-list', 'target' => (int) $nav->ID,
                    'items' => $items, 'content' => $nav->post_content, 'modified' => $nav->post_modified_gmt);
            }
            $valid = array('core/navigation-link','core/navigation-submenu');
            if ($kinds && count(array_diff($kinds, $valid)) === 0) {
                $items = array();
                foreach ($blocks as $index => $block) {
                    if (!in_array($block['blockName'], $valid, true)) { continue; }
                    $label = isset($block['attrs']['label']) && is_string($block['attrs']['label']) ?
                        $block['attrs']['label'] : '';
                    $url = isset($block['attrs']['url']) && is_string($block['attrs']['url']) ?
                        $block['attrs']['url'] : '';
                    $items[] = array('id' => (int) $index, 'label' => $label ?: ($url ?: 'Lien sans titre'));
                }
                return array('type' => 'block-links', 'target' => (int) $nav->ID,
                    'items' => $items, 'content' => $nav->post_content, 'modified' => $nav->post_modified_gmt);
            }
        }
        if (count($navs) > 1) { return false; }
        $locations = get_nav_menu_locations();
        if (is_array($locations) && $locations) {
            $term_id = isset($locations['primary']) ? (int) $locations['primary'] :
                (int) reset($locations);
            if ($term_id) {
                $all = wp_get_nav_menu_items($term_id);
                if ($all && !is_wp_error($all)) {
                    $items = array();
                    foreach ($all as $item) {
                        if ((int) $item->menu_item_parent === 0) {
                            $items[] = array('id' => (int) $item->ID, 'label' => $item->title);
                        }
                    }
                    if ($items) {
                        return array('type' => 'classic', 'target' => $term_id,
                            'items' => $items, 'content' => '', 'modified' => '');
                    }
                }
            }
        }
        return false;
    }

    public static function fingerprint($menu)
    {
        if (!$menu) { return ''; }
        return hash('sha256', wp_json_encode(array(
            $menu['type'], $menu['target'], $menu['items'],
            $menu['content'], $menu['modified'],
        )));
    }

    private static function redirect($notice)
    {
        wp_safe_redirect(add_query_arg(array('page' => 'dendrila-simple-edit',
            'dse_tab' => 'menu', 'dse_nav_notice' => $notice), admin_url('admin.php')));
        exit;
    }

    public static function save()
    {
        if (!self::allowed()) {
            wp_die('Réorganisation du menu non autorisée.', 'Accès refusé', array('response' => 403));
        }
        check_admin_referer('dse_nav_reorder');
        $menu = self::detect();
        $raw = isset($_POST['dse_nav_ids']) ? wp_unslash($_POST['dse_nav_ids']) : null;
        $expected = isset($_POST['dse_nav_version']) ? wp_unslash($_POST['dse_nav_version']) : null;
        if (!$menu || !is_array($raw) || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/', $expected) ||
            count($raw) !== count($menu['items']) || count($raw) > 100) {
            wp_die('Liste invalide.', 'Requête incorrecte', array('response' => 400));
        }
        if (!hash_equals(self::fingerprint($menu), $expected)) { self::redirect('conflict'); }
        $ids = array();
        foreach ($raw as $value) {
            if (!is_string($value) || !ctype_digit($value)) {
                wp_die('Ordre invalide.', 'Requête incorrecte', array('response' => 400));
            }
            $ids[] = (int) $value;
        }
        $original = array_map(static function ($item) { return $item['id']; }, $menu['items']);
        $a = $ids; $b = $original;
        sort($a, SORT_NUMERIC); sort($b, SORT_NUMERIC);
        if ($a !== $b || count(array_unique($ids)) !== count($ids)) {
            wp_die('Impossible d’ajouter ou supprimer des liens par ce formulaire.', 'Requête incorrecte', array('response' => 400));
        }
        if ($ids === $original) { self::redirect('unchanged'); }

        if ($menu['type'] === 'page-list' || $menu['type'] === 'classic') {
            foreach ($ids as $position => $id) {
                // The selected IDs have been validated against the exact current menu.
                $result = wp_update_post(array('ID' => $id, 'menu_order' => ($position + 1) * 10), true);
                if (is_wp_error($result) || !$result) { self::redirect('error'); }
            }
        } elseif ($menu['type'] === 'block-links') {
            $post = get_post($menu['target']);
            if (!$post || $post->post_type !== 'wp_navigation') { self::redirect('conflict'); }
            $blocks = parse_blocks($post->post_content);
            $map = array();
            foreach ($blocks as $index => $block) { $map[$index] = $block; }
            $ordered = array();
            foreach ($ids as $id) { $ordered[] = $map[$id]; }
            $updated = wp_update_post(array('ID' => $post->ID,
                'post_content' => wp_slash(serialize_blocks($ordered))), true);
            if (is_wp_error($updated) || !$updated) { self::redirect('error'); }
        }
        self::redirect('saved');
    }

    public static function render()
    {
        if (!self::allowed()) {
            wp_die('Accès refusé.', 'Accès refusé', array('response' => 403));
        }
        $notice = isset($_GET['dse_nav_notice']) && is_string($_GET['dse_nav_notice']) ?
            sanitize_key(wp_unslash($_GET['dse_nav_notice'])) : '';
        $messages = array(
            'saved' => 'Le nouvel ordre du menu a été enregistré.',
            'unchanged' => 'Aucune modification à enregistrer.',
            'conflict' => 'Le menu a changé depuis son ouverture. Rechargez la page.',
            'error' => 'L’ordre n’a pas pu être entièrement enregistré. Vérifiez le menu et recommencez.',
        );
        if (isset($messages[$notice])) {
            echo '<p class="notice notice-info" role="status">' . esc_html($messages[$notice]) . '</p>';
        }
        echo '<section class="dse-card"><h2>Ordre des liens du menu principal</h2>';
        $menu = self::detect();
        if (!$menu) {
            echo '<p>Le site ne possède pas un menu principal que Simple Edit peut réorganiser sans risque. ';
            echo 'L’administrateur doit d’abord choisir ou créer un menu dans WordPress.</p></section>';
            return;
        }
        if ($menu['type'] === 'page-list') {
            echo '<p>Ce menu affiche automatiquement les pages publiées. Leur ordre est modifié, ';
            echo 'mais aucune page ni aucun lien n’est supprimé. Les nouvelles pages s’ajouteront automatiquement.</p>';
        } else {
            echo '<p>Déplacez les liens principaux. Le contenu des pages et les sous-menus ne seront pas modifiés.</p>';
        }
        if (count($menu['items']) < 2) {
            echo '<p>Il faut au moins deux liens pour modifier leur ordre.</p></section>';
            return;
        }
        echo '<form class="dse-nav-reorder" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dse_nav_reorder">';
        echo '<input type="hidden" name="dse_nav_version" value="' .
            esc_attr(self::fingerprint($menu)) . '">';
        wp_nonce_field('dse_nav_reorder');
        echo '<ol class="dse-nav-sort-list" data-dse-nav-sort>';
        foreach ($menu['items'] as $entry) {
            echo '<li class="dse-nav-sort-item"><input type="hidden" name="dse_nav_ids[]" value="' .
                (int) $entry['id'] . '"><span class="dse-nav-sort-label">' .
                esc_html($entry['label']) . '</span>';
            echo '<button type="button" class="dse-nav-drag-handle" data-dse-nav-handle ' .
                'aria-label="' . esc_attr('Déplacer : ' . $entry['label'] .
                    '. Faites glisser ou utilisez les flèches haut et bas du clavier.') .
                '" aria-keyshortcuts="ArrowUp ArrowDown" title="Maintenir et faire glisser">' .
                '<span class="dashicons dashicons-move" aria-hidden="true"></span></button>';
            echo '</li>';
        }
        echo '</ol><p class="description">Maintenez la poignée de déplacement, puis faites glisser le lien où vous le souhaitez. ';
        echo 'Au clavier, utilisez les flèches haut et bas sur la poignée. ';
        echo 'L’ordre reste inchangé sur le site tant que vous ne l’avez pas enregistré.</p>';
        echo '<p role="status" class="dse-nav-sort-status" data-dse-nav-status></p>';
        echo '<p><button type="submit" class="button button-primary">Enregistrer l’ordre du menu</button></p></form>';
        echo '</section>';
    }
}

