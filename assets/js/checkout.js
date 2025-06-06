/**
 * External dependencies
 */
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;

/**
 * Internal dependencies
 */
const settings = getSetting( 'everydaymoney_gateway_data', {} );

/**
 * Content component for Everydaymoney payment method.
 */
const Content = () => {
    return decodeEntities( settings.description || '' );
};

/**
 * Label component for Everydaymoney payment method.
 */
const Label = () => {
    return (
        createElement( 'span', 
            { 
                className: 'wc-block-components-payment-method-label',
                style: { width: '100%' }
            },
            settings.icon && createElement( 'img', 
                { 
                    src: settings.icon,
                    alt: settings.title,
                    style: { float: 'right', marginRight: '20px' }
                }
            ),
            decodeEntities( settings.title || __( 'Everydaymoney', 'everydaymoney-gateway' ) )
        )
    );
};

/**
 * Everydaymoney payment method configuration.
 */
const Everydaymoney = {
    name: 'everydaymoney_gateway',
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: decodeEntities( 
        settings.title || __( 'Everydaymoney payment method', 'everydaymoney-gateway' ) 
    ),
    supports: {
        features: settings.supports || [],
    },
};

registerPaymentMethod( Everydaymoney );