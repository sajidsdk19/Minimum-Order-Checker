<?php
/**
 * Plugin Name: Minimum Order Fee - Free Delivery Above 25 OMR
 * Plugin URI: https://sajidkhan.me
 * Description: Adds a fee for orders below 25 OMR and provides free delivery above 25 OMR regardless of location.
 * Version: 2.0.0
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
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

class MinimumOrderFeeSimplified {
    
    private $plugin_name = 'minimum-order-fee';
    private $version = '2.0.0';
    
    public function __construct() {
        $this->init();
    }
    
    public function init() {
        // Plugin activation/deactivation hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        
        // Initialize plugin
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'init_hooks' ) );
        
        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
        
        // Admin hooks
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'admin_init' ) );
        }
    }
    
    /**
     * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 
                'custom_order_tables', 
                __FILE__, 
                true 
            );
        }
    }
    
    public function init_hooks() {
        // Main functionality hooks
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_minimum_order_fee' ) );
        add_action( 'woocommerce_before_cart', array( $this, 'display_order_status_notice' ) );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'display_order_status_notice' ) );
        
        // Free delivery for orders above minimum - HIGH PRIORITY to override other shipping
        add_filter( 'woocommerce_package_rates', array( $this, 'apply_free_delivery' ), 999, 2 );
        
        // Also block any other plugins from adding shipping costs above minimum
        add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'force_free_shipping_above_minimum' ), 999, 3 );
        
        // Optional product page notice
        if ( get_option( 'mof_show_product_notice', 'no' ) === 'yes' ) {
            add_action( 'woocommerce_single_product_summary', array( $this, 'display_minimum_order_info' ), 25 );
        }
        
        // Add checkout script to handle city selection
        add_action( 'wp_footer', array( $this, 'checkout_city_override_script' ) );

        // --- Thawani / Payment Gateway Compatibility ---
        // Ensure the delivery fee is correctly saved onto the WC_Order so payment
        // gateways (like Thawani) that read the order total include the fee.
        add_action( 'woocommerce_checkout_create_order', array( $this, 'ensure_fee_on_order' ), 10, 2 );
    }
    
    public function activate() {
        // Set default options
        $defaults = array(
            'mof_minimum_amount' => '25',
            'mof_fee_amount' => '2',
            'mof_fee_label' => 'Small Order Fee',
            'mof_currency_symbol' => 'OMR',
            'mof_notice_message' => 'Add {remaining} {currency} more to get FREE DELIVERY! (Minimum for free delivery: {minimum} {currency})',
            'mof_success_message' => '🎉 Congratulations! Your order qualifies for FREE DELIVERY since it is above {minimum} {currency}.',
            'mof_product_notice' => 'Orders below {minimum} {currency} will have a {fee} {currency} small order fee added. Orders above {minimum} {currency} get FREE DELIVERY!',
            'mof_show_product_notice' => 'no',
            'mof_enabled' => 'yes'
        );
        
        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
        
        // Clean up old city shipping options if they exist
        $this->cleanup_old_options();
    }
    
    /**
     * Clean up old city shipping related options and database table
     */
    private function cleanup_old_options() {
        // Remove old options
        delete_option( 'mof_city_shipping_enabled' );
        delete_option( 'mof_city_shipping_rates' );
        
        // Drop old city shipping table if it exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'mof_city_shipping';
        $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    public function load_textdomain() {
        load_plugin_textdomain( 'minimum-order-fee', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
    
    /**
     * Add minimum order fee to cart (only for orders below minimum)
     */
    public function add_minimum_order_fee() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        
        // Check if feature is enabled
        if ( get_option( 'mof_enabled', 'yes' ) !== 'yes' ) {
            return;
        }
        
        // Get settings
        $minimum_amount = floatval( get_option( 'mof_minimum_amount', 25 ) );
        $fee_amount     = floatval( get_option( 'mof_fee_amount', 2 ) );
        $fee_label      = get_option( 'mof_fee_label', 'Small Order Fee' );
        
        // Get cart total (excluding fees)
        $cart_total = WC()->cart->get_subtotal();
        
        // Check if cart total is below minimum
        if ( $cart_total < $minimum_amount && $cart_total > 0 ) {
            WC()->cart->add_fee( $fee_label, $fee_amount );
        }
    }

    /**
     * Ensure the minimum order fee is explicitly saved on the WC_Order object.
     *
     * Some payment gateways (e.g. Thawani) read the order total directly from
     * the WC_Order rather than from the live cart, so any fee added only via
     * WC()->cart->add_fee() can be missed. This hook re-adds the fee as an
     * order fee item and recalculates the order total before it is saved,
     * guaranteeing the correct amount is charged.
     *
     * @param WC_Order $order   The order being created.
     * @param array    $data    Posted checkout data.
     */
    public function ensure_fee_on_order( $order, $data ) {
        if ( get_option( 'mof_enabled', 'yes' ) !== 'yes' ) {
            return;
        }

        $minimum_amount = floatval( get_option( 'mof_minimum_amount', 25 ) );
        $fee_amount     = floatval( get_option( 'mof_fee_amount', 2 ) );
        $fee_label      = get_option( 'mof_fee_label', 'Small Order Fee' );

        // Use the cart subtotal (products only, no fees) to decide.
        $cart_subtotal = WC()->cart->get_subtotal();

        if ( $cart_subtotal <= 0 || $cart_subtotal >= $minimum_amount ) {
            // Nothing to do – either free delivery applies or cart is empty.
            return;
        }

        // Check whether WooCommerce already added this fee to the order via the
        // normal cart-to-order flow.  If the fee is already there with the
        // correct amount we do nothing; otherwise we add / correct it.
        $fee_already_present = false;
        foreach ( $order->get_fees() as $fee_item ) {
            if ( $fee_item->get_name() === $fee_label ) {
                // Fee exists – make sure the amount is correct.
                if ( abs( floatval( $fee_item->get_total() ) - $fee_amount ) > 0.001 ) {
                    $fee_item->set_total( $fee_amount );
                    $fee_item->set_total_tax( 0 );
                }
                $fee_already_present = true;
                break;
            }
        }

        if ( ! $fee_already_present ) {
            // Add the fee manually so the order total is correct.
            $item = new WC_Order_Item_Fee();
            $item->set_name( $fee_label );
            $item->set_amount( $fee_amount );
            $item->set_total( $fee_amount );
            $item->set_total_tax( 0 );
            $order->add_item( $item );
        }

        // Recalculate totals so the order grand total reflects the fee.
        $order->calculate_totals();
    }
    
    /**
     * Apply free delivery for orders above minimum amount
     * This overrides ALL other shipping methods including city-based charges
     */
    public function apply_free_delivery( $rates, $package ) {
        $cart_total = WC()->cart->get_subtotal();
        $minimum_amount = floatval( get_option( 'mof_minimum_amount', 25 ) );
        
        // If cart total is above minimum, provide free shipping REGARDLESS of location
        if ( $cart_total >= $minimum_amount ) {
            // FORCE remove ALL existing shipping methods (including any city-based charges)
            $rates = array();
            
            // Add our free delivery method with highest priority
            $rates['free_delivery'] = new WC_Shipping_Rate(
                'free_delivery',
                __( 'Free Delivery (Above 25 OMR)', 'minimum-order-fee' ),
                0,
                array(),
                'free_delivery'
            );
        }
        
        return $rates;
    }
    
    /**
     * Display appropriate notice based on order status
     */
    public function display_order_status_notice() {
        if ( get_option( 'mof_enabled', 'yes' ) !== 'yes' ) {
            return;
        }
        
        $cart_total     = WC()->cart->get_subtotal();
        $minimum_amount = floatval( get_option( 'mof_minimum_amount', 25 ) );
        
        if ( $cart_total >= $minimum_amount && $cart_total > 0 ) {
            // Show success message for free delivery
            $currency = get_option( 'mof_currency_symbol', 'OMR' );
            $message_template = get_option( 'mof_success_message', '🎉 Congratulations! Your order qualifies for FREE DELIVERY since it is above {minimum} {currency}.' );
            
            $message = str_replace(
                array( '{minimum}', '{currency}' ),
                array( number_format( $minimum_amount, 2 ), $currency ),
                $message_template
            );
            
            wc_print_notice( $message, 'success' );
            
        } elseif ( $cart_total < $minimum_amount && $cart_total > 0 ) {
            // Show notice about how much more needed for free delivery
            $remaining       = $minimum_amount - $cart_total;
            $fee_amount      = floatval( get_option( 'mof_fee_amount', 2 ) );
            $currency        = get_option( 'mof_currency_symbol', 'OMR' );
            $message_template = get_option( 'mof_notice_message', 'Add {remaining} {currency} more to get FREE DELIVERY! (Minimum for free delivery: {minimum} {currency})' );
            
            $message = str_replace(
                array( '{remaining}', '{currency}', '{fee}', '{minimum}' ),
                array( number_format( $remaining, 2 ), $currency, number_format( $fee_amount, 2 ), number_format( $minimum_amount, 2 ) ),
                $message_template
            );
            
            wc_print_notice( $message, 'notice' );
        }
    }
    
    /**
     * Force free shipping to be available for orders above minimum
     * This prevents other plugins from blocking free shipping
     */
    public function force_free_shipping_above_minimum( $is_available, $package, $shipping_method ) {
        $cart_total = WC()->cart->get_subtotal();
        $minimum_amount = floatval( get_option( 'mof_minimum_amount', 25 ) );
        
        if ( $cart_total >= $minimum_amount ) {
            return true; // Force free shipping to be available
        }
        
        return $is_available;
    }
    
    /**
     * Add JavaScript to handle city selection and ensure free delivery message
     * Specifically handles Select2 dropdowns with select2-selection__rendered class
     */
    public function checkout_city_override_script() {
        if ( ! is_checkout() ) {
            return;
        }
        
        $cart_total = WC()->cart->get_subtotal();
        $minimum_amount = floatval( get_option( 'mof_minimum_amount', 25 ) );
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var cartTotal = <?php echo floatval( $cart_total ); ?>;
            var minimumAmount = <?php echo floatval( $minimum_amount ); ?>;
            
            // Function to show free delivery message
            function showFreeDeliveryMessage() {
                // Remove any existing free delivery notices
                $('.free-delivery-override-notice').remove();
                
                if (cartTotal >= minimumAmount) {
                    // Add prominent free delivery notice
                    var notice = '<div class="woocommerce-message free-delivery-override-notice" style="background: #d4edda; border-color: #c3e6cb; color: #155724; margin: 10px 0; padding: 15px; border-radius: 4px;">' +
                                '<strong>🎉 FREE DELIVERY ACTIVE:</strong> Your order is above ' + minimumAmount + ' OMR - delivery is completely FREE regardless of your selected city!' +
                                '</div>';
                    
                    // Add notice before shipping methods
                    if ($('.woocommerce-shipping-methods').length > 0) {
                        $('.woocommerce-shipping-methods').before(notice);
                    } else if ($('#shipping_method').length > 0) {
                        $('#shipping_method').before(notice);
                    } else {
                        $('.woocommerce-checkout-review-order').prepend(notice);
                    }
                }
            }
            
            // Function to wait for Select2 city selection before calculating shipping
            function waitForCitySelection() {
                // Check if Select2 is rendered and has a value
                var citySelectRendered = $('.select2-selection__rendered');
                
                if (citySelectRendered.length > 0) {
                    var hasSelectedCity = false;
                    
                    citySelectRendered.each(function() {
                        var text = $(this).text().trim();
                        if (text && text !== '' && text !== 'Choose City' && text !== 'Select City') {
                            hasSelectedCity = true;
                        }
                    });
                    
                    if (hasSelectedCity && cartTotal >= minimumAmount) {
                        // City is selected and order qualifies for free delivery
                        console.log('City selected, forcing free delivery calculation...');
                        $('body').trigger('update_checkout');
                        showFreeDeliveryMessage();
                    }
                }
            }
            
            // Wait for Select2 to be fully initialized
            function initSelect2Monitoring() {
                if ($('.select2-selection__rendered').length > 0) {
                    console.log('Select2 city selector found, monitoring changes...');
                    
                    // Monitor Select2 changes
                    $(document).on('select2:select', function(e) {
                        console.log('Select2 city changed, updating checkout...');
                        setTimeout(function() {
                            waitForCitySelection();
                        }, 300);
                    });
                    
                    // Also monitor standard change events
                    $(document).on('change', 'select[name*="city"], select[name*="state"]', function() {
                        console.log('City field changed, updating checkout...');
                        setTimeout(function() {
                            waitForCitySelection();
                        }, 300);
                    });
                    
                    // Initial check
                    waitForCitySelection();
                } else {
                    // Retry after a delay if Select2 not ready yet
                    setTimeout(initSelect2Monitoring, 500);
                }
            }
            
            // Start monitoring
            initSelect2Monitoring();
            
            // Show message immediately if above minimum
            if (cartTotal >= minimumAmount) {
                showFreeDeliveryMessage();
            }
            
            // Show message after checkout updates
            $(document.body).on('updated_checkout', function() {
                setTimeout(function() {
                    if (cartTotal >= minimumAmount) {
                        showFreeDeliveryMessage();
                    }
                }, 500);
            });
            
            // Monitor cart updates that might change the total
            $(document.body).on('updated_cart_totals', function() {
                // Refresh cart total from the page
                var newTotal = parseFloat($('.cart-subtotal .amount').text().replace(/[^\d.]/g, ''));
                if (!isNaN(newTotal)) {
                    cartTotal = newTotal;
                }
                
                setTimeout(function() {
                    if (cartTotal >= minimumAmount) {
                        showFreeDeliveryMessage();
                        waitForCitySelection();
                    }
                }, 300);
            });
        });
        </script>
        <?php
    }

    /**
     * Display minimum order info on product pages
     */
    public function display_minimum_order_info() {
        $minimum_amount   = floatval( get_option( 'mof_minimum_amount', 25 ) );
        $fee_amount       = floatval( get_option( 'mof_fee_amount', 2 ) );
        $currency         = get_option( 'mof_currency_symbol', 'OMR' );
        $message_template = get_option( 'mof_product_notice', 'Orders below {minimum} {currency} will have a {fee} {currency} small order fee added. Orders above {minimum} {currency} get FREE DELIVERY!' );
        
        $message = str_replace(
            array( '{minimum}', '{currency}', '{fee}' ),
            array( number_format( $minimum_amount, 2 ), $currency, number_format( $fee_amount, 2 ) ),
            $message_template
        );
        
        echo '<div class="minimum-order-info" style="margin: 10px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px;">';
        echo '<p style="margin: 0; color: #555;"><strong>' . esc_html__( 'Delivery Info:', 'minimum-order-fee' ) . '</strong> ' . esc_html( $message ) . '</p>';
        echo '</div>';
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Minimum Order Fee', 'minimum-order-fee' ),
            __( 'Minimum Order Fee', 'minimum-order-fee' ),
            'manage_woocommerce',
            'minimum-order-fee',
            array( $this, 'admin_page' )
        );
    }
    
    /**
     * Initialize admin settings
     */
    public function admin_init() {
        register_setting( 'mof_settings', 'mof_enabled' );
        register_setting( 'mof_settings', 'mof_minimum_amount' );
        register_setting( 'mof_settings', 'mof_fee_amount' );
        register_setting( 'mof_settings', 'mof_fee_label' );
        register_setting( 'mof_settings', 'mof_currency_symbol' );
        register_setting( 'mof_settings', 'mof_notice_message' );
        register_setting( 'mof_settings', 'mof_success_message' );
        register_setting( 'mof_settings', 'mof_product_notice' );
        register_setting( 'mof_settings', 'mof_show_product_notice' );
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Minimum Order Fee Settings', 'minimum-order-fee' ); ?></h1>
            
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'mof_settings' ); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Plugin', 'minimum-order-fee' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mof_enabled" value="yes" <?php checked( get_option( 'mof_enabled', 'yes' ), 'yes' ); ?> />
                                <?php esc_html_e( 'Enable minimum order fee and free delivery system', 'minimum-order-fee' ); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Minimum Order Amount', 'minimum-order-fee' ); ?></th>
                        <td>
                            <input type="number" name="mof_minimum_amount" value="<?php echo esc_attr( get_option( 'mof_minimum_amount', '25' ) ); ?>" min="0" step="0.01" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Orders below this amount will have a fee added. Orders above this amount get FREE DELIVERY.', 'minimum-order-fee' ); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Fee Amount', 'minimum-order-fee' ); ?></th>
                        <td>
                            <input type="number" name="mof_fee_amount" value="<?php echo esc_attr( get_option( 'mof_fee_amount', '2' ) ); ?>" min="0" step="0.01" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Amount to charge for small orders (below minimum).', 'minimum-order-fee' ); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Fee Label', 'minimum-order-fee' ); ?></th>
                        <td>
                            <input type="text" name="mof_fee_label" value="<?php echo esc_attr( get_option( 'mof_fee_label', 'Small Order Fee' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Label shown for the fee in cart and checkout.', 'minimum-order-fee' ); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Currency Symbol', 'minimum-order-fee' ); ?></th>
                        <td>
                            <input type="text" name="mof_currency_symbol" value="<?php echo esc_attr( get_option( 'mof_currency_symbol', 'OMR' ) ); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Currency symbol or code to display in notices.', 'minimum-order-fee' ); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Below Minimum Notice', 'minimum-order-fee' ); ?></th>
                        <td>
                            <textarea name="mof_notice_message" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'mof_notice_message', 'Add {remaining} {currency} more to get FREE DELIVERY! (Minimum for free delivery: {minimum} {currency})' ) ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Message shown when cart is below minimum. Available placeholders:', 'minimum-order-fee' ); ?>
                                <code>{remaining}</code>, <code>{currency}</code>, <code>{fee}</code>, <code>{minimum}</code>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Free Delivery Success Message', 'minimum-order-fee' ); ?></th>
                        <td>
                            <textarea name="mof_success_message" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'mof_success_message', '🎉 Congratulations! Your order qualifies for FREE DELIVERY since it is above {minimum} {currency}.' ) ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Message shown when cart qualifies for free delivery. Available placeholders:', 'minimum-order-fee' ); ?>
                                <code>{minimum}</code>, <code>{currency}</code>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Show Product Page Notice', 'minimum-order-fee' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mof_show_product_notice" value="yes" <?php checked( get_option( 'mof_show_product_notice', 'no' ), 'yes' ); ?> />
                                <?php esc_html_e( 'Show delivery information on product pages', 'minimum-order-fee' ); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Product Page Notice', 'minimum-order-fee' ); ?></th>
                        <td>
                            <textarea name="mof_product_notice" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'mof_product_notice', 'Orders below {minimum} {currency} will have a {fee} {currency} small order fee added. Orders above {minimum} {currency} get FREE DELIVERY!' ) ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Message shown on product pages. Available placeholders:', 'minimum-order-fee' ); ?>
                                <code>{minimum}</code>, <code>{currency}</code>, <code>{fee}</code>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card" style="max-width: 600px; margin-top: 20px;">
                <h2 class="title"><?php esc_html_e( 'How It Works', 'minimum-order-fee' ); ?></h2>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><?php esc_html_e( 'Orders below the minimum amount get a small order fee', 'minimum-order-fee' ); ?></li>
                    <li><?php esc_html_e( 'Orders above the minimum amount get FREE DELIVERY (regardless of location)', 'minimum-order-fee' ); ?></li>
                    <li><?php esc_html_e( 'Clear notices guide customers to reach free delivery threshold', 'minimum-order-fee' ); ?></li>
                    <li><?php esc_html_e( 'Automatic congratulations message when free delivery is achieved', 'minimum-order-fee' ); ?></li>
                </ul>
                <p><strong><?php esc_html_e( 'Version:', 'minimum-order-fee' ); ?></strong> <?php echo esc_html( $this->version ); ?></p>
                <p><strong><?php esc_html_e( 'HPOS Compatible:', 'minimum-order-fee' ); ?></strong> <?php esc_html_e( 'Yes', 'minimum-order-fee' ); ?></p>
            </div>
        </div>
        <?php
    }
}

// Initialize the plugin
new MinimumOrderFeeSimplified();