<?php
/**
 * Plugin Name:       Everydaymoney Payment Gateway
 * Plugin URI:        https://everydaymoney.app.com/integrations
 * Description:       Integrates Everydaymoney Payment Gateway with WooCommerce for basic payments.
 * Version:           1.1.0
 * Author:            EverydayMoney SAS
 * Author URI:        https://everydaymoney.app
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * WC requires at least: 3.5
 * WC tested up to:   (Current WooCommerce Version)
 * Text Domain:       everydaymoney-gateway
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'EVERYDAYMONEY_GATEWAY_PLUGIN_FILE', __FILE__ );
define( 'EVERYDAYMONEY_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVERYDAYMONEY_GATEWAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) ); // Added this constant
define( 'EVERYDAYMONEY_GATEWAY_VERSION', '1.1.0' );
define( 'EVERYDAYMONEY_GATEWAY_API_URL', 'http://localhost:8000' );
// Optional: Define a test API URL if it's different and you want to easily switch or use it in API class
// define( 'EVERYDAYMONEY_GATEWAY_TEST_API_URL', 'https://em-api-staging.everydaymoney.app' );


/**
 * Main function to initialize the Everydaymoney Gateway plugin.
 */
function everydaymoney_gateway_plugin_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'everydaymoney_gateway_missing_woocommerce_notice' );
        return;
    }

    add_action( 'before_woocommerce_init', function() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EVERYDAYMONEY_GATEWAY_PLUGIN_FILE, true );
        }
    } );

    // Include helper classes
    if ( ! class_exists( 'WC_Everydaymoney_Logger' ) ) {
        require_once EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'includes/class-wc-everydaymoney-logger.php';
    }
    if ( ! class_exists( 'WC_Everydaymoney_API' ) ) {
        require_once EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'includes/class-wc-everydaymoney-api.php';
    }

    // Include the gateway class file.
    if ( ! class_exists( 'WC_Everydaymoney_Gateway' ) ) {
        require_once EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'class-wc-everydaymoney-gateway.php';
    }

    add_filter( 'woocommerce_payment_gateways', 'everydaymoney_gateway_add_to_gateways_list' );
    add_filter( 'plugin_action_links_' . plugin_basename( EVERYDAYMONEY_GATEWAY_PLUGIN_FILE ), 'everydaymoney_gateway_add_settings_link_to_plugins' );
    add_action( 'admin_enqueue_scripts', 'everydaymoney_gateway_enqueue_admin_assets' );
    load_plugin_textdomain( 'everydaymoney-gateway', false, dirname( plugin_basename( EVERYDAYMONEY_GATEWAY_PLUGIN_FILE ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'everydaymoney_gateway_plugin_init', 11 );

/**
 * Plugin activation hook.
 */
function everydaymoney_gateway_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'everydaymoney_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
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
        ) $charset_collate;"; // Added index length for transaction_id for utf8mb4 compatibility

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
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
 * Add the gateway to WooCommerce's list of payment gateways.
 */
function everydaymoney_gateway_add_to_gateways_list( $gateways ) {
    if ( class_exists( 'WC_Everydaymoney_Gateway' ) ) {
        $gateways[] = 'WC_Everydaymoney_Gateway';
    }
    return $gateways;
}

/**
 * Add a settings link on the plugin's entry in the plugins page.
 */
function everydaymoney_gateway_add_settings_link_to_plugins( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=everydaymoney_gateway' ) ) . '">' . esc_html__( 'Settings', 'everydaymoney-gateway' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Enqueue admin scripts and styles.
 */
function everydaymoney_gateway_enqueue_admin_assets( $hook_suffix ) {
    if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
        return;
    }
    $current_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash($_GET['section']) ) : '';
    if ( 'everydaymoney_gateway' !== $current_section ) {
        return;
    }

    wp_enqueue_script(
        'everydaymoney-gateway-admin-script',
        EVERYDAYMONEY_GATEWAY_PLUGIN_URL . 'assets/js/everydaymoney-admin.js',
        array('jquery'),
        EVERYDAYMONEY_GATEWAY_VERSION,
        true
    );
    wp_localize_script('everydaymoney-gateway-admin-script', 'everydaymoney_admin_params', array(
        'ajax_url'        => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('everydaymoney_test_connection_nonce'),
        'testing_message' => __('Testing connection...', 'everydaymoney-gateway'),
        'success_message' => __('Connection successful!', 'everydaymoney-gateway'),
        'failure_message' => __('Connection failed. Error: ', 'everydaymoney-gateway'),
    ));
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
    
    $gateways = WC()->payment_gateways->payment_gateways();
    if ( ! isset( $gateways['everydaymoney_gateway'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found/initialized.', 'everydaymoney-gateway' ) ), 500 );
        return;
    }
    
    $gateway_instance = $gateways['everydaymoney_gateway'];
    
    if ( ! isset($gateway_instance->api_handler) || ! is_object($gateway_instance->api_handler) || ! method_exists($gateway_instance->api_handler, 'test_connection') ) {
        // Ensure API handler is loaded if not already
        if ( ! class_exists( 'WC_Everydaymoney_Logger' ) ) {
            require_once EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'includes/class-wc-everydaymoney-logger.php';
        }
        if ( ! class_exists( 'WC_Everydaymoney_API' ) ) {
            require_once EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'includes/class-wc-everydaymoney-api.php';
        }
        // Re-initialize the API handler on the gateway instance for AJAX context
        $gateway_instance->logger = new WC_Everydaymoney_Logger( $gateway_instance->debug );
        $gateway_instance->api_handler = new WC_Everydaymoney_API( $gateway_instance );

         if ( ! isset($gateway_instance->api_handler) || ! method_exists($gateway_instance->api_handler, 'test_connection') ) {
             wp_send_json_error( array( 'message' => __( 'API Handler could not be initialized for test.', 'everydaymoney-gateway' ) ), 500 );
            return;
         }
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
add_action( 'wp_ajax_everydaymoney_test_connection', 'everydaymoney_ajax_test_connection' );


add_action( 'init', function() {
    if ( is_admin() ) {
        error_log( 'Everydaymoney Debug: Plugin loaded' );
        error_log( 'WooCommerce active: ' . ( class_exists( 'WooCommerce' ) ? 'Yes' : 'No' ) );
        error_log( 'Gateway class exists: ' . ( class_exists( 'WC_Everydaymoney_Gateway' ) ? 'Yes' : 'No' ) );
        
        if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
            $gateways = WC()->payment_gateways->payment_gateways();
            error_log( 'Registered gateways: ' . print_r( array_keys( $gateways ), true ) );
        }
    }
});

?>