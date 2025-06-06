<?php
/**
 * Plugin Name:       Everydaymoney Payment Gateway
 * Plugin URI:        https://everydaymoney.app.com/integrations
 * Description:       Integrates Everydaymoney Payment Gateway with WooCommerce for basic payments.
 * Version:           1.0.1
 * Author:            EverydayMoney SAS
 * Author URI:        https://everydaymoney.app
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * WC requires at least: 3.5
 * WC tested up to:   8.9
 * Text Domain:       everydaymoney-gateway
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'EVERYDAYMONEY_GATEWAY_PLUGIN_FILE', __FILE__ );
define( 'EVERYDAYMONEY_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVERYDAYMONEY_GATEWAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EVERYDAYMONEY_GATEWAY_VERSION', '1.0.1' );
define( 'EVERYDAYMONEY_GATEWAY_API_URL', 'https://em-api-prod.everydaymoney.app' );
define( 'EVERYDAYMONEY_GATEWAY_TEST_API_URL', 'https://em-api-staging.everydaymoney.app' );

/**
 * Main initialization function. Hooks the gateway into WooCommerce.
 */
function everydaymoney_gateway_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'everydaymoney_gateway_missing_woocommerce_notice' );
        return;
    }

    // Add the gateway to WooCommerce using a robust method
    add_filter( 'woocommerce_payment_gateways', 'everydaymoney_add_gateway_class' );

    // Add plugin action links and other general hooks
    add_filter( 'plugin_action_links_' . plugin_basename( EVERYDAYMONEY_GATEWAY_PLUGIN_FILE ), 'everydaymoney_gateway_add_settings_link_to_plugins' );
    add_action( 'admin_enqueue_scripts', 'everydaymoney_gateway_enqueue_admin_assets' );
    load_plugin_textdomain( 'everydaymoney-gateway', false, dirname( plugin_basename( EVERYDAYMONEY_GATEWAY_PLUGIN_FILE ) ) . '/languages/' );

    // Declare High-Performance Order Storage (HPOS) compatibility
    add_action( 'before_woocommerce_init', function() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EVERYDAYMONEY_GATEWAY_PLUGIN_FILE, true );
        }
    } );

    // *** NEW: REGISTER THE BLOCKS INTEGRATION ***
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry' ) ) {
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $includes_dir = EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'includes/';
                $integration_file = $includes_dir . 'class-wc-everydaymoney-blocks-integration.php';
                if ( file_exists( $integration_file ) ) {
                    require_once $integration_file;
                    $payment_method_registry->register(
                        new WC_Everydaymoney_Blocks_Integration()
                    );
                }
            }
        );
    }

    // Register the AJAX handler for the API test connection button
    add_action( 'wp_ajax_everydaymoney_test_connection', 'everydaymoney_ajax_test_connection' );
}
add_action( 'plugins_loaded', 'everydaymoney_gateway_init' );

/**
 * Includes the necessary class files and adds the gateway to WooCommerce's list.
 * This function is hooked into `woocommerce_payment_gateways` to ensure it runs at the correct time.
 *
 * @param array $gateways The array of existing gateway classes.
 * @return array The modified array of gateway classes.
 */
function everydaymoney_add_gateway_class( $gateways ) {
    // Ensure the includes directory exists before trying to include files from it.
    $includes_dir = EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'includes/';
    if ( ! is_dir( $includes_dir ) ) {
        return $gateways;
    }

    // Include all necessary class files right before they are needed.
    $logger_file = $includes_dir . 'class-wc-everydaymoney-logger.php';
    if ( file_exists( $logger_file ) ) {
        require_once $logger_file;
    }

    $api_file = $includes_dir . 'class-wc-everydaymoney-api.php';
    if ( file_exists( $api_file ) ) {
        require_once $api_file;
    }

    $gateway_file = EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'class-wc-everydaymoney-gateway.php';
    if ( file_exists( $gateway_file ) ) {
        require_once $gateway_file;
    }

    if ( class_exists( 'WC_Everydaymoney_Gateway' ) ) {
        $gateways[] = 'WC_Everydaymoney_Gateway';
    }

    return $gateways;
}

/**
 * Plugin activation hook. Creates the custom transactions table.
 */
function everydaymoney_gateway_activate() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'everydaymoney_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            transaction_id varchar(255) DEFAULT '' NOT NULL,
            transaction_ref varchar(255) DEFAULT '' NOT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            amount decimal(19,4) NOT NULL,
            currency varchar(10) NOT NULL,
            webhook_data longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            UNIQUE KEY unique_transaction_id (transaction_id(191))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
register_activation_hook( EVERYDAYMONEY_GATEWAY_PLUGIN_FILE, 'everydaymoney_gateway_activate' );

/**
 * Admin notice if WooCommerce is not active.
 */
function everydaymoney_gateway_missing_woocommerce_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e( 'Everydaymoney Payment Gateway requires WooCommerce to be activated to function. Please install and activate WooCommerce.', 'everydaymoney-gateway' ); ?></p>
    </div>
    <?php
}

/**
 * Add a settings link on the plugin's entry in the plugins page.
 *
 * @param array $links The existing plugin action links.
 * @return array The modified plugin action links.
 */
function everydaymoney_gateway_add_settings_link_to_plugins( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=everydaymoney_gateway' ) ) . '">' . esc_html__( 'Settings', 'everydaymoney-gateway' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Enqueue admin scripts and styles for the settings page.
 *
 * @param string $hook_suffix The current admin page.
 */
function everydaymoney_gateway_enqueue_admin_assets( $hook_suffix ) {
    if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
        return;
    }
    $current_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
    if ( 'everydaymoney_gateway' !== $current_section ) {
        return;
    }

    wp_enqueue_script(
        'everydaymoney-gateway-admin-script',
        EVERYDAYMONEY_GATEWAY_PLUGIN_URL . 'assets/js/everydaymoney-admin.js',
        array( 'jquery' ),
        EVERYDAYMONEY_GATEWAY_VERSION,
        true
    );
    wp_localize_script(
        'everydaymoney-gateway-admin-script',
        'everydaymoney_admin_params',
        array(
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'everydaymoney_test_connection_nonce' ),
            'testing_message' => __( 'Testing connection...', 'everydaymoney-gateway' ),
            'success_message' => __( 'Connection successful!', 'everydaymoney-gateway' ),
            'failure_message' => __( 'Connection failed. Error: ', 'everydaymoney-gateway' ),
        )
    );
}

/**
 * AJAX handler for testing API connection.
 */
function everydaymoney_ajax_test_connection() {
    check_ajax_referer( 'everydaymoney_test_connection_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'everydaymoney-gateway' ) ), 403 );
        return;
    }

    // Ensure gateways are loaded
    $payment_gateways = WC()->payment_gateways();
    if ( ! $payment_gateways ) {
        wp_send_json_error( array( 'message' => __( 'Could not load payment gateways.', 'everydaymoney-gateway' ) ), 500 );
        return;
    }

    $gateways = $payment_gateways->payment_gateways();
    if ( ! isset( $gateways['everydaymoney_gateway'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found or initialized. Please save your settings first.', 'everydaymoney-gateway' ) ), 500 );
        return;
    }

    $gateway_instance = $gateways['everydaymoney_gateway'];

    // The API handler should be initialized in the gateway's constructor.
    if ( ! isset( $gateway_instance->api_handler ) || ! method_exists( $gateway_instance->api_handler, 'test_connection' ) ) {
        wp_send_json_error( array( 'message' => __( 'API Handler could not be initialized for test. Please ensure all plugin files are intact.', 'everydaymoney-gateway' ) ), 500 );
        return;
    }

    $result = $gateway_instance->api_handler->test_connection();

    if ( true === $result ) {
        wp_send_json_success( array( 'message' => __( 'Connection successful!', 'everydaymoney-gateway' ) ) );
    } elseif ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    } else {
        wp_send_json_error( array( 'message' => __( 'Connection test failed with an unknown error.', 'everydaymoney-gateway' ) ), 500 );
    }
}

// The debug error_log on 'init' has been removed as it's no longer needed with the improved loading structure.
?>