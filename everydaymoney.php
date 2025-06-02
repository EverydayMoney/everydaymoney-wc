<?php
/**
 * Plugin Name:       Everydaymoney Payment Gateway
 * Plugin URI:        https://yourwebsite.com/everydaymoney-gateway
 * Description:       Integrates Everydaymoney Payment Gateway with WooCommerce.
 * Version:           1.0.1
 * Author:            Your Name/Company
 * Author URI:        https://yourwebsite.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * WC requires at least: 3.5
 * WC tested up to:   (Current WooCommerce Version, e.g., 8.9)
 * Text Domain:       everydaymoney-gateway
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Ensure WooCommerce is active
if ( ! class_exists( 'WooCommerce' ) ) {
    add_action( 'admin_notices', 'everydaymoney_woocommerce_not_active_notice' );
    return;
}

function everydaymoney_woocommerce_not_active_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e( 'Everydaymoney Payment Gateway requires WooCommerce to be activated to function. Please install and activate WooCommerce.', 'everydaymoney-gateway' ); ?></p>
    </div>
    <?php
}

define( 'EVERYDAYMONEY_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVERYDAYMONEY_GATEWAY_VERSION', '1.0.1' );
// Updated API Base URL
define( 'EVERYDAYMONEY_GATEWAY_API_URL', 'https://em-api-prod.everydaymoney.app' );


/**
 * Declare High-Performance Order Storage (HPOS) compatibility.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );


/**
 * Add the Gateway to WooCommerce
 **/
function wc_everydaymoney_add_gateway( $gateways ) {
    $gateways[] = 'WC_Everydaymoney_Gateway'; // This should match the class name in class-wc-everydaymoney-gateway.php
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_everydaymoney_add_gateway' );

/**
 * Initialize the gateway.
 */
function wc_everydaymoney_gateway_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }
    // Ensure the class file is loaded
    if ( ! class_exists( 'WC_Everydaymoney_Gateway' ) ) {
        require_once EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'class-wc-everydaymoney-gateway.php';
    }
}
add_action( 'plugins_loaded', 'wc_everydaymoney_gateway_init', 11 );

/**
 * Add settings link on plugin page.
 */
function wc_everydaymoney_gateway_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=everydaymoney_gateway' ) . '">' . __( 'Settings', 'everydaymoney-gateway' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_everydaymoney_gateway_settings_link' );


/**
 * Enqueue admin scripts and styles (Optional).
 * Uncomment and customize if you have admin-specific JS/CSS.
 */
/*
function everydaymoney_gateway_admin_scripts_and_styles($hook_suffix) {
    // Only load on our specific admin page for WooCommerce settings
    if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
        return;
    }

    // Check if it's our gateway's section (ID is 'everydaymoney_gateway')
    if ( !isset($_GET['tab']) || $_GET['tab'] !== 'checkout' || !isset($_GET['section']) || $_GET['section'] !== 'everydaymoney_gateway' ) {
        return;
    }

    // Example: Enqueue admin CSS
    wp_enqueue_style(
        'everydaymoney-gateway-admin-style',
        plugins_url('assets/css/everydaymoney-admin.css', __FILE__),
        array(),
        EVERYDAYMONEY_GATEWAY_VERSION
    );

    // Example: Enqueue admin JS
    wp_enqueue_script(
        'everydaymoney-gateway-admin-script',
        plugins_url('assets/js/everydaymoney-admin.js', __FILE__),
        array('jquery'),
        EVERYDAYMONEY_GATEWAY_VERSION,
        true // Load in footer
    );
}
add_action( 'admin_enqueue_scripts', 'everydaymoney_gateway_admin_scripts_and_styles' );
*/

/**
 * Load plugin textdomain for translations.
 */
function everydaymoney_gateway_load_textdomain() {
    load_plugin_textdomain( 'everydaymoney-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'everydaymoney_gateway_load_textdomain' );

?>