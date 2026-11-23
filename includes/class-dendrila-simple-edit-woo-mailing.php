<?php
/** Optional WooCommerce newsletter opt-in; no MailPoet dependency. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Woo_Mailing
{
    const OPTION = 'dendrila_simple_edit_wc_mailing';
    const FIELD = 'dendrila-simple-edit/marketing-opt-in';

    public static function settings()
    {
        $data = get_option(self::OPTION, array());
        return array('enabled'=>!empty($data['enabled']), 'list_id'=>absint($data['list_id'] ?? 0));
    }

    public static function lists()
    {
        global $wpdb;
        if (get_option(Dendrila_Simple_Edit_Mailing::DB_OPTION) !== Dendrila_Simple_Edit_Mailing::DB_VERSION ||
            !Dendrila_Simple_Edit_Suppressions::ready()) { return array(); }
        $table = $wpdb->prefix . 'dendrila_simple_edit_ml_lists';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the plugin-owned list table using a bounded prepared query; ownership is checked separately.
        return (array) $wpdb->get_results($wpdb->prepare('SELECT id,name FROM %i ORDER BY name ASC LIMIT 150', $table));
    }

    public static function valid_list($id)
    {
        if ($id < 1) { return false; }
        foreach (self::lists() as $list) { if ((int)$list->id === (int)$id) { return true; } }
        return false;
    }

    public static function enabled()
    {
        $settings = self::settings();
        return $settings['enabled'] && self::valid_list($settings['list_id']) &&
            Dendrila_Simple_Edit_Modules::enabled('newsletters') &&
            Dendrila_Simple_Edit_Mailing::transport_ready();
    }

    public static function boot()
    {
        add_action('admin_post_dendrila_simple_edit_wc_mailing_settings', array(__CLASS__, 'configure'));
        add_action('woocommerce_init', array(__CLASS__, 'register_checkout_block'));
        add_action('woocommerce_review_order_before_submit', array(__CLASS__, 'classic_checkbox'));
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'classic_save'), 10, 2);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'order_paid'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'order_paid'));
    }

    public static function configure()
    {
        if (!Dendrila_Simple_Edit_Setup::can_configure()) {
            wp_die('Configuration non autorisée.', 'Accès refusé',array('response'=>403));
        }
        check_admin_referer('dendrila_simple_edit_wc_mailing_settings');
        $list = isset($_POST['dendrila_simple_edit_woo_list']) ? absint($_POST['dendrila_simple_edit_woo_list']) : 0;
        if ($list && !self::valid_list($list)) { wp_die('Cette liste n’existe pas.'); }
        update_option(self::OPTION, array('enabled'=>$list>0 && !empty($_POST['dendrila_simple_edit_woo_optin']),
            'list_id'=>$list), false);
        wp_safe_redirect(add_query_arg(array('page'=>'dendrila-simple-edit','dendrila_simple_edit_tab'=>'newsletters',
            'dendrila_simple_edit_nl_section'=>'woocommerce','dendrila_simple_edit_wc_notice'=>'saved'), admin_url('admin.php')));
        exit;
    }

    public static function render_admin()
    {
        if (!Dendrila_Simple_Edit_Setup::can_configure()) { return; }
        $settings = self::settings();
        echo '<section class="dendrila-simple-edit-card"><h2>Synchronisation WooCommerce</h2>';
        echo '<p>' . esc_html__('Demandez explicitement l’inscription pendant le paiement, sans cocher la case par défaut. Les clients reçoivent une demande de confirmation. Une désinscription antérieure reste protégée.', 'dendrila-simple-edit') . '</p>';
        if (!class_exists('WooCommerce')) { echo '<p>Activez WooCommerce pour utiliser cette option.</p>'; }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('dendrila_simple_edit_wc_mailing_settings');
        echo '<input type="hidden" name="action" value="dendrila_simple_edit_wc_mailing_settings">';
        echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_woo_optin" value="1" '.
            checked($settings['enabled'],true,false).'> Proposer une inscription facultative lors de la commande</label>';
        echo '<p><label>Liste à rejoindre <select name="dendrila_simple_edit_woo_list"><option value="0">Aucune — désactiver</option>';
        foreach (self::lists() as $list) {
            echo '<option value="'.(int)$list->id.'" '.selected($settings['list_id'],(int)$list->id,false).'>' .
                esc_html($list->name).'</option>';
        }
        echo '</select></label></p><p><button type="submit" class="button button-primary">Enregistrer la synchronisation</button></p></form>';
        echo '<p class="description">' . esc_html__('Uniquement les commandes payées et consenties. Les courriels transactionnels restent gérés par WooCommerce.', 'dendrila-simple-edit') . '</p></section>';
    }

    public static function register_checkout_block()
    {
        if (!self::enabled() || !function_exists('woocommerce_register_additional_checkout_field')) { return; }
        woocommerce_register_additional_checkout_field(array(
            'id'=>self::FIELD, 'label'=>__('Je souhaite recevoir la newsletter du site (confirmation par e-mail).', 'dendrila-simple-edit'),
            'location'=>'order', 'type'=>'checkbox', 'required'=>false,
        ));
    }

    public static function classic_checkbox()
    {
        if (!self::enabled()) { return; }
        wp_nonce_field('dendrila_simple_edit_wc_optin', 'dendrila_simple_edit_optin_nonce', false);
        echo '<p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox">' .
            '<input type="checkbox" name="dendrila_simple_edit_wc_optin" value="1"> ' .
            esc_html__('Je souhaite recevoir la newsletter du site (confirmation par e-mail).', 'dendrila-simple-edit') .
            '</label></p>';
    }

    public static function classic_save($order, $data)
    {
        if (!self::enabled()) { return; }
        if (!isset($_POST['dendrila_simple_edit_optin_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_optin_nonce'])), 'dendrila_simple_edit_wc_optin')) {
            return;
        }
        if (isset($_POST['dendrila_simple_edit_wc_optin']) &&
            sanitize_text_field(wp_unslash($_POST['dendrila_simple_edit_wc_optin'])) === '1') {
            $order->update_meta_data('_dendrila_simple_edit_wc_optin','1');
        }
    }

    /** Called on paid states only; deduplicated across processing -> completed. */
    public static function order_paid($order_id)
    {
        if (!self::enabled() || !Dendrila_Simple_Edit_Suppressions::ready() || !function_exists('wc_get_order')) { return; }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_dendrila_simple_edit_wc_optin_processed',true)) { return; }
        $allowed = $order->get_meta('_dendrila_simple_edit_wc_optin',true) === '1';
        if (!$allowed && class_exists('Automattic\WooCommerce\Blocks\Package') &&
            class_exists('Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields')) {
            try {
                $service = \Automattic\WooCommerce\Blocks\Package::container()->get(
                    \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class);
                $allowed = $service->get_field_from_object(self::FIELD,$order,'other') === true;
            } catch (Throwable $ignore) { $allowed = false; }
        }
        if (!$allowed) { return; }
        $email = strtolower(sanitize_email($order->get_billing_email()));
        if (!is_email($email)) { return; }
        $settings = self::settings();
        if (Dendrila_Simple_Edit_Mailing::request_checkout_confirmation($email, $settings['list_id'])) {
            $order->update_meta_data('_dendrila_simple_edit_wc_optin_processed', '1');
            $order->save();
        }
    }
}
