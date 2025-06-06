/**
 * External dependencies
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';

/**
 * Gets settings for the payment method.
 *
 * The `everydaymoney_gateway_data` is sourced from the `get_payment_method_data` method
 * in the `WC_Everydaymoney_Blocks_Integration` class.
 */
const settings = getSetting( 'everydaymoney_gateway_data', {} );

/**
 * A simple component that renders the payment method's description.
 */
const Content = () => {
    return decodeEntities( settings.description || '' );
};

/**
 * The properties of the payment method.
 */
const everydaymoneyPaymentMethod = {
    name: "everydaymoney_gateway",
    label: decodeEntities( settings.title || 'Everydaymoney' ),
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    ariaLabel: decodeEntities( settings.title || 'Everydaymoney' ),
    supports: {
        features: settings.supports || [],
    },
};

// Register the payment method with WooCommerce Blocks
registerPaymentMethod( everydaymoneyPaymentMethod );