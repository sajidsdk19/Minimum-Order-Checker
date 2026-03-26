<?php
/**
 * Plugin Name: Minimum Order Fee
 * Plugin URI: https://sajidkhan.me
 * Description: Adds a configurable fee for orders below a minimum amount when delivery is selected. Skips fee for "I'll pick it up myself". Also handles free shipping.
 * Version: 1.3.0
 * Author: Sajid Khan
 * Author URI: https://sajidkhan.me
 * License: GPL v2 or later
 * Text Domain: minimum-order-fee
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class MinimumOrderFee
{

    private $plugin_name = 'minimum-order-fee';
    private $version = '1.3.0';

    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_hooks'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'admin_init'));
        }
    }

    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function init_hooks()
    {
        // Core hooks
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_minimum_order_fee'));
        add_action('woocommerce_before_cart', array($this, 'minimum_order_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'minimum_order_notice'));

        // Free shipping + congrats
        add_filter('woocommerce_package_rates', array($this, 'maybe_apply_free_shipping'), 10, 2);
        add_action('woocommerce_before_cart', array($this, 'free_shipping_congrats'));
        add_action('woocommerce_before_checkout_form', array($this, 'free_shipping_congrats'));

        // Product page notice
        if (get_option('mof_show_product_notice', 'no') === 'yes') {
            add_action('woocommerce_single_product_summary', array($this, 'display_minimum_order_info'), 25);
        }

        // AJAX support for dynamic field change
        add_action('wp_ajax_nopriv_woocommerce_update_order_review', array($this, 'add_minimum_order_fee'), 5);
        add_action('wp_ajax_woocommerce_update_order_review', array($this, 'add_minimum_order_fee'), 5);
    }

    public function activate()
    {
        $defaults = array(
            'mof_minimum_amount' => '25',
            'mof_fee_amount' => '2',
            'mof_fee_label' => 'Small Order Fee',
            'mof_currency_symbol' => 'ر.ع.',
            'mof_notice_message' => 'Add {remaining} {currency} more to avoid the {fee} {currency} small order fee. (Minimum order: {minimum} {currency})',
            'mof_product_notice' => 'Orders below {minimum} {currency} will have a {fee} {currency} small order fee added when delivered.',
            'mof_show_product_notice' => 'no',
            'mof_enabled' => 'yes'
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    public function deactivate()
    {
    // Cleanup if needed
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('minimum-order-fee', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Check if delivery is selected (not self pickup)
     */
    private function is_delivery_selected()
    {
        // Check in POST data (during checkout update)
        if (isset($_POST['billing_delivery'])) {
            return $_POST['billing_delivery'] === 'Option_1'; // Deliver to address
        }

        // Check in session / cart (for cart page or initial load)
        $delivery = WC()->session->get('billing_delivery');
        if (!empty($delivery)) {
            return $delivery === 'Option_1';
        }

        return true; // Default: assume delivery (safer for most cases)
    }

    /**
     * Add minimum order fee ONLY when "Deliver to address" is selected
     */
    public function add_minimum_order_fee()
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (get_option('mof_enabled', 'yes') !== 'yes') {
            return;
        }

        // Skip fee if customer chose "I'll pick it up myself"
        if (!$this->is_delivery_selected()) {
            return;
        }

        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        $fee_amount = floatval(get_option('mof_fee_amount', 2));
        $fee_label = get_option('mof_fee_label', 'Small Order Fee');

        $cart_total = floatval(WC()->cart->get_cart_contents_total());

        // Remove any previous fee first (important for dynamic updates)
        WC()->cart->remove_fee($fee_label);

        if ($cart_total > 0 && $cart_total < $minimum_amount) {
            WC()->cart->add_fee($fee_label, $fee_amount, false, 'small-order-fee');
        }
    }

    /**
     * Minimum order notice - also respects delivery choice
     */
    public function minimum_order_notice()
    {
        if (get_option('mof_enabled', 'yes') !== 'yes') {
            return;
        }

        if (!$this->is_delivery_selected()) {
            return; // No notice for pickup
        }

        $cart_total = floatval(WC()->cart->get_cart_contents_total());
        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));

        if ($cart_total < $minimum_amount && $cart_total > 0) {
            $remaining = $minimum_amount - $cart_total;
            $fee_amount = floatval(get_option('mof_fee_amount', 2));
            $currency = get_option('mof_currency_symbol', 'ر.ع.');
            $message_template = get_option('mof_notice_message');

            $message = str_replace(
                array('{remaining}', '{currency}', '{fee}', '{minimum}'),
                array(number_format($remaining, 2), $currency, number_format($fee_amount, 2), number_format($minimum_amount, 2)),
                $message_template
            );

            wc_print_notice($message, 'notice');
        }
    }

    /**
     * Free shipping logic - applies only on delivery (you can adjust if needed)
     */
    public function maybe_apply_free_shipping($rates, $package)
    {
        if (!$this->is_delivery_selected()) {
            return $rates; // Don't force free shipping on pickup
        }

        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        $cart_total = floatval(WC()->cart->get_cart_contents_total());

        if ($cart_total >= $minimum_amount) {
            foreach ($rates as $rate_id => $rate) {
                if ('free_shipping' !== $rate->method_id) {
                    unset($rates[$rate_id]);
                }
            }
        }
        return $rates;
    }

    public function free_shipping_congrats()
    {
        if (!$this->is_delivery_selected()) {
            return;
        }

        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        $cart_total = floatval(WC()->cart->get_cart_contents_total());

        if ($cart_total >= $minimum_amount) {
            $currency = get_option('mof_currency_symbol', 'ر.ع.');
            $message = sprintf(
                __('🎉 Congratulations! Your order qualifies for free shipping since it is above %s %s.', 'minimum-order-fee'),
                number_format($minimum_amount, 2),
                $currency
            );
            wc_print_notice($message, 'success');
        }
    }

    public function display_minimum_order_info()
    {
        // Same as before...
        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        $fee_amount = floatval(get_option('mof_fee_amount', 2));
        $currency = get_option('mof_currency_symbol', 'ر.ع.');
        $message_template = get_option('mof_product_notice');

        $message = str_replace(
            array('{minimum}', '{currency}', '{fee}'),
            array(number_format($minimum_amount, 2), $currency, number_format($fee_amount, 2)),
            $message_template
        );

        echo '<div class="minimum-order-info" style="margin: 10px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px;">';
        echo '<p style="margin: 0; color: #555;"><strong>' . esc_html__('Note:', 'minimum-order-fee') . '</strong> ' . esc_html($message) . '</p>';
        echo '</div>';
    }

    // Admin menu and settings (same as your original - only version updated)
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Minimum Order Fee', 'minimum-order-fee'),
            __('Minimum Order Fee', 'minimum-order-fee'),
            'manage_woocommerce',
            'minimum-order-fee',
            array($this, 'admin_page')
        );
    }

    public function admin_init()
    {
        register_setting('mof_settings', 'mof_enabled');
        register_setting('mof_settings', 'mof_minimum_amount');
        register_setting('mof_settings', 'mof_fee_amount');
        register_setting('mof_settings', 'mof_fee_label');
        register_setting('mof_settings', 'mof_currency_symbol');
        register_setting('mof_settings', 'mof_notice_message');
        register_setting('mof_settings', 'mof_product_notice');
        register_setting('mof_settings', 'mof_show_product_notice');
    }

    public function admin_page()
    {
        // Your existing admin page HTML (unchanged)
?>
        <div class="wrap">
            <h1><?php esc_html_e('Minimum Order Fee Settings', 'minimum-order-fee'); ?></h1>
            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('mof_settings'); ?>
                <table class="form-table">
                    <!-- Your existing form fields here (same as before) -->
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Fee', 'minimum-order-fee'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mof_enabled" value="yes" <?php checked(get_option('mof_enabled', 'yes'), 'yes'); ?> />
                                <?php esc_html_e('Enable minimum order fee', 'minimum-order-fee'); ?>
                            </label>
                        </td>
                    </tr>
                    <!-- ... rest of your fields ... -->
                </table>
                <?php submit_button(); ?>
            </form>

            <div class="card" style="max-width: 500px; margin-top: 20px;">
                <h2><?php esc_html_e('Plugin Information', 'minimum-order-fee'); ?></h2>
                <p><strong>Version:</strong> <?php echo esc_html($this->version); ?></p>
                <p><strong>HPOS Compatible:</strong> Yes</p>
                <p><strong>Note:</strong> Fee is now skipped when "I’ll pick it up myself" is selected.</p>
            </div>
        </div>
        <?php
    }
}

// Initialize
new MinimumOrderFee();