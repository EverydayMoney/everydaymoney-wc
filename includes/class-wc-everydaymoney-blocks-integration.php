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
     * Returns an array of script handles to enqueue for the payment method.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        $script_path       = EVERYDAYMONEY_GATEWAY_PLUGIN_URL . 'assets/js/checkout.js';
        $script_asset_path = EVERYDAYMONEY_GATEWAY_PLUGIN_DIR . 'assets/js/checkout.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array(
                'dependencies' => array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
                'version'      => EVERYDAYMONEY_GATEWAY_VERSION,
            );
        
        wp_register_script(
            'wc-everydaymoney-blocks-integration',
            $script_path,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_script_add_data( 'wc-everydaymoney-blocks-integration', 'group', 1 );
        
        // Register and enqueue styles
        wp_register_style(
            'wc-everydaymoney-blocks-integration',
            EVERYDAYMONEY_GATEWAY_PLUGIN_URL . 'assets/css/checkout.css',
            [],
            EVERYDAYMONEY_GATEWAY_VERSION
        );
        
        wp_enqueue_style( 'wc-everydaymoney-blocks-integration' );

        return [ 'wc-everydaymoney-blocks-integration' ];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment method script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        if ( ! $this->gateway ) {
            return [];
        }

        // Get the icon URL
        $icon_url = '';
        if ( ! empty( $this->gateway->icon ) ) {
            $icon_url = $this->gateway->icon;
        }

        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
            'icon'        => $icon_url,
            'test_mode'   => 'yes' === $this->get_setting( 'test_mode', 'no' ),
        ];
    }
}