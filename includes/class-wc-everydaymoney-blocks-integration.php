<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Everydaymoney Payment Gateway Blocks Integration.
 *
 * @since 1.3.0
 */
final class WC_Everydaymoney_Blocks_Integration extends AbstractPaymentMethodType {
    /**
     * The gateway instance.
     *
     * @var WC_Everydaymoney_Gateway
     */
    private $gateway;

    /**
     * Payment method name/id, must be equivalent to the gateway's id.
     *
     * @var string
     */
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
     * For a simple redirect gateway, we don't need a specific script.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        return [];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment method script.
     * This data is used to display the payment method in the checkout block.
     *
     * @return array
     */
    public function get_payment_method_data() {
        if ( ! $this->gateway ) {
            return [];
        }

        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
        ];
    }
}