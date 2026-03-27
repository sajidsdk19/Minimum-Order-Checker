<?php
/**
 * Plugin Name: Minimum Order Fee
 * Plugin URI: https://sajidkhan.me
 * Description: Adds a configurable fee for orders below a minimum amount and applies free shipping with a congratulatory message when above the minimum amount.
 * Version: 1.2.0
 * Author: Sajid Khan
 * Author URI: https://sajidkhan.me
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: minimum-order-fee
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Prevent direct access
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
    private $version = '1.2.0';

    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize plugin
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_hooks'));

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'admin_init'));
        }
    }

    /**
     * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
     */
    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    public function init_hooks()
    {
        // Main functionality hooks
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_minimum_order_fee'));
        add_action('woocommerce_before_cart', array($this, 'minimum_order_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'minimum_order_notice'));

        // NOTE: WooCommerce automatically persists cart fees (added via WC()->cart->add_fee())
        // into the placed order. No extra hook is needed — adding one causes fee duplication.

        // Free shipping + congrats message
        add_filter('woocommerce_package_rates', array($this, 'maybe_apply_free_shipping'), 10, 2);
        add_action('woocommerce_before_cart', array($this, 'free_shipping_congrats'));
        add_action('woocommerce_before_checkout_form', array($this, 'free_shipping_congrats'));

        // Optional product page notice
        if (get_option('mof_show_product_notice', 'no') === 'yes') {
            add_action('woocommerce_single_product_summary', array($this, 'display_minimum_order_info'), 25);
        }

        // AJAX for admin settings
        add_action('wp_ajax_mof_test_settings', array($this, 'test_settings'));
    }

    public function activate()
    {
        // Set default options
        $defaults = array(
            'mof_minimum_amount' => '25',
            'mof_fee_amount' => '2',
            'mof_fee_label' => 'Small Order Fee',
            'mof_currency_symbol' => 'OMR',
            'mof_notice_message' => 'Add {remaining} {currency} more to avoid the {fee} {currency} small order fee. (Minimum order: {minimum} {currency})',
            'mof_product_notice' => 'Orders below {minimum} {currency} will have a {fee} {currency} small order fee added.',
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
    // Clean up if needed
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('minimum-order-fee', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Add minimum order fee to cart
     *
     * The fee is skipped when the customer selects "I'll pick it up myself"
     * (billing_delivery = Option_2). When "Deliver to address" (Option_1) is
     * selected the fee is applied as usual. The billing_delivery value is sent
     * via the WooCommerce AJAX update_order_review POST request so this works
     * dynamically without a page reload.
     */
    public function add_minimum_order_fee()
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Check if feature is enabled
        if (get_option('mof_enabled', 'yes') !== 'yes') {
            return;
        }

        // --- Delivery method check ---
        // Read billing_delivery from the AJAX post data that WooCommerce sends
        // during update_order_review. When the customer chooses pickup (Option_2)
        // we do NOT apply the small-order fee.
        $delivery_method = '';
        if (isset($_POST['post_data'])) {
            // WooCommerce sends all checkout fields URL-encoded inside post_data.
            parse_str(wp_unslash($_POST['post_data']), $post_data);
            $delivery_method = isset($post_data['billing_delivery'])
                ? sanitize_text_field($post_data['billing_delivery'])
                : '';
        }
        elseif (isset($_POST['billing_delivery'])) {
            // Fallback: field submitted directly (non-AJAX or custom integrations).
            $delivery_method = sanitize_text_field(wp_unslash($_POST['billing_delivery']));
        }

        // Skip the fee entirely for pickup orders.
        if ($delivery_method === 'Option_2') {
            return;
        }

        // Get settings
        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        $fee_amount = floatval(get_option('mof_fee_amount', 2));
        $fee_label = get_option('mof_fee_label', 'Small Order Fee');

        // Use get_cart_contents_total() instead of get_subtotal().
        // get_subtotal()            → raw product prices, BEFORE discounts/coupons.
        // get_cart_contents_total() → product total AFTER discounts, BEFORE shipping.
        // This is the correct value to compare against for fee logic:
        //   • It reflects applied coupons so the fee threshold is accurate.
        //   • It does NOT include shipping or fees, preventing circular calculations.
        $cart_total = floatval(WC()->cart->get_cart_contents_total());

        // Apply the fee only when the discounted cart total is below the minimum
        // and the customer chose delivery (Option_1 or unset).
        if ($cart_total > 0 && $cart_total < $minimum_amount) {
            WC()->cart->add_fee($fee_label, $fee_amount);
        }
    }


    /**
     * Display notice about minimum order
     */
    public function minimum_order_notice()
    {
        if (get_option('mof_enabled', 'yes') !== 'yes') {
            return;
        }

        // No notice for pickup — fee doesn't apply, so neither does the notice.
        $delivery_method = '';
        if (isset($_POST['post_data'])) {
            parse_str(wp_unslash($_POST['post_data']), $post_data);
            $delivery_method = isset($post_data['billing_delivery'])
                ? sanitize_text_field($post_data['billing_delivery'])
                : '';
        } elseif (isset($_POST['billing_delivery'])) {
            $delivery_method = sanitize_text_field(wp_unslash($_POST['billing_delivery']));
        }
        if ($delivery_method === 'Option_2') {
            return;
        }

        // Use get_cart_contents_total() so the notice reflects the discounted cart value
        $cart_total = floatval(WC()->cart->get_cart_contents_total());
        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));

        if ($cart_total < $minimum_amount && $cart_total > 0) {
            $remaining = $minimum_amount - $cart_total;
            $fee_amount = floatval(get_option('mof_fee_amount', 2));
            $currency = get_option('mof_currency_symbol', get_woocommerce_currency_symbol());
            $message_template = get_option('mof_notice_message', 'Add {remaining} {currency} more to avoid the {fee} {currency} small order fee. (Minimum order: {minimum} {currency})');

            $message = str_replace(
                array('{remaining}', '{currency}', '{fee}', '{minimum}'),
                array(number_format($remaining, 2), $currency, number_format($fee_amount, 2), number_format($minimum_amount, 2)),
                $message_template
            );

            wc_print_notice($message, 'notice');
        }
    }

    /**
     * Ensure free shipping above minimum
     */
    public function maybe_apply_free_shipping($rates, $package)
    {
        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        // Use get_cart_contents_total() so free shipping is only granted after discounts
        $cart_total = floatval(WC()->cart->get_cart_contents_total());

        if ($cart_total >= $minimum_amount) {
            foreach ($rates as $rate_id => $rate) {
                // Only keep free shipping
                if ('free_shipping' !== $rate->method_id) {
                    unset($rates[$rate_id]);
                }
            }
        }
        return $rates;
    }

    /**
     * Show congrats message when free shipping applies
     */
    public function free_shipping_congrats()
    {
        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        // Use get_cart_contents_total() so the congrats message is accurate post-discount
        $cart_total = floatval(WC()->cart->get_cart_contents_total());

        if ($cart_total >= $minimum_amount) {
            $currency = get_option('mof_currency_symbol', get_woocommerce_currency_symbol());
            $message = sprintf(
                __('🎉 Congratulations! Your order qualifies for free shipping since it is above %s %s.', 'minimum-order-fee'),
                number_format($minimum_amount, 2),
                $currency
            );

            wc_print_notice($message, 'success');
        }
    }

    /**
     * Display minimum order info on product pages
     */
    public function display_minimum_order_info()
    {
        $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
        $fee_amount = floatval(get_option('mof_fee_amount', 2));
        $currency = get_option('mof_currency_symbol', get_woocommerce_currency_symbol());
        $message_template = get_option('mof_product_notice', 'Orders below {minimum} {currency} will have a {fee} {currency} small order fee added.');

        $message = str_replace(
            array('{minimum}', '{currency}', '{fee}'),
            array(number_format($minimum_amount, 2), $currency, number_format($fee_amount, 2)),
            $message_template
        );

        echo '<div class="minimum-order-info" style="margin: 10px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px;">';
        echo '<p style="margin: 0; color: #555;"><strong>' . esc_html__('Note:', 'minimum-order-fee') . '</strong> ' . esc_html($message) . '</p>';
        echo '</div>';
    }

    /**
     * Add admin menu
     */
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

    /**
     * Initialize admin settings
     */
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

    /**
     * Admin page content
     */
    public function admin_page()
    {
?>
        <div class="wrap">
            <h1><?php esc_html_e('Minimum Order Fee Settings', 'minimum-order-fee'); ?></h1>
            
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('mof_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Fee', 'minimum-order-fee'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mof_enabled" value="yes" <?php checked(get_option('mof_enabled', 'yes'), 'yes'); ?> />
                                <?php esc_html_e('Enable minimum order fee', 'minimum-order-fee'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Minimum Order Amount', 'minimum-order-fee'); ?></th>
                        <td>
                            <input type="number" name="mof_minimum_amount" value="<?php echo esc_attr(get_option('mof_minimum_amount', '25')); ?>" min="0" step="0.01" class="regular-text" />
                            <p class="description"><?php esc_html_e('Orders below this amount will have a fee added. Orders equal or above qualify for free shipping.', 'minimum-order-fee'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Fee Amount', 'minimum-order-fee'); ?></th>
                        <td>
                            <input type="number" name="mof_fee_amount" value="<?php echo esc_attr(get_option('mof_fee_amount', '2')); ?>" min="0" step="0.01" class="regular-text" />
                            <p class="description"><?php esc_html_e('Amount to charge for small orders.', 'minimum-order-fee'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Fee Label', 'minimum-order-fee'); ?></th>
                        <td>
                            <input type="text" name="mof_fee_label" value="<?php echo esc_attr(get_option('mof_fee_label', 'Small Order Fee')); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Label shown for the fee in cart and checkout.', 'minimum-order-fee'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Currency Symbol', 'minimum-order-fee'); ?></th>
                        <td>
                            <input type="text" name="mof_currency_symbol" value="<?php echo esc_attr(get_option('mof_currency_symbol', 'OMR')); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Currency symbol or code to display in notices.', 'minimum-order-fee'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Cart Notice Message', 'minimum-order-fee'); ?></th>
                        <td>
                            <textarea name="mof_notice_message" rows="3" class="large-text"><?php echo esc_textarea(get_option('mof_notice_message', 'Add {remaining} {currency} more to avoid the {fee} {currency} small order fee. (Minimum order: {minimum} {currency})')); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Message shown in cart/checkout when below minimum. Available placeholders:', 'minimum-order-fee'); ?>
                                <code>{remaining}</code>, <code>{currency}</code>, <code>{fee}</code>, <code>{minimum}</code>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Show Product Page Notice', 'minimum-order-fee'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mof_show_product_notice" value="yes" <?php checked(get_option('mof_show_product_notice', 'no'), 'yes'); ?> />
                                <?php esc_html_e('Show minimum order notice on product pages', 'minimum-order-fee'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Product Page Notice', 'minimum-order-fee'); ?></th>
                        <td>
                            <textarea name="mof_product_notice" rows="2" class="large-text"><?php echo esc_textarea(get_option('mof_product_notice', 'Orders below {minimum} {currency} will have a {fee} {currency} small order fee added.')); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Message shown on product pages. Available placeholders:', 'minimum-order-fee'); ?>
                                <code>{minimum}</code>, <code>{currency}</code>, <code>{fee}</code>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card" style="max-width: 500px; margin-top: 20px;">
                <h2 class="title"><?php esc_html_e('Plugin Information', 'minimum-order-fee'); ?></h2>
                <p><?php esc_html_e('This plugin automatically adds a configurable fee to orders that fall below your specified minimum amount. Orders equal or above qualify for free shipping and display a congratulatory message.', 'minimum-order-fee'); ?></p>
                <p><strong><?php esc_html_e('Version:', 'minimum-order-fee'); ?></strong> <?php echo esc_html($this->version); ?></p>
                <p><strong><?php esc_html_e('HPOS Compatible:', 'minimum-order-fee'); ?></strong> <?php esc_html_e('Yes', 'minimum-order-fee'); ?></p>
            </div>
        </div>
        <?php
    }
}

// Initialize the plugin
new MinimumOrderFee();
