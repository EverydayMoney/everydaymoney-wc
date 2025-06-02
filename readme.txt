=== Everydaymoney Payment Gateway ===
Contributors: (your name or company)
Tags: woocommerce, payment gateway, everydaymoney, payment
Requires at least: 5.0
Tested up to: (Current WP Version)
WC requires at least: 3.5
WC tested up to: (Current WC Version)
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates Everydaymoney Payment Gateway with WooCommerce to accept payments.

== Description ==

This plugin allows WooCommerce store owners to accept payments via Everydaymoney. Customers are redirected to the secure Everydaymoney platform to complete their payment.

== Installation ==

1. Upload the `everydaymoney` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to WooCommerce > Settings > Payments and enable "Everydaymoney".
4. Configure your API Public Key and API Secret.

== Changelog ==

= 1.0.0 =
* Initial release.


everydaymoney/
├── everydaymoney.php                     (Main plugin file)
├── class-wc-everydaymoney-gateway.php    (Gateway logic class)
├── assets/
│   ├── images/
│   │   └── icon.png                      (Your gateway's icon, ~120x40px is common)
│   ├── js/
│   │   └── everydaymoney-admin.js        (Optional: JS for admin settings page)
│   └── css/
│       └── everydaymoney-admin.css       (Optional: CSS for admin settings page)
└── readme.txt                            (Standard WordPress plugin readme)