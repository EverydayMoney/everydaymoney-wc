/**
 * We wrap everything in a 'DOMContentLoaded' event listener. This ensures that all of
 * WooCommerce's scripts have loaded and objects like `wc.blocksRegistry` are available
 * before our script tries to use them.
 */
window.addEventListener('DOMContentLoaded', () => {
    // Use the WordPress global objects `wp` and `wc`
    const { registerPaymentMethod } = wc.blocksRegistry;
    const { decodeEntities } = wp.htmlEntities;
    const { getSetting } = wc.settings;
    const { createElement } = wp.element;

    /**
     * Gets settings for the payment method.
     */
    const settings = getSetting('everydaymoney_gateway_data', {});

    /**
     * A simple component that renders the payment method's description.
     */
    const Content = () => {
        return createElement('div', {
            dangerouslySetInnerHTML: {
                __html: decodeEntities(settings.description || ''),
            },
        });
    };

    /**
     * The properties of the payment method.
     */
    const everydaymoneyPaymentMethod = {
        name: "everydaymoney_gateway",
        label: createElement('div', { style: { display: 'flex', alignItems: 'center' } },
            decodeEntities(settings.title || 'Everydaymoney')
        ),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: decodeEntities(settings.title || 'Everydaymoney'),
        supports: {
            features: settings.supports || [],
        },
    };

    // Register the payment method with WooCommerce Blocks
    registerPaymentMethod(everydaymoneyPaymentMethod);
});