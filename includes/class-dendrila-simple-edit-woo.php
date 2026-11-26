<?php
/**
 * Restrained WooCommerce product editor, using WooCommerce's CRUD API.
 * Only simple products; no access to orders, customers or payment settings.
 */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Woo
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
            'manage_stock' => 'stock',
            'stock_status' => 'stock',
            'backorders' => 'stock',
            'low_stock_amount' => 'stock',
            'product_url' => 'content',
            'button_text' => 'content',
            'grouped_ids' => 'content',
        );
    }

    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_product_save', array(__CLASS__, 'save'));
        add_action('admin_post_dendrila_simple_edit_product_approve', array(__CLASS__, 'approve'));
    }

    public static function available()
    {
        return class_exists('WC_Product_Simple') && function_exists('wc_get_product') &&
            function_exists('wc_get_products');
    }

    public static function editable_product($product)
    {
        return self::available() && is_object($product) && method_exists($product, 'get_type') &&
            in_array($product->get_type(), array('simple','variable','grouped','external'), true) &&
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
            $product->get_backorders('edit'), $product->get_type(),
            is_object($modified) && method_exists($modified, 'getTimestamp') ?
                $modified->getTimestamp() : null,
        );
        return hash('sha256', wp_json_encode($payload));
    }

    private static function allowed_field($key, $creating)
    {
        $fields = self::fields();
        if (!isset($fields[$key])) { return false; }
        if ($creating && in_array($key, array('name', 'description', 'short_description', 'product_url', 'button_text', 'grouped_ids'), true)) {
            return Dendrila_Simple_Edit_Modules::allowed('products', 'create');
        }
        return Dendrila_Simple_Edit_Modules::allowed('products', $fields[$key]);
    }

    /** Validate a strictly limited update payload before touching WooCommerce. */
    public static function clean_changes($input, $product = null, $creating = false)
    {
        if (!self::available() || !is_array($input) || !$input || count($input) > 18 ||
            (!$creating && !self::editable_product($product))) {
            throw new InvalidArgumentException('Produit ou formulaire non pris en charge.');
        }
        if (!$creating && $product->get_status() === 'publish' &&
            !Dendrila_Simple_Edit_Modules::allowed('products', 'live')) {
            throw new InvalidArgumentException('La modification de produits publiés est désactivée.');
        }
        $result = array();
        foreach ($input as $key => $raw) {
            if (!is_string($key) || !self::allowed_field($key, $creating)) {
                throw new InvalidArgumentException('Ce champ du produit n’est pas autorisé.');
            }
            if ($key === 'grouped_ids') {
                if (!is_array($raw) || count($raw) > 30) { throw new InvalidArgumentException('Produits regroupés invalides.'); }
                $ids = array();
                foreach ($raw as $child) {
                    if (!is_string($child) || !ctype_digit($child)) { continue; }
                    $candidate = wc_get_product((int)$child);
                    if ($candidate && $candidate->is_type('simple') && $candidate->get_status() === 'publish' &&
                        (!$product || (int)$child !== (int)$product->get_id())) { $ids[]=(int)$child; }
                }
                $result[$key] = array_values(array_unique($ids));
                continue;
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
            // Input is unslashed once by the save() boundary.
            // Preserve the exact existing value when changing a different field.
            // Existing product descriptions can contain rich HTML that this
            // intentionally plain-text editor must never silently strip.
            if (!$creating && in_array($key, array('name', 'description', 'short_description'), true) &&
                $raw === (string) call_user_func(array($product, 'get_' . $key), 'edit')) {
                continue;
            }
            if ($key === 'manage_stock') {
                if (!in_array($raw, array('0','1'), true)) { throw new InvalidArgumentException('Suivi des stocks invalide.'); }
                $result[$key] = $raw === '1';
            } elseif ($key === 'stock_status') {
                if (!in_array($raw, array('instock','outofstock','onbackorder'), true)) { throw new InvalidArgumentException('État du stock invalide.'); }
                $result[$key] = $raw;
            } elseif ($key === 'backorders') {
                if (!in_array($raw, array('no','notify','yes'), true)) { throw new InvalidArgumentException('Option de réservation invalide.'); }
                $result[$key] = $raw;
            } elseif ($key === 'low_stock_amount') {
                if ($raw !== '' && (!ctype_digit($raw) || (int)$raw > 1000000)) { throw new InvalidArgumentException('Seuil de stock invalide.'); }
                $result[$key] = $raw === '' ? '' : (int)$raw;
            } elseif ($key === 'product_url') {
                $value = esc_url_raw($raw, array('https','http'));
                if ($raw !== '' && (!$value || !preg_match('~^https?://~', $value))) { throw new InvalidArgumentException('URL du produit invalide.'); }
                $result[$key] = $value;
            } elseif ($key === 'button_text') {
                 $value = sanitize_text_field($raw);
                $result[$key] = function_exists('mb_substr') ? mb_substr($value, 0, 100, 'UTF-8') : substr($value,0,100);
            } elseif ($key === 'image_id') {
                if ($raw !== '0' && (!ctype_digit($raw) || (int) $raw < 1 ||
                    (!Dendrila_Simple_Edit_Media::allowed_image((int) $raw) &&
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
                if ((!$creating && !$product->get_manage_stock() && ($input['manage_stock'] ?? '') !== '1') || !ctype_digit($raw) ||
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
                if ($key === 'category_ids' || $key === 'grouped_ids') {
                    $original = array_map('intval', $key === 'grouped_ids' && method_exists($product,'get_children') ? $product->get_children() : $product->get_category_ids('edit'));
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
        if (array_key_exists('manage_stock',$changes)) { $product->set_manage_stock($changes['manage_stock']); }
        foreach ($changes as $key => $value) {
            switch ($key) {
                case 'name': $product->set_name($value); break;
                case 'description': $product->set_description($value); break;
                case 'short_description': $product->set_short_description($value); break;
                case 'image_id': $product->set_image_id($value); break;
                case 'category_ids': $product->set_category_ids($value); break;
                case 'grouped_ids': if ($product->is_type('grouped')) { $product->set_children($value); } break;
                case 'product_url': if ($product->is_type('external')) { $product->set_product_url($value); } break;
                case 'button_text': if ($product->is_type('external')) { $product->set_button_text($value); } break;
                case 'manage_stock': break;
                case 'stock_status': $product->set_stock_status($value); break;
                case 'backorders': $product->set_backorders($value); break;
                case 'low_stock_amount': $product->set_low_stock_amount($value === '' ? null : $value); break;
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

    private static function post_id($nonce_action)
    {
        check_admin_referer($nonce_action);
        $id = isset($_POST['dendrila_simple_edit_pid']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_pid'])) : '';
        return is_string($id) && ctype_digit($id) ? (int) $id : 0;
    }

    private static function may_publish()
    {
        if (current_user_can('manage_options')) { return true; }
        return Dendrila_Simple_Edit_Modules::allowed('products','publish') &&
            (!Dendrila_Simple_Edit_Approvals::required('product') ||
                Dendrila_Simple_Edit_Approvals::can_approve('product'));
    }

    public static function approve()
    {
        check_admin_referer('dendrila_simple_edit_product_approve');
        if (!self::available()) { wp_die('WooCommerce indisponible.', 'Accès refusé',array('response'=>503)); }
        if (!current_user_can('manage_options') && !Dendrila_Simple_Edit_Modules::allowed('products','approve') &&
            !Dendrila_Simple_Edit_Approvals::can_approve('product')) {
            wp_die('Validation de produits non autorisée.', 'Accès refusé',array('response'=>403));
        }
        $id = self::post_id('dendrila_simple_edit_product_approve');
        $product = $id ? wc_get_product($id) : false;
        if (!$product || $product->get_status() !== 'pending') { self::redirect('conflict'); }
        $version = isset($_POST['dendrila_simple_edit_pversion']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_pversion'])) : '';
        if (!is_string($version) || !hash_equals(self::fingerprint($product), $version)) { self::redirect('conflict'); }
        $product->set_status('publish');
        try { $product->save(); } catch (Exception $error) { self::redirect('error'); }
        self::redirect('published');
    }

    private static function category_input()
    {
        check_admin_referer('dendrila_simple_edit_product_save');
        if (!isset($_POST['dendrila_simple_edit_new_category'])) { return array(); }
        if (!Dendrila_Simple_Edit_Modules::allowed('products','create_categories') ||
            !Dendrila_Simple_Edit_Modules::allowed('products','categories')) {
            wp_die('Création de catégories non autorisée.', 'Accès refusé',array('response'=>403));
        }
        $raw = map_deep(wp_unslash($_POST['dendrila_simple_edit_new_category']), 'sanitize_text_field');
        if (!is_array($raw)) { wp_die('Catégorie incorrecte.'); }
        $name = sanitize_text_field($raw['name'] ?? '');
        if ($name === '') { return array(); }
        if (strlen($name) > 150) { wp_die('Nom de catégorie trop long.'); }
        $parent = absint($raw['parent'] ?? 0);
        if ($parent && !term_exists($parent,'product_cat')) { wp_die('Catégorie parente inexistante.'); }
        $description = sanitize_textarea_field($raw['description'] ?? '');
        if (strlen($description) > 1000) { wp_die('Description trop longue.'); }
        return array('name'=>$name,'parent'=>$parent,'description'=>$description);
    }

    private static function category_create($data)
    {
        if (!$data) { return 0; }
        $exists = term_exists($data['name'], 'product_cat', $data['parent']);
        if ($exists) { return (int) (is_array($exists) ? $exists['term_id'] : $exists); }
        $created = wp_insert_term($data['name'],'product_cat',array('parent'=>$data['parent'],
            'description'=>$data['description']));
        if (is_wp_error($created)) { wp_die('Impossible de créer cette catégorie : '.esc_html($created->get_error_message())); }
        return (int)$created['term_id'];
    }

    /** Basic one-attribute variable product with editable variation prices and quantities. */
    private static function build_variations($product, $name, $choices)
    {
        if (!Dendrila_Simple_Edit_Modules::allowed('products','variations')) { return; }
        if (!$product->is_type('variable') || !class_exists('WC_Product_Attribute') ||
            !class_exists('WC_Product_Variation') || !$name || !$choices) { return; }
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(0);
        $attribute->set_name($name);
        $attribute->set_options($choices);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $product->set_attributes(array($attribute));
        $product->save();
        foreach ($choices as $value) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product->get_id());
            $variation->set_attributes(array(sanitize_title($name)=>$value));
            $variation->set_status('publish');
            $variation->save();
        }
        if (class_exists('WC_Product_Variable')) { WC_Product_Variable::sync($product->get_id()); }
    }

    private static function variation_changes($parent)
    {
        check_admin_referer('dendrila_simple_edit_product_save');
        if (!$parent->is_type('variable') || !Dendrila_Simple_Edit_Modules::allowed('products','variations')) { return; }
        $updates = isset($_POST['dendrila_simple_edit_variations']) ? map_deep(wp_unslash($_POST['dendrila_simple_edit_variations']), 'sanitize_text_field') : array();
        if (!is_array($updates) || count($updates)>30) { wp_die('Variations invalides.'); }
        foreach ($updates as $id=>$row) {
            if (!ctype_digit((string)$id) || !is_array($row)) { wp_die('Variation invalide.'); }
            $variation = wc_get_product((int)$id);
            if (!$variation || !$variation->is_type('variation') || $variation->get_parent_id() !== $parent->get_id()) { wp_die('Variation non autorisée.'); }
            if (array_key_exists('price',$row)) {
                if (!Dendrila_Simple_Edit_Modules::allowed('products','prices')) { wp_die('Prix non autorisé.'); }
                $price = trim((string)$row['price']);
                if ($price!=='' && !preg_match('/^[0-9]+([.,][0-9]{1,6})?$/',$price)) { wp_die('Prix de variation invalide.'); }
                $variation->set_regular_price($price===''?'':wc_format_decimal($price));
            }
            if (array_key_exists('manage_stock',$row)) {
                if (!Dendrila_Simple_Edit_Modules::allowed('products','stock')) { wp_die('Stock non autorisé.'); }
                $on = (string)$row['manage_stock'] === '1';
                $variation->set_manage_stock($on);
                if ($on && isset($row['quantity']) && ctype_digit((string)$row['quantity'])) {
                    $variation->set_stock_quantity(min(1000000,(int)$row['quantity']));
                }
            }
            $variation->save();
        }
        if (class_exists('WC_Product_Variable')) { WC_Product_Variable::sync($parent->get_id()); }
    }

    public static function save()
    {
        check_admin_referer('dendrila_simple_edit_product_save');
        $id = self::post_id('dendrila_simple_edit_product_save');
        $creating = $id === 0;
        if (!self::available() || !Dendrila_Simple_Edit_Modules::enabled('products') ||
            ($creating && !Dendrila_Simple_Edit_Modules::allowed('products', 'create'))) {
            wp_die('Module ou création non autorisé.', 'Accès refusé', array('response' => 403));
        }
        $type = $creating && isset($_POST['dendrila_simple_edit_product_type']) ?
            sanitize_key(wp_unslash($_POST['dendrila_simple_edit_product_type'])) : 'simple';
        $types = array('simple','variable','grouped','external');
        if (!in_array($type,$types,true)) { wp_die('Type de produit inconnu.'); }
        if ($creating && $type !== 'simple' && !Dendrila_Simple_Edit_Modules::allowed('products','types')) {
            wp_die('Création de ce type non autorisée.', 'Accès refusé', array('response'=>403));
        }
        $classes = array('simple'=>'WC_Product_Simple','variable'=>'WC_Product_Variable',
            'grouped'=>'WC_Product_Grouped','external'=>'WC_Product_External');
        $class = $classes[$type];
        $product = $creating ? new $class() : wc_get_product($id);
        if (!$creating && !self::editable_product($product)) {
            wp_die('Type de produit non pris en charge.', 'Accès refusé', array('response' => 403));
        }
        if (!$creating) {
            $expected = isset($_POST['dendrila_simple_edit_pversion']) ? sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_pversion'])) : '';
            if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/', $expected) ||
                !hash_equals(self::fingerprint($product), $expected)) {
                self::redirect('conflict');
            }
        }
        $submitted = isset($_POST['dendrila_simple_edit_pfields']) && is_array($_POST['dendrila_simple_edit_pfields']) ?
            wp_unslash($_POST['dendrila_simple_edit_pfields']) : array();
        // clean_changes() enforces an allowlist and applies the correct type-
        // specific sanitizer for each supported WooCommerce field.
        if (is_array($submitted) && isset($submitted['manage_stock']) && $submitted['manage_stock'] !== '1') {
            unset($submitted['stock_quantity']);
        }
        try {
            $changes = self::clean_changes($submitted, $product, $creating);
        } catch (InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), 'Champ non autorisé', array('response' => 400));
        }
        $category = self::category_input();
        $requested = isset($_POST['dendrila_simple_edit_product_status']) ?
            sanitize_key(wp_unslash($_POST['dendrila_simple_edit_product_status'])) : 'draft';
        if (!in_array($requested,array('draft','publish','pending'),true)) { wp_die('Statut de publication inconnu.'); }
        if ($requested==='publish' && !self::may_publish() &&
            !(!$creating && $product->get_status()==='publish' && Dendrila_Simple_Edit_Modules::allowed('products','live'))) { wp_die('Publication non autorisée.', 'Accès refusé',array('response'=>403)); }
        if (!$creating) {
            // Refresh before persisting, reducing stale form overwrite windows.
            $fresh = wc_get_product($id);
            if (!self::editable_product($fresh) ||
                !hash_equals(self::fingerprint($fresh), self::fingerprint($product))) {
                self::redirect('conflict');
            }
            $product = $fresh;
        }
        if (!$creating && $product->get_status()==='publish' && $requested!=='publish' &&
            !current_user_can('manage_options')) {
            wp_die('Dépublication réservée à l’administration.', 'Accès refusé', array('response'=>403));
        }
        if (!$changes && !$category && $requested === $product->get_status() &&
            empty($_POST['dendrila_simple_edit_variations'])) { self::redirect('unchanged'); }
        $product->set_status($requested);
        try {
            self::apply($product, $changes);
            $category_id = self::category_create($category);
            if ($category_id) { $product->set_category_ids(array_values(array_unique(array_merge(
                $product->get_category_ids('edit'), array($category_id))))); }
            $saved_id = $product->save();
            if ($creating && $product->is_type('variable')) {
                $var_name = sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_variable_attribute'] ?? ''));
                $var_values = sanitize_textarea_field(wp_unslash($_POST['dendrila_simple_edit_variable_values'] ?? ''));
                if (is_string($var_values) && $var_name !== '' && strlen($var_values)<500) {
                    $values = array_values(array_unique(array_filter(array_map('sanitize_text_field',
                        preg_split('/[,\n|]+/', $var_values)))));
                    if (count($values)<=12) { self::build_variations($product,$var_name,$values); }
                }
            }
            if (!$creating && $product->is_type('variable')) { self::variation_changes($product); }
        } catch (Exception $error) {
            self::redirect('error');
        }
        if (!$saved_id || !is_int($saved_id)) { self::redirect('error'); }
        do_action('dendrila_simple_edit_product_updated', $saved_id, array_keys($changes));
        self::redirect('saved');
    }

    private static function redirect($notice)
    {
        wp_safe_redirect(add_query_arg(array('dendrila_simple_edit_pnotice' => $notice, 'dendrila_simple_edit_tab' => 'boutique'), admin_url('admin.php?page=dendrila-simple-edit')));
        exit;
    }

    public static function render()
    {
        if (!self::available() || !Dendrila_Simple_Edit_Modules::enabled('products')) { return; }
        $rights = array();
        foreach (array('create', 'publish', 'approve', 'types', 'variations', 'create_categories', 'content', 'images', 'categories', 'prices', 'stock', 'live') as $key) {
            $rights[$key] = Dendrila_Simple_Edit_Modules::allowed('products', $key);
        }
        if (!in_array(true, $rights, true)) { return; }
        echo '<section class="dendrila-simple-edit-card"><h2>Fiches produits</h2>';
        echo '<p>Vous pouvez modifier uniquement les informations autorisées. Les nouveaux produits restent en brouillon.</p>';
        $notices = array('published' => 'Produit publié.', 'saved' => 'Produit enregistré.', 'unchanged' => 'Aucun changement nécessaire.',
            'conflict' => 'Produit modifié entre-temps : rechargez cette page.',
            'error' => 'L’enregistrement du produit a échoué.');
        $notice = isset($_GET['dendrila_simple_edit_pnotice']) ? sanitize_key(wp_unslash($_GET['dendrila_simple_edit_pnotice'])) : '';
        if (is_string($notice) && isset($notices[$notice])) {
            echo '<p role="status">' . esc_html($notices[$notice]) . '</p>';
        }
        if ($rights['create']) {
            echo '<details class="dendrila-simple-edit-product-item"><summary>Ajouter un produit</summary>';
            self::form(null, $rights);
            echo '</details>';
        }
        if (array_filter($rights) === array('create' => true)) {
            echo '</section>';
            return;
        }
        $products = wc_get_products(array('type' => array('simple','variable','grouped','external'),
            'status' => array('draft', 'pending', 'publish'),
            'limit' => 25, 'orderby' => 'modified', 'order' => 'DESC'));
        foreach (is_array($products) ? $products : array() as $product) {
            if (!self::editable_product($product)) { continue; }
            $live = $product->get_status() === 'publish';
            if ($live && !$rights['live']) { continue; }
            echo '<details class="dendrila-simple-edit-product-item"><summary>' . esc_html($product->get_name()) .
                ($live ? ' · publié' : ($product->get_status()==='pending' ? ' · en attente de validation' : ' · brouillon')) . '</summary>';
            self::form($product, $rights);
            echo '</details>';
        }
        if ($rights['approve'] || current_user_can('manage_options')) {
            foreach ($products as $product) {
                if ($product->get_status()!=='pending') { continue; }
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('dendrila_simple_edit_product_approve');
                echo '<input type="hidden" name="action" value="dendrila_simple_edit_product_approve">';
                echo '<input type="hidden" name="dendrila_simple_edit_pid" value="'.(int)$product->get_id().'">';
                echo '<input type="hidden" name="dendrila_simple_edit_pversion" value="'.esc_attr(self::fingerprint($product)).'">';
                echo '<button type="submit" class="button button-primary">Valider et publier : '.esc_html($product->get_name()).'</button></form>';
            }
        }
        echo '</section>';
    }

    private static function form($product, $rights)
    {
        $creating = $product === null;
        $id = $creating ? 'new' : (string) $product->get_id();
        echo '<form class="dendrila-simple-edit-product-form" method="post" action="' .
            esc_url(admin_url('admin-post.php')) . '">';
        echo '<h3>' . ($creating ? 'Nouveau produit' : 'Modifier la fiche') . '</h3>';
        echo '<p class="description">Parcourez les onglets pour compléter votre produit. Le nom suffit pour un brouillon ; la publication nécessite les informations adaptées au type choisi.</p>';
        if ($creating) {
            echo '<p class="dendrila-simple-edit-field"><label>Type de produit <select name="dendrila_simple_edit_product_type" data-dendrila-simple-edit-type-select>';
            $types = array('simple'=>'Simple','variable'=>'Variable (déclinaisons)','grouped'=>'Groupé','external'=>'Externe / affilié');
            foreach ($types as $key=>$label) {
                if ($key!=='simple' && !$rights['types']) { continue; }
                echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
            }
            echo '</select></label></p>';
        } else { echo '<p><strong>Type :</strong> '.esc_html($product->get_type()).'</p><input type="hidden" data-dendrila-simple-edit-current-type value="'.esc_attr($product->get_type()).'">'; }
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_product_save">';
        if (!$creating) {
            echo '<input type="hidden" name="dendrila_simple_edit_pid" value="' . esc_attr($id) . '">';
            echo '<input type="hidden" name="dendrila_simple_edit_pversion" value="' .
                esc_attr(self::fingerprint($product)) . '">';
        }
        wp_nonce_field('dendrila_simple_edit_product_save');
        $has_info = $creating || $rights['content'] || (!$creating && $rights['variations'] && $product->is_type('variable'));
        $has_images = $rights['images'];
        $has_categories = $rights['categories'];
        $has_pricing = $rights['prices'] || $rights['stock'];
        echo '<div class="dendrila-simple-edit-product-tabs" data-dendrila-simple-edit-product-tabs>';
        echo '<nav class="dendrila-simple-edit-product-subnav" aria-label="Rubriques du produit">';
        foreach (array('info' => 'Informations', 'images' => 'Image', 'categories' => 'Catégories', 'pricing' => 'Prix et stock') as $key => $name) {
            $enabled = $key === 'info' ? $has_info : ($key === 'images' ? $has_images : ($key === 'categories' ? $has_categories : $has_pricing));
            if (!$enabled) { continue; }
            echo '<button class="dendrila-simple-edit-product-tab" type="button" data-dendrila-simple-edit-product-tab="' . esc_attr($key) . '">' . esc_html($name) . '</button>';
        }
        echo '</nav>';
        if ($has_info) { echo '<section class="dendrila-simple-edit-product-panel" data-dendrila-simple-edit-product-panel="info">'; }
        if ($creating || $rights['content']) {
            $values = array('name' => 'Nom du produit', 'short_description' => 'Résumé',
                'description' => 'Description');
            foreach ($values as $field => $label) {
                $value = $creating ? '' : (string) call_user_func(array($product, 'get_' . $field));
                $target = 'dendrila-simple-edit-p-' . $field . '-' . $id;
                echo '<div class="dendrila-simple-edit-field"><label for="' . esc_attr($target) . '">' .
                    esc_html($label) . '</label>';
                if ($field === 'name') {
                    echo '<input id="' . esc_attr($target) . '" type="text" required maxlength="200" name="dendrila_simple_edit_pfields[name]" value="' . esc_attr($value) . '">';
                } else {
                    echo '<textarea id="' . esc_attr($target) . '" rows="4" name="dendrila_simple_edit_pfields[' .
                        esc_attr($field) . ']" maxlength="' .
                        ($field === 'description' ? '12000' : '2400') . '">' .
                        esc_textarea($value) . '</textarea>';
                }
                echo '</div>';
            }
        }
        if ($creating && $rights['types']) {
            echo '<div class="dendrila-simple-edit-field" data-dendrila-simple-edit-for-type="variable"><label>Nom de l’attribut de variation (ex. Taille) <input type="text" name="dendrila_simple_edit_variable_attribute" maxlength="50"></label>';
            echo '<label>Valeurs possibles, séparées par des virgules (maximum 12) <textarea name="dendrila_simple_edit_variable_values" rows="3" maxlength="500" placeholder="S, M, L"></textarea></label>';
            echo '<p class="description">Les variations seront créées sans prix ; renseignez leurs prix après la création pour pouvoir les vendre.</p></div>';
        }
        if (($creating && $rights['types']) || (!$creating && $rights['content'] && $product->is_type('external'))) {
            echo '<div data-dendrila-simple-edit-for-type="external" class="dendrila-simple-edit-field">';
            echo '<label>URL externe <input type="url" name="dendrila_simple_edit_pfields[product_url]" value="'.esc_attr(!$creating && $product->is_type('external')?$product->get_product_url():'').'"></label>';
            echo '<label>Texte du bouton <input type="text" name="dendrila_simple_edit_pfields[button_text]" value="'.esc_attr(!$creating && $product->is_type('external')?$product->get_button_text():'').'"></label></div>';
        }
        if (($creating && $rights['types']) || (!$creating && $rights['content'] && $product->is_type('grouped'))) {
            echo '<fieldset class="dendrila-simple-edit-permissions" data-dendrila-simple-edit-for-type="grouped"><legend>Produits du groupe</legend>';
            $existing_children = !$creating && $product->is_type('grouped') ? $product->get_children() : array();
            foreach (wc_get_products(array('type'=>'simple','status'=>'publish','limit'=>30)) as $child) {
                echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_pfields[grouped_ids][]" value="'.(int)$child->get_id().'" '.
                    checked(in_array((int)$child->get_id(),$existing_children,true),true,false).'>'.esc_html($child->get_name()).'</label>';
            }
            echo '</fieldset>';
        }
        if (!$creating && $product->is_type('variable') && $rights['variations']) {
            echo '<fieldset class="dendrila-simple-edit-permissions"><legend>Variations existantes</legend>';
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product((int)$variation_id);
                if (!$variation || !$variation->is_type('variation')) { continue; }
                echo '<div class="dendrila-simple-edit-subcard"><strong>'.esc_html(implode(' / ',array_values($variation->get_attributes()))).'</strong>';
                if ($rights['prices']) { echo '<label>Prix <input type="text" name="dendrila_simple_edit_variations['.(int)$variation_id.'][price]" value="'.esc_attr($variation->get_regular_price('edit')).'"></label>'; }
                if ($rights['stock']) {
                    echo '<input type="hidden" name="dendrila_simple_edit_variations['.(int)$variation_id.'][manage_stock]" value="0">';
                    echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_variations['.(int)$variation_id.'][manage_stock]" value="1" '.checked($variation->get_manage_stock(),true,false).'> Suivre le stock de cette variation</label>';
                    echo '<label>Quantité <input type="number" min="0" max="1000000" name="dendrila_simple_edit_variations['.(int)$variation_id.'][quantity]" value="'.esc_attr((string)$variation->get_stock_quantity('edit')).'"></label>';
                }
                echo '</div>';
            }
            echo '</fieldset>';
        }
        if ($has_info) { echo '</section>'; }
        if ($has_images) { echo '<section class="dendrila-simple-edit-product-panel" data-dendrila-simple-edit-product-panel="images">'; }
        if ($rights['images']) {
            $image = $creating ? 0 : (int) $product->get_image_id();
            $available = Dendrila_Simple_Edit_Media::accessible_ids();
            if ($image && !in_array($image, $available, true)) { $available[] = $image; }
            echo '<div class="dendrila-simple-edit-field dendrila-simple-edit-photo-editor" data-dendrila-simple-edit-photo-picker><label for="dendrila-simple-edit-p-image-' . esc_attr($id) .
                '">Image du produit</label><select id="dendrila-simple-edit-p-image-' . esc_attr($id) .
                '" name="dendrila_simple_edit_pfields[image_id]" data-dendrila-simple-edit-photo-select>';
            echo '<option value="0" ' . selected($image, 0, false) . '>Aucune image</option>';
            foreach ($available as $attachment_id) {
                echo '<option value="' . esc_attr((string) $attachment_id) . '" ' .
                    selected($image, $attachment_id, false) . '>' .
                    esc_html(get_the_title($attachment_id)) . '</option>';
            }
            echo '</select><div class="dendrila-simple-edit-photo-picker-actions">';
            echo '<button class="button button-secondary" type="button" data-dendrila-simple-edit-photo-open hidden>' .
                'Choisir ou importer une image</button>';
            echo '<button class="button button-link-delete" type="button" data-dendrila-simple-edit-photo-clear hidden>' .
                'Retirer cette image</button></div>';
            echo '<div class="dendrila-simple-edit-image-preview" data-dendrila-simple-edit-photo-preview>';
            if ($image && wp_attachment_is_image($image)) {
                echo wp_get_attachment_image($image, 'thumbnail', false, array('loading' => 'lazy'));
            }
            echo '</div></div>';
        }
        if ($has_images) { echo '</section>'; }
        if ($has_categories) { echo '<section class="dendrila-simple-edit-product-panel" data-dendrila-simple-edit-product-panel="categories">'; }
        if ($rights['categories']) {
            $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 51));
            if (!is_wp_error($terms) && is_array($terms) && count($terms) <= 50) {
                echo '<fieldset class="dendrila-simple-edit-permissions"><legend>Catégories existantes</legend>';
                echo '<input type="hidden" name="dendrila_simple_edit_pfields[category_ids][]" value="">';
                foreach ($terms as $term) {
                    echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_pfields[category_ids][]" value="' .
                        esc_attr((string) $term->term_id) . '" ' .
                        checked(!$creating && in_array((int) $term->term_id, $product->get_category_ids('edit'), true), true, false) .
                        '> ' . esc_html($term->name) . '</label>';
                }
                echo '</fieldset>';
                if ($rights['create_categories']) {
                    echo '<details class="dendrila-simple-edit-subcard"><summary>+ Nouvelle catégorie</summary>';
                    echo '<p><label>Nom <input type="text" name="dendrila_simple_edit_new_category[name]" maxlength="150"></label></p>';
                    echo '<p><label>Description (facultative) <textarea name="dendrila_simple_edit_new_category[description]" maxlength="1000"></textarea></label></p>';
                    echo '<p><label>Catégorie parente <select name="dendrila_simple_edit_new_category[parent]"><option value="0">Aucune</option>';
                    foreach ($terms as $term) { echo '<option value="'.(int)$term->term_id.'">'.esc_html($term->name).'</option>'; }
                    echo '</select></label></p><p>La catégorie sera créée et associée à ce produit lors de l’enregistrement.</p></details>';
                }
            } else {
                echo '<p>Catégories non proposées : inventaire trop important ou indisponible. Utilisez WooCommerce pour éviter de retirer des catégories non affichées.</p>';
            }
        }
        if ($has_categories) { echo '</section>'; }
        if ($has_pricing) { echo '<section class="dendrila-simple-edit-product-panel" data-dendrila-simple-edit-product-panel="pricing">'; }
        if ($rights['prices']) {
            echo '<div class="dendrila-simple-edit-field"><label for="dendrila-simple-edit-p-price-' . esc_attr($id) .
                '">Prix du produit (devise de la boutique)</label><input id="dendrila-simple-edit-p-price-' .
                esc_attr($id) . '" type="text" inputmode="decimal" name="dendrila_simple_edit_pfields[regular_price]" value="' .
                esc_attr($creating ? '' : (string) $product->get_regular_price('edit')) . '"></div>';
        }
        if ($rights['stock']) {
            $managed = !$creating && $product->get_manage_stock();
            echo '<input type="hidden" name="dendrila_simple_edit_pfields[manage_stock]" value="0">';
            echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_pfields[manage_stock]" value="1" ' .
                checked($managed,true,false).'> Activer le suivi des quantités</label>';
            echo '<div class="dendrila-simple-edit-field" data-dendrila-simple-edit-stock-managed>'; 
            echo '<label>Quantité disponible <input type="number" min="0" max="1000000" name="dendrila_simple_edit_pfields[stock_quantity]" value="'.
                esc_attr($creating?'0':(string)$product->get_stock_quantity('edit')).'"></label>';
            echo '<label>Seuil d’alerte <input type="number" min="0" max="1000000" name="dendrila_simple_edit_pfields[low_stock_amount]" value="'.
                esc_attr($creating?'':(string)$product->get_low_stock_amount('edit')).'"></label>';
            echo '<label>Commandes en attente <select name="dendrila_simple_edit_pfields[backorders]">';
            foreach (array('no'=>'Ne pas autoriser','notify'=>'Autoriser et prévenir','yes'=>'Autoriser') as $value=>$name) {
                echo '<option value="'.esc_attr($value).'" '.selected($creating?'no':$product->get_backorders('edit'),$value,false).'>'.esc_html($name).'</option>';
            }
            echo '</select></label></div>';
            echo '<div class="dendrila-simple-edit-field" data-dendrila-simple-edit-stock-simple>'; 
            echo '<label>Disponibilité <select name="dendrila_simple_edit_pfields[stock_status]">';
            foreach (array('instock'=>'En stock','outofstock'=>'Rupture de stock','onbackorder'=>'En réapprovisionnement') as $value=>$name) {
                echo '<option value="'.esc_attr($value).'" '.selected($creating?'instock':$product->get_stock_status('edit'),$value,false).'>'.esc_html($name).'</option>';
            }
            echo '</select></label></div>';
        }
        if ($has_pricing) { echo '</section>'; }
        echo '</div><div class="dendrila-simple-edit-product-submit">';
        echo '<label>Enregistrer comme <select name="dendrila_simple_edit_product_status">';
        $current_status = $creating?'draft':$product->get_status();
        echo '<option value="draft" '.selected($current_status,'draft',false).'>Brouillon (non publié)</option>';
        if (self::may_publish()) { echo '<option value="publish" '.selected($current_status,'publish',false).'>Publier sur le site</option>'; }
        if (!self::may_publish() && ($creating || $current_status!=='publish')) { echo '<option value="pending" '.selected($current_status,'pending',false).'>Soumettre pour validation</option>'; }
        if (!self::may_publish() && $current_status==='publish') { echo '<option value="publish" selected>Conserver publié</option>'; }
        echo '</select></label><button class="button button-primary" type="submit">Enregistrer le produit</button></div></form>';
    }
}
