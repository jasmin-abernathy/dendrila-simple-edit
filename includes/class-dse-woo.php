<?php
/**
 * Restrained WooCommerce product editor, using WooCommerce's CRUD API.
 * Only simple products; no access to orders, customers or payment settings.
 */
if (!defined('ABSPATH')) { exit; }

final class DSE_Woo
{
    private static function fields()
    {
        return array(
            'name' => 'content',
            'description' => 'content',
            'short_description' => 'content',
            'image_id' => 'images',
            'category_ids' => 'categories',
            'regular_price' => 'prices',
            'stock_quantity' => 'stock',
        );
    }

    public static function boot()
    {
        add_action('admin_post_dse_product_save', array(__CLASS__, 'save'));
    }

    public static function available()
    {
        return class_exists('WC_Product_Simple') && function_exists('wc_get_product') &&
            function_exists('wc_get_products');
    }

    public static function editable_product($product)
    {
        return self::available() && is_object($product) && method_exists($product, 'get_type') &&
            $product->get_type() === 'simple' &&
            in_array($product->get_status(), array('draft', 'pending', 'publish'), true);
    }

    public static function fingerprint($product)
    {
        $modified = $product->get_date_modified();
        $payload = array(
            $product->get_id(), $product->get_status(), $product->get_name('edit'),
            $product->get_description('edit'), $product->get_short_description('edit'),
            $product->get_image_id('edit'), $product->get_category_ids('edit'),
            $product->get_regular_price('edit'), $product->get_price('edit'),
            $product->get_sale_price('edit'), $product->get_manage_stock('edit'),
            $product->get_stock_quantity('edit'), $product->get_stock_status('edit'),
            $product->get_backorders('edit'),
            is_object($modified) && method_exists($modified, 'getTimestamp') ?
                $modified->getTimestamp() : null,
        );
        return hash('sha256', wp_json_encode($payload));
    }

    private static function allowed_field($key, $creating)
    {
        $fields = self::fields();
        if (!isset($fields[$key])) { return false; }
        if ($creating && in_array($key, array('name', 'description', 'short_description'), true)) {
            return DSE_Modules::allowed('products', 'create');
        }
        return DSE_Modules::allowed('products', $fields[$key]);
    }

    /** Validate a strictly limited update payload before touching WooCommerce. */
    public static function clean_changes($input, $product = null, $creating = false)
    {
        if (!self::available() || !is_array($input) || !$input || count($input) > 7 ||
            (!$creating && !self::editable_product($product))) {
            throw new InvalidArgumentException('Produit ou formulaire non pris en charge.');
        }
        if (!$creating && $product->get_status() === 'publish' &&
            !DSE_Modules::allowed('products', 'live')) {
            throw new InvalidArgumentException('La modification de produits publiés est désactivée.');
        }
        $result = array();
        foreach ($input as $key => $raw) {
            if (!is_string($key) || !self::allowed_field($key, $creating)) {
                throw new InvalidArgumentException('Ce champ du produit n’est pas autorisé.');
            }
            if ($key === 'category_ids') {
                if (!is_array($raw) || count($raw) > 25) {
                    throw new InvalidArgumentException('Catégories incorrectes.');
                }
                $ids = array();
                foreach ($raw as $item) {
                    if ($item === '') { continue; } // Empty sentinel for clearing categories.
                    if (!is_string($item) || !ctype_digit($item) || (int) $item < 1 ||
                        !term_exists((int) $item, 'product_cat')) {
                        throw new InvalidArgumentException('Catégorie inconnue.');
                    }
                    $ids[] = (int) $item;
                }
                $result[$key] = array_values(array_unique($ids));
                continue;
            }
            if (!is_string($raw)) {
                throw new InvalidArgumentException('Valeur de produit incorrecte.');
            }
            $raw = wp_unslash($raw);
            // Preserve the exact existing value when changing a different field.
            // Existing product descriptions can contain rich HTML that this
            // intentionally plain-text editor must never silently strip.
            if (!$creating && in_array($key, array('name', 'description', 'short_description'), true) &&
                $raw === (string) call_user_func(array($product, 'get_' . $key), 'edit')) {
                continue;
            }
            if ($key === 'image_id') {
                if ($raw !== '0' && (!ctype_digit($raw) || (int) $raw < 1 ||
                    (!DSE_Media::allowed_image((int) $raw) &&
                     ($creating || (int) $raw !== (int) $product->get_image_id('edit'))))) {
                    throw new InvalidArgumentException('Image non autorisée.');
                }
                $result[$key] = (int) $raw;
            } elseif ($key === 'regular_price') {
                // Identical values are no-ops, including on products with
                // promotions configured by the store administrator.
                if (!$creating && (
                    ($raw === '' && (string) $product->get_regular_price('edit') === '') ||
                    ($raw !== '' && preg_match('/^[0-9]+([.,][0-9]{1,6})?$/', $raw) &&
                        (string) wc_format_decimal($raw) === (string) $product->get_regular_price('edit'))
                )) {
                    continue;
                }
                // Scheduled or active sale pricing is never modified here.
                if ($product && (string) $product->get_sale_price('edit') !== '') {
                    throw new InvalidArgumentException('Prix promotionnel existant : modification réservée à WooCommerce.');
                }
                if ($raw === '') {
                    $result[$key] = '';
                } elseif (strlen($raw) > 24 || !preg_match('/^[0-9]+([.,][0-9]{1,6})?$/', $raw)) {
                    throw new InvalidArgumentException('Prix incorrect.');
                } else {
                    $price = wc_format_decimal($raw);
                    if (!is_numeric($price) || (float) $price > 99999999) {
                        throw new InvalidArgumentException('Prix incorrect.');
                    }
                    $result[$key] = (string) $price;
                }
            } elseif ($key === 'stock_quantity') {
                if (!$product || !$product->get_manage_stock() || !ctype_digit($raw) ||
                    strlen($raw) > 7 || (int) $raw > 1000000) {
                    throw new InvalidArgumentException('Le stock doit déjà être suivi dans WooCommerce.');
                }
                $result[$key] = (int) $raw;
            } else {
                $limit = $key === 'name' ? 200 : ($key === 'short_description' ? 2400 : 12000);
                $text = $key === 'name' ? sanitize_text_field($raw) : sanitize_textarea_field($raw);
                $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
                if ($length > $limit || strlen($text) > $limit * 4 ||
                    ($key === 'name' && $text === '')) {
                    throw new InvalidArgumentException('Texte de produit incorrect ou trop long.');
                }
                $result[$key] = $text;
            }
        }
        if (!$creating) {
            // Do not write unchanged values: price or stock setters can have
            // observable commercial side effects even when the form was only
            // opened to change a description.
            foreach ($result as $key => $value) {
                if ($key === 'category_ids') {
                    $original = array_map('intval', $product->get_category_ids('edit'));
                    $new_ids = $value;
                    sort($original);
                    sort($new_ids);
                    if ($original === $new_ids) { unset($result[$key]); }
                } elseif ($key === 'image_id' &&
                    $value === (int) $product->get_image_id('edit')) {
                    unset($result[$key]);
                } elseif ($key === 'stock_quantity' &&
                    $product->get_stock_quantity('edit') !== null &&
                    $value === (int) $product->get_stock_quantity('edit')) {
                    unset($result[$key]);
                } elseif ($key === 'regular_price' &&
                    (string) $value === (string) $product->get_regular_price('edit')) {
                    unset($result[$key]);
                }
            }
        }
        if ($creating && (!isset($result['name']) || $result['name'] === '')) {
            throw new InvalidArgumentException('Nom obligatoire pour un produit brouillon.');
        }
        return $result;
    }

    private static function apply($product, $changes)
    {
        foreach ($changes as $key => $value) {
            switch ($key) {
                case 'name': $product->set_name($value); break;
                case 'description': $product->set_description($value); break;
                case 'short_description': $product->set_short_description($value); break;
                case 'image_id': $product->set_image_id($value); break;
                case 'category_ids': $product->set_category_ids($value); break;
                case 'regular_price':
                    $product->set_regular_price($value);
                    // WooCommerce maintains both regular and effective prices.
                    // This path is disabled for products with sale pricing.
                    $product->set_price($value);
                    break;
                case 'stock_quantity':
                    $product->set_stock_quantity($value);
                    if ($value > 0) {
                        $product->set_stock_status('instock');
                    } else {
                        $backorders = $product->get_backorders('edit');
                        $product->set_stock_status($backorders === 'no' ? 'outofstock' : 'onbackorder');
                    }
                    break;
            }
        }
    }

    private static function post_id()
    {
        $id = isset($_POST['dse_pid']) ? wp_unslash($_POST['dse_pid']) : '';
        return is_string($id) && ctype_digit($id) ? (int) $id : 0;
    }

    public static function save()
    {
        check_admin_referer('dse_product_save');
        $id = self::post_id();
        $creating = $id === 0;
        if (!self::available() || !DSE_Modules::enabled('products') ||
            ($creating && !DSE_Modules::allowed('products', 'create'))) {
            wp_die('Module ou création non autorisé.', 'Accès refusé', array('response' => 403));
        }
        $product = $creating ? new WC_Product_Simple() : wc_get_product($id);
        if (!$creating && !self::editable_product($product)) {
            wp_die('Seuls les produits simples sont pris en charge.', 'Accès refusé', array('response' => 403));
        }
        if (!$creating) {
            $expected = isset($_POST['dse_pversion']) ? wp_unslash($_POST['dse_pversion']) : '';
            if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/', $expected) ||
                !hash_equals(self::fingerprint($product), $expected)) {
                self::redirect('conflict');
            }
        }
        $submitted = isset($_POST['dse_pfields']) ? $_POST['dse_pfields'] : array();
        try {
            $changes = self::clean_changes($submitted, $product, $creating);
        } catch (InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), 'Champ non autorisé', array('response' => 400));
        }
        if (!$creating) {
            // Refresh before persisting, reducing stale form overwrite windows.
            $fresh = wc_get_product($id);
            if (!self::editable_product($fresh) ||
                !hash_equals(self::fingerprint($fresh), self::fingerprint($product))) {
                self::redirect('conflict');
            }
            $product = $fresh;
        } else {
            $product->set_status('draft');
        }
        if (!$changes) { self::redirect('unchanged'); }
        try {
            self::apply($product, $changes);
            $saved_id = $product->save();
        } catch (Exception $error) {
            self::redirect('error');
        }
        if (!$saved_id || !is_int($saved_id)) { self::redirect('error'); }
        do_action('dse_product_updated', $saved_id, array_keys($changes));
        self::redirect('saved');
    }

    private static function redirect($notice)
    {
        wp_safe_redirect(add_query_arg(array('dse_pnotice' => $notice, 'dse_tab' => 'boutique'), admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function render()
    {
        if (!self::available() || !DSE_Modules::enabled('products')) { return; }
        $rights = array();
        foreach (array('create', 'content', 'images', 'categories', 'prices', 'stock', 'live') as $key) {
            $rights[$key] = DSE_Modules::allowed('products', $key);
        }
        if (!in_array(true, $rights, true)) { return; }
        echo '<section class="dse-card"><h2>Fiches produits</h2>';
        echo '<p>Vous pouvez modifier uniquement les informations autorisées. Les nouveaux produits restent en brouillon.</p>';
        $notices = array('saved' => 'Produit enregistré.', 'unchanged' => 'Aucun changement nécessaire.',
            'conflict' => 'Produit modifié entre-temps : rechargez cette page.',
            'error' => 'L’enregistrement du produit a échoué.');
        $notice = isset($_GET['dse_pnotice']) ? wp_unslash($_GET['dse_pnotice']) : '';
        if (is_string($notice) && isset($notices[$notice])) {
            echo '<p role="status">' . esc_html($notices[$notice]) . '</p>';
        }
        if ($rights['create']) { self::form(null, $rights); }
        if (array_filter($rights) === array('create' => true)) {
            echo '</section>';
            return;
        }
        $products = wc_get_products(array('type' => 'simple',
            'status' => array('draft', 'pending', 'publish'),
            'limit' => 25, 'orderby' => 'modified', 'order' => 'DESC'));
        foreach (is_array($products) ? $products : array() as $product) {
            if (!self::editable_product($product)) { continue; }
            $live = $product->get_status() === 'publish';
            if ($live && !$rights['live']) { continue; }
            echo '<article class="dse-subcard"><h3>' . esc_html($product->get_name()) .
                '</h3><p>' . ($live ? 'Produit publié : changements immédiatement visibles' :
                    'Produit non publié') . '</p>';
            self::form($product, $rights);
            echo '</article>';
        }
        echo '</section>';
    }

    private static function form($product, $rights)
    {
        $creating = $product === null;
        $id = $creating ? 'new' : (string) $product->get_id();
        echo '<form class="dse-product-form" method="post" action="' .
            esc_url(admin_url('admin-post.php')) . '">';
        echo '<h3>' . ($creating ? 'Nouveau produit brouillon' : 'Modifier la fiche') . '</h3>';
        echo '<input type="hidden" name="action" value="dse_product_save">';
        if (!$creating) {
            echo '<input type="hidden" name="dse_pid" value="' . esc_attr($id) . '">';
            echo '<input type="hidden" name="dse_pversion" value="' .
                esc_attr(self::fingerprint($product)) . '">';
        }
        wp_nonce_field('dse_product_save');
        if ($creating || $rights['content']) {
            $values = array('name' => 'Nom du produit', 'short_description' => 'Résumé',
                'description' => 'Description');
            foreach ($values as $field => $label) {
                $value = $creating ? '' : (string) call_user_func(array($product, 'get_' . $field));
                $target = 'dse-p-' . $field . '-' . $id;
                echo '<div class="dse-field"><label for="' . esc_attr($target) . '">' .
                    esc_html($label) . '</label>';
                if ($field === 'name') {
                    echo '<input id="' . esc_attr($target) . '" type="text" required maxlength="200" name="dse_pfields[name]" value="' . esc_attr($value) . '">';
                } else {
                    echo '<textarea id="' . esc_attr($target) . '" rows="4" name="dse_pfields[' .
                        esc_attr($field) . ']" maxlength="' .
                        ($field === 'description' ? '12000' : '2400') . '">' .
                        esc_textarea($value) . '</textarea>';
                }
                echo '</div>';
            }
        }
        if (!$creating && $rights['images']) {
            $image = (int) $product->get_image_id();
            $available = DSE_Media::accessible_ids();
            if ($image && !in_array($image, $available, true)) { $available[] = $image; }
            echo '<div class="dse-field dse-photo-editor" data-dse-photo-picker><label for="dse-p-image-' . esc_attr($id) .
                '">Image du produit</label><select id="dse-p-image-' . esc_attr($id) .
                '" name="dse_pfields[image_id]" data-dse-photo-select>';
            echo '<option value="0" ' . selected($image, 0, false) . '>Aucune image</option>';
            foreach ($available as $attachment_id) {
                echo '<option value="' . esc_attr((string) $attachment_id) . '" ' .
                    selected($image, $attachment_id, false) . '>' .
                    esc_html(get_the_title($attachment_id)) . '</option>';
            }
            echo '</select><div class="dse-photo-picker-actions">';
            echo '<button class="button button-secondary" type="button" data-dse-photo-open hidden>' .
                'Choisir ou importer une image</button>';
            echo '<button class="button button-link-delete" type="button" data-dse-photo-clear hidden>' .
                'Retirer cette image</button></div>';
            echo '<div class="dse-image-preview" data-dse-photo-preview>';
            if ($image && wp_attachment_is_image($image)) {
                echo wp_get_attachment_image($image, 'thumbnail', false, array('loading' => 'lazy'));
            }
            echo '</div></div>';
        }
        if (!$creating && $rights['categories']) {
            $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 51));
            if (!is_wp_error($terms) && is_array($terms) && count($terms) <= 50) {
                echo '<fieldset class="dse-permissions"><legend>Catégories existantes</legend>';
                echo '<input type="hidden" name="dse_pfields[category_ids][]" value="">';
                foreach ($terms as $term) {
                    echo '<label class="dse-check"><input type="checkbox" name="dse_pfields[category_ids][]" value="' .
                        esc_attr((string) $term->term_id) . '" ' .
                        checked(in_array((int) $term->term_id, $product->get_category_ids('edit'), true), true, false) .
                        '> ' . esc_html($term->name) . '</label>';
                }
                echo '</fieldset>';
            } else {
                echo '<p>Catégories non proposées : inventaire trop important ou indisponible. Utilisez WooCommerce pour éviter de retirer des catégories non affichées.</p>';
            }
        }
        if (!$creating && $rights['prices']) {
            echo '<div class="dse-field"><label for="dse-p-price-' . esc_attr($id) .
                '">Prix du produit (devise de la boutique)</label><input id="dse-p-price-' .
                esc_attr($id) . '" type="text" inputmode="decimal" name="dse_pfields[regular_price]" value="' .
                esc_attr((string) $product->get_regular_price('edit')) . '"></div>';
        }
        if (!$creating && $rights['stock'] && $product->get_manage_stock()) {
            echo '<div class="dse-field"><label for="dse-p-stock-' . esc_attr($id) .
                '">Quantité en stock</label><input id="dse-p-stock-' .
                esc_attr($id) . '" type="number" min="0" max="1000000" name="dse_pfields[stock_quantity]" value="' .
                esc_attr((string) $product->get_stock_quantity('edit')) . '"></div>';
        } elseif (!$creating && $rights['stock']) {
            echo '<p>Le suivi des stocks est désactivé pour ce produit dans WooCommerce.</p>';
        }
        echo '<p><button class="button button-secondary" type="submit">Enregistrer le produit</button></p></form>';
    }
}
