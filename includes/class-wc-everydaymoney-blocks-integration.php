<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Everydaymoney Payment Gateway Blocks Integration.
 *
 * @since 1.0.2
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
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     */
    public function is_active() {
        return ! empty( $this->gateway ) && $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     */
    public function get_payment_method_script_handles() {
        // Define script handle
        $script_handle = 'everydaymoney-blocks-integration';
        
        // Get the script path - since this file is in includes/, go up one level
        $script_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/checkout.js';
        
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
            defined( 'EVERYDAYMONEY_GATEWAY_VERSION' ) ? EVERYDAYMONEY_GATEWAY_VERSION : '1.0.2',
            true
        );

        // Prepare data to pass to JavaScript only if gateway is available
        if ( $this->gateway ) {
            $script_data = array(
                'title'             => $this->gateway->get_title(),
                'description'       => $this->gateway->get_description(),
                'icon'              => $this->gateway->icon,
                'supports'          => array_keys( $this->gateway->supports ),
                'test_mode'         => property_exists( $this->gateway, 'test_mode' ) ? $this->gateway->test_mode : false,
                'gateway_id'        => $this->gateway->id,
            );

            // Apply filters to allow customization
            $script_data = apply_filters( 'wc_everydaymoney_gateway_script_data', $script_data, $this->gateway );

            // Localize script with data
            wp_localize_script(
                $script_handle,
                'everydaymoney_gateway_data',
                $script_data
            );
        }

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
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     */
    public function get_payment_method_data() {
        if ( ! $this->gateway ) {
            return array();
        }

        return array(
            'title'             => $this->gateway->get_title(),
            'description'       => $this->gateway->get_description(),
            'icon'              => $this->gateway->icon,
            'supports'          => $this->gateway->supports,
            'test_mode'         => property_exists( $this->gateway, 'test_mode' ) ? $this->gateway->test_mode : false,
        );
    }
}