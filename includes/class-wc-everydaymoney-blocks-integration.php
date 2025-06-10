<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Everydaymoney Payment Gateway Blocks Integration.
 *
 * @since 1.0.1
 */
final class WC_Everydaymoney_Blocks_Integration extends AbstractPaymentMethodType {
    
    private $gateway;
    protected $name = 'everydaymoney_gateway';

    /**
     * Initializes the integration.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_everydaymoney_gateway_settings', [] );
        $gateways       = WC()->payment_gateways->get_available_payment_gateways();
        if ( isset( $gateways[ $this->name ] ) ) {
            $this->gateway = $gateways[ $this->name ];
        }
    }

    /**
     * Get payment method script handles for WooCommerce Blocks integration.
     * This method is called by WooCommerce Blocks to register the payment method.
     *
     * @return array Array of script handles.
     */
    public function get_payment_method_script_handles() {
        // Define script handle
        $script_handle = 'everydaymoney-blocks-integration';
        
        // Get the script path - adjust based on your file structure
        $script_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/checkout.js';
        
        // If this gateway class is in the root directory, use this instead:
        // $script_url = plugin_dir_url( __FILE__ ) . 'assets/js/checkout.js';
        
        // Register the script
        wp_register_script(
            $script_handle,
            $script_url,
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n'
            ),
            '1.0.2', // Update version when you make changes
            true
        );

        // Prepare data to pass to JavaScript
        $script_data = array(
            'title'             => $this->get_title(),
            'description'       => $this->get_description(),
            'icon'              => $this->get_icon(),
            'supports'          => array_keys( $this->supports ),
            'test_mode'         => $this->test_mode,
            'gateway_id'        => $this->id,
        );

        // Apply filters to allow customization
        $script_data = apply_filters( 'wc_everydaymoney_gateway_script_data', $script_data, $this );

        // Localize script with data
        wp_localize_script(
            $script_handle,
            'everydaymoney_gateway_data',
            $script_data
        );

        // Set script translations if using WordPress 5.0+
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                $script_handle,
                'everydaymoney-gateway', // Text domain
                plugin_dir_path( dirname( __FILE__ ) ) . 'languages' // Path to language files
            );
        }

        // Return array of script handles
        return array( $script_handle );
    }

    /**
     * Get payment method data for WooCommerce Blocks.
     * This method provides additional data that might be needed by the blocks integration.
     *
     * @return array Payment method data.
     */
    public function get_payment_method_data() {
        return array(
            'title'             => $this->get_title(),
            'description'       => $this->get_description(),
            'icon'              => $this->get_icon(),
            'supports'          => $this->supports,
            'test_mode'         => $this->test_mode,
            'gateway_id'        => $this->id,
            'enabled'           => $this->is_available(),
        );
    }
}