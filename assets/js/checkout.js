// Use the WordPress global objects `wp` and `wc` instead of `import`.
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
    // Use `createElement` to render the description as a div.
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
        // Optional: Add an icon next to the title.
        // createElement('img', { src: 'icon-url-here.png', style: { marginLeft: '10px' } })
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