<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Everydaymoney Payment Gateway.
 *
 * @class       WC_Everydaymoney_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.1.0
 */
class WC_Everydaymoney_Gateway extends WC_Payment_Gateway {

    public $api_handler; // Made public for test connection AJAX
    public $logger;      // Made public for easier access if needed
    private $webhook_secret;
    public $public_key;  // Made public for API handler
    public $api_secret;  // Made public for API handler
    public $debug;       // Made public for API handler and Logger
    public $test_mode;   // Made public for API handler

    public function __construct() {
        $this->id                 = 'everydaymoney_gateway';
        $this->icon               = apply_filters( 'woocommerce_everydaymoney_gateway_icon', EVERYDAYMONEY_GATEWAY_PLUGIN_URL . 'assets/images/icon.png' );
        $this->has_fields         = false;
        $this->method_title       = __( 'Everydaymoney', 'everydaymoney-gateway' );
        $this->method_description = __( 'Accept payments through Everydaymoney. Customers will be redirected to complete their purchase.', 'everydaymoney-gateway' );
        
        // Supports basic products only
        $this->supports = array(
            'products',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title            = $this->get_option( 'title' );
        $this->description      = $this->get_option( 'description' );
        $this->enabled          = $this->get_option( 'enabled' );
        $this->public_key       = $this->get_option( 'public_key' );
        $this->api_secret       = $this->get_option( 'api_secret' );
        $this->webhook_secret   = $this->get_option( 'webhook_secret' );
        $this->debug            = 'yes' === $this->get_option( 'debug', 'no' );
        $this->test_mode        = 'yes' === $this->get_option( 'test_mode', 'no' );
        
        // Initialize handlers
        // Ensure logger is available before API handler if API handler uses logger in constructor
        $this->logger = new WC_Everydaymoney_Logger( $this->debug );
        $this->api_handler = new WC_Everydaymoney_API( $this );


        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page_content' ) );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        
        add_action( 'woocommerce_api_wc_everydaymoney_gateway', array( $this, 'handle_webhook' ) );
        // Removed payment_scripts as checkout.js is not essential for simple redirect
        // add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_metadata_on_checkout' ), 10, 2 );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'everydaymoney-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Everydaymoney Payment Gateway', 'everydaymoney-gateway' ),
                'default' => 'yes'
            ),
            'title' => array(
                'title'       => __( 'Title', 'everydaymoney-gateway' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'everydaymoney-gateway' ),
                'default'     => __( 'Everydaymoney', 'everydaymoney-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'everydaymoney-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'everydaymoney-gateway' ),
                'default'     => __( 'Pay securely using Everydaymoney. You will be redirected to complete your purchase.', 'everydaymoney-gateway' ),
            ),
            'test_mode' => array(
                'title'       => __( 'Test Mode', 'everydaymoney-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Test Mode', 'everydaymoney-gateway' ),
                'default'     => 'no',
                'description' => __( 'Place the payment gateway in test mode using test API keys (if applicable) and test API endpoint.', 'everydaymoney-gateway' ),
                'desc_tip'    => true,
            ),
            'api_details' => array(
                'title'       => __( 'API Credentials', 'everydaymoney-gateway' ),
                'type'        => 'title',
                'description' => sprintf(
                    __( 'Enter your Everydaymoney API credentials. Get them from your %sEverydaymoney Dashboard%s.', 'everydaymoney-gateway' ),
                    '<a href="https://dashboard.everydaymoney.app" target="_blank">', // Replace with actual dashboard URL
                    '</a>'
                ) . '<br><button type="button" class="button" id="everydaymoney-test-connection">' . __( 'Test API Connection', 'everydaymoney-gateway' ) . '</button><div id="everydaymoney-test-connection-message" style="margin-top:10px;"></div>',
            ),
            'public_key' => array(
                'title'       => __( 'Public Key', 'everydaymoney-gateway' ),
                'type'        => 'text',
                'description' => __( 'Enter your API Public Key.', 'everydaymoney-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'api_secret' => array(
                'title'       => __( 'API Secret', 'everydaymoney-gateway' ),
                'type'        => 'password',
                'description' => __( 'Enter your API Secret Key.', 'everydaymoney-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __( 'Webhook Secret', 'everydaymoney-gateway' ),
                'type'        => 'password',
                'description' => sprintf(
                    __( 'Enter your webhook endpoint secret for signature verification. This is generated by you and configured in your Everydaymoney Dashboard. Your webhook URL is: %s', 'everydaymoney-gateway' ),
                    '<code>' . WC()->api_request_url( 'wc_everydaymoney_gateway' ) . '</code>'
                ),
                'default'     => '',
                'desc_tip'    => false,
            ),
            'advanced' => array(
                'title'       => __( 'Advanced Options', 'everydaymoney-gateway' ),
                'type'        => 'title',
            ),
            'payment_action' => array(
                'title'       => __( 'Payment Action', 'everydaymoney-gateway' ),
                'type'        => 'select',
                'description' => __( 'Choose whether to capture payments immediately or only authorize them (if supported by your API).', 'everydaymoney-gateway' ),
                'default'     => 'capture',
                'desc_tip'    => true,
                'options'     => array(
                    'capture'   => __( 'Capture Immediately', 'everydaymoney-gateway' ),
                    // 'authorize' => __( 'Authorize Only', 'everydaymoney-gateway' ), // Only if your API supports separate auth/capture
                ),
            ),
            'order_status_on_success' => array(
                'title'       => __( 'Order Status on Success', 'everydaymoney-gateway' ),
                'type'        => 'select',
                'description' => __( 'Choose the order status to set after successful payment confirmation via webhook.', 'everydaymoney-gateway' ),
                'default'     => 'processing',
                'desc_tip'    => true,
                'options'     => array(
                    'processing' => __( 'Processing', 'everydaymoney-gateway' ),
                    'completed'  => __( 'Completed', 'everydaymoney-gateway' ),
                ),
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'everydaymoney-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'everydaymoney-gateway' ),
                'default'     => 'no',
                'description' => sprintf(
                    __( 'Log Everydaymoney events, such as API requests. Logs are stored in %s', 'everydaymoney-gateway' ),
                    '<code>WooCommerce > Status > Logs (select everydaymoney-gateway from dropdown)</code>'
                ),
            ),
        );
    }

    // public function is_available() {
    //     // if ( 'yes' !== $this->enabled ) {
    //     //     return false;
    //     // }
    //     // if ( empty( $this->public_key ) || empty( $this->api_secret ) ) {
    //     //     if (is_admin() && ! wp_doing_ajax() ) { // Show message in admin if keys are missing
    //     //         // This message location might not be ideal, consider a dedicated admin notice.
    //     //     }
    //     //     $this->logger->log( 'Gateway unavailable: Missing API credentials.', 'debug' );
    //     //     return false;
    //     // }
    //     // if ( ! is_ssl() && ! $this->test_mode && ! defined('WP_DEBUG') ) {
    //     //     $this->logger->log( 'Gateway unavailable: SSL is required for live mode.', 'warning' );
    //     //     // Consider showing an admin notice for this too.
    //     //     return false;
    //     // }
    //     // return parent::is_available();
    //     return true;
    // }

    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
        if ( $this->test_mode ) {
            echo '<p style="font-weight: bold; color: red; padding: 10px; border: 1px solid red; background-color: #ffe0e0;">';
            echo esc_html__( 'TEST MODE ENABLED. No real PII or card details should be used.', 'everydaymoney-gateway' );
            echo '</p>';
        }
    }

    public function validate_fields() {
        return true; // No specific fields to validate on frontend for redirect
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Critical error: Order not found.', 'everydaymoney-gateway' ), 'error' );
            $this->logger->log( 'Process Payment: Order not found for ID ' . $order_id, 'error' );
            return array( 'result' => 'failure' );
        }

        $this->logger->log( 'Processing payment for order #' . $order->get_order_number() . ' (ID: ' . $order_id . ')', 'info' );

        try {
            $charge_data = $this->prepare_charge_data( $order );
            $response = $this->api_handler->create_charge( $charge_data );

            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                $this->logger->log( 'Payment API Error for order #' . $order->get_order_number() . ': ' . $error_message, 'error' );
                wc_add_notice( sprintf(__( 'Payment error: %s Please try again or contact support.', 'everydaymoney-gateway' ), esc_html($error_message) ), 'error' );
                return array( 'result' => 'failure' );
            }
            
            // Assuming $response is the 'result' part of your API structure
            if ( !isset($response['checkoutURL']) || !isset($response['transactionId']) ) {
                $this->logger->log( 'Payment API Error for order #' . $order->get_order_number() . ': Invalid response structure from API. Missing checkoutURL or transactionId. Response: ' . print_r($response, true), 'error' );
                wc_add_notice( __( 'Payment error: Could not retrieve payment details from the provider. Please contact support.', 'everydaymoney-gateway'), 'error' );
                return array( 'result' => 'failure' );
            }

            // Store transaction data from API response
            $this->save_initial_transaction_data_to_db( $order, $response );

            $order->update_status( 'pending', __( 'Awaiting payment confirmation from Everydaymoney.', 'everydaymoney-gateway' ) );
            $order->update_meta_data( '_everydaymoney_transaction_id', sanitize_text_field($response['transactionId']) );
            if (isset($response['transactionRef'])) { // If gateway returns its own ref
                 $order->update_meta_data( '_everydaymoney_gateway_ref', sanitize_text_field($response['transactionRef']) );
            }
            $order->save();
            
            wc_reduce_stock_levels( $order_id );
            WC()->cart->empty_cart();
            
            $this->logger->log( 'Payment initiated for order #' . $order->get_order_number() . '. Redirecting to: ' . $response['checkoutURL'], 'info' );
            
            return array(
                'result'   => 'success',
                'redirect' => esc_url_raw($response['checkoutURL']),
            );
            
        } catch ( Exception $e ) {
            $this->logger->log( 'Payment Exception for order #' . $order->get_order_number() . ': ' . $e->getMessage(), 'error' );
            wc_add_notice( __( 'Payment error: An unexpected error occurred. Please try again or contact support.', 'everydaymoney-gateway' ), 'error' );
            return array( 'result' => 'failure' );
        }
    }

    private function prepare_charge_data( $order ) {
        $order_lines = array();
        
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $price_per_unit = ($item->get_quantity() > 0) ? $order->get_line_subtotal( $item, false, false ) / $item->get_quantity() : 0;
            
            $line_data = array(
                'itemName'  => $item->get_name(),
                'quantity'  => $item->get_quantity(),
                'amount'    => $this->format_amount_for_api( $price_per_unit, $order->get_currency() ),
                'itemCode'  => $product ? $product->get_sku() : '',
            );
            if ( $product ) {
                $line_data['productId'] = $product->get_id();
            }
            $order_lines[] = $line_data;
        }

        if ( $order->get_shipping_total() > 0 ) {
            $order_lines[] = array(
                'itemName' => sprintf( __( 'Shipping: %s', 'everydaymoney-gateway' ), $order->get_shipping_method() ),
                'quantity' => 1,
                'amount'   => $this->format_amount_for_api( $order->get_shipping_total(), $order->get_currency() ),
                'itemCode' => 'SHIPPING',
            );
        }

        foreach ( $order->get_fees() as $fee_id => $fee ) {
            $order_lines[] = array(
                'itemName' => $fee->get_name(),
                'quantity' => 1,
                'amount'   => $this->format_amount_for_api( $fee->get_total(), $order->get_currency() ),
                'itemCode' => 'FEE-' . sanitize_key($fee_id),
            );
        }
        
        if ( $order->get_total_tax() > 0 && ! wc_prices_include_tax() ) {
            $order_lines[] = array(
                'itemName' => __( 'Tax', 'everydaymoney-gateway' ),
                'quantity' => 1,
                'amount'   => $this->format_amount_for_api( $order->get_total_tax(), $order->get_currency() ),
                'itemCode' => 'TAX',
            );
        }

        $customer_data = array(
            'email'        => $order->get_billing_email(),
            'phone'        => $order->get_billing_phone(),
            'customerName' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'customerKey'  => $this->generate_customer_key( $order ),
            'address'      => array(
                'line1'       => $order->get_billing_address_1(),
                'line2'       => $order->get_billing_address_2(),
                'city'        => $order->get_billing_city(),
                'state'       => $order->get_billing_state(),
                'postalCode'  => $order->get_billing_postcode(),
                'country'     => $order->get_billing_country(),
            ),
        );

        $charge_data = array(
            'currency'       => $order->get_currency(),
            // Amount should be calculated by your API from orderLines or pass $order->get_total() if your API expects total
            // 'amount'         => $this->format_amount_for_api( $order->get_total(), $order->get_currency() ),
            'email'          => $customer_data['email'],
            'phone'          => $customer_data['phone'],
            'customerName'   => $customer_data['customerName'],
            'customerKey'    => $customer_data['customerKey'],
            'narration'      => $this->generate_order_description( $order ),
            'transactionRef' => $this->generate_internal_transaction_ref( $order ), // Internal ref for WC
            'referenceKey'   => $order->get_order_key(), // WC order key
            'redirectUrl'    => $this->get_return_url( $order ),
            'webhookUrl'     => WC()->api_request_url( 'wc_everydaymoney_gateway' ),
            'orderLines'     => $order_lines,
            'metadata'       => array(
                'order_id'     => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'store_url'    => get_site_url(),
                'store_name'   => get_bloginfo( 'name' ),
                'wc_version'   => WC()->version,
                'plugin_version' => EVERYDAYMONEY_GATEWAY_VERSION,
            ),
            'customer'       => $customer_data, // If your API nests customer details
            'testMode'       => $this->test_mode,
        );
        
        // For payment action 'authorize', if your API supports it
        // The 'capture' key in charge_data might be specific to your API
        if ( 'authorize' === $this->get_option( 'payment_action' ) ) {
             $charge_data['capture'] = false; // This depends on your API spec
        } else {
             $charge_data['capture'] = true; // Default to capture
        }
        
        return apply_filters( 'wc_everydaymoney_charge_data', $charge_data, $order );
    }

    public function format_amount_for_api( $amount, $currency ) {
        // Adjust if your API requires amount in cents/smallest unit
        // Example: For NGN, USD (2 decimal places)
        // return intval( round( (float) $amount * 100 ) );
        return round( (float) $amount, wc_get_price_decimals() ); // Default to decimal format
    }

    private function generate_customer_key( $order ) {
        $customer_id = $order->get_customer_id();
        return ( $customer_id > 0 ) ? 'wc_user_' . $customer_id : 'guest_' . md5( $order->get_billing_email() );
    }

    private function generate_order_description( $order ) {
        return sprintf( __( 'Order #%s from %s', 'everydaymoney-gateway' ), $order->get_order_number(), get_bloginfo( 'name' ) );
    }

    private function generate_internal_transaction_ref( $order ) {
        // This is a reference generated by the plugin, might differ from gateway's ref
        return sprintf( 'WC-%s-%s', $order->get_order_number(), time() );
    }

    private function save_initial_transaction_data_to_db( $order, $response_result ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'everydaymoney_transactions';
        
        $wpdb->insert(
            $table_name,
            array(
                'order_id'        => $order->get_id(),
                'transaction_id'  => isset($response_result['transactionId']) ? sanitize_text_field($response_result['transactionId']) : '',
                'transaction_ref' => isset($response_result['transactionRef']) ? sanitize_text_field($response_result['transactionRef']) : $this->generate_internal_transaction_ref( $order ),
                'status'          => 'pending_gateway', // Initial status before webhook
                'amount'          => $order->get_total(),
                'currency'        => $order->get_currency(),
                'webhook_data'    => wp_json_encode( $response_result ), // Store initial response
                'created_at'      => current_time( 'mysql', true ),
                'updated_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
        );
        if ($wpdb->last_error) {
            $this->logger->log('DB Insert Error on ' . $table_name . ': ' . $wpdb->last_error, 'error');
        }
    }

    public function handle_webhook() {
        $this->logger->log( 'Webhook received', 'info' );
        $payload = file_get_contents( 'php://input' );
        $headers = getallheaders(); // Note: getallheaders() may not be available in all environments (e.g. Nginx FastCGI)
                                    // Consider accessing specific headers like $_SERVER['HTTP_X_EVERYDAYMONEY_SIGNATURE']

        if ( empty( $payload ) ) {
            $this->logger->log( 'Webhook: Empty payload', 'error' );
            status_header( 400 ); exit( 'Empty payload' );
        }

        if ( ! $this->verify_webhook_signature( $payload, $headers ) ) {
            $this->logger->log( 'Webhook: Invalid signature', 'error' );
            status_header( 401 ); exit( 'Invalid signature' );
        }

        $data = json_decode( $payload, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->logger->log( 'Webhook: Invalid JSON', 'error' );
            status_header( 400 ); exit( 'Invalid JSON' );
        }

        $this->logger->log( 'Webhook data: ' . print_r( $data, true ), 'debug' );

        try {
            $event_type = isset( $data['event'] ) ? sanitize_key($data['event']) : (isset($data['type']) ? sanitize_key($data['type']) : 'unknown.event'); // Adjust based on your API

            switch ( $event_type ) {
                case 'payment.success': // Adjust to your API's event names
                case 'payment.completed':
                case 'charge.succeeded':
                    $this->handle_payment_webhook_success( $data );
                    break;
                case 'payment.failed':
                case 'charge.failed':
                    $this->handle_payment_webhook_failed( $data );
                    break;
                case 'payment.cancelled': // If your API sends this
                    $this->handle_payment_webhook_cancelled( $data );
                    break;
                default:
                    $this->logger->log( 'Webhook: Unhandled event type: ' . $event_type, 'info' );
                    break;
            }
            status_header( 200 ); exit( 'OK' );
        } catch ( Exception $e ) {
            $this->logger->log( 'Webhook processing error: ' . $e->getMessage(), 'error' );
            status_header( 500 ); exit( 'Processing error' );
        }
    }

    private function verify_webhook_signature( $payload, $headers ) {
        if ( empty( $this->webhook_secret ) ) {
            $this->logger->log( 'Webhook secret not configured. Skipping signature verification. THIS IS INSECURE FOR PRODUCTION.', 'warning' );
            return true; // Should be false in production if secret is missing.
        }
        
        // Adjust header name based on your API's actual signature header
        $signature_header_key_options = array('X-Everydaymoney-Signature', 'HTTP_X_EVERYDAYMONEY_SIGNATURE');
        $signature_header = '';
        foreach ($signature_header_key_options as $key) {
            if (isset($headers[$key])) {
                $signature_header = $headers[$key];
                break;
            }
        }

        if ( empty( $signature_header ) ) {
             $this->logger->log( 'Webhook: Missing signature header', 'error' );
            return false;
        }
        
        // Assuming signature format: t=timestamp,v1=signature
        // Your API might have a different format.
        $elements = array();
        parse_str(str_replace(',', '&', $signature_header), $elements);

        $timestamp = isset($elements['t']) ? $elements['t'] : null;
        $signature_v1 = isset($elements['v1']) ? $elements['v1'] : null;

        if ( empty( $timestamp ) || empty( $signature_v1 ) ) {
             $this->logger->log( 'Webhook: Malformed signature header', 'error' );
            return false;
        }

        if ( abs( time() - intval( $timestamp ) ) > apply_filters('wc_everydaymoney_webhook_timestamp_tolerance', 300) ) { // 5 minute tolerance
            $this->logger->log( 'Webhook timestamp outside tolerance window', 'error' );
            return false;
        }
        
        $signed_payload = $timestamp . '.' . $payload;
        $expected_signature = hash_hmac( 'sha256', $signed_payload, $this->webhook_secret );

        if ( hash_equals( $expected_signature, $signature_v1 ) ) {
            return true;
        }
        
        $this->logger->log( 'Webhook: Signature mismatch. Expected: ' . $expected_signature . ' Got: ' . $signature_v1, 'error' );
        return false;
    }

    private function handle_payment_webhook_success( $data ) {
        $order = $this->get_order_from_webhook_data( $data );
        if ( ! $order ) {
            $this->logger->log( 'Webhook Success: Order not found for data: ' . print_r($data, true), 'warning' );
            return;
        }

        if ( $order->is_paid() || $order->has_status(array('completed', 'processing')) ) {
            $this->logger->log( 'Webhook Success: Order #' . $order->get_order_number() . ' already processed or paid.', 'info' );
            return;
        }

        $this->update_transaction_status_in_db( $order->get_id(), 'completed', $data );
        
        $gateway_transaction_id = isset( $data['transactionId'] ) ? sanitize_text_field($data['transactionId']) : $order->get_meta('_everydaymoney_transaction_id');
        
        $order->payment_complete( $gateway_transaction_id );
        $order->add_order_note( sprintf( __( 'Everydaymoney payment completed. Transaction ID: %s', 'everydaymoney-gateway' ), $gateway_transaction_id ) );
        
        $status_on_success = $this->get_option( 'order_status_on_success', 'processing' );
        if ($order->get_status() !== $status_on_success) { // Only update if not already the target status
             $order->update_status( $status_on_success, __('Payment confirmed by Everydaymoney webhook.', 'everydaymoney-gateway'));
        }

        $this->logger->log( 'Payment completed via webhook for order #' . $order->get_order_number(), 'info' );
    }

    private function handle_payment_webhook_failed( $data ) {
        $order = $this->get_order_from_webhook_data( $data );
        if ( ! $order ) {
            $this->logger->log( 'Webhook Failed: Order not found for data: ' . print_r($data, true), 'warning' );
            return;
        }

        if ($order->has_status('failed')) {
            $this->logger->log( 'Webhook Failed: Order #' . $order->get_order_number() . ' already marked as failed.', 'info' );
            return;
        }

        $this->update_transaction_status_in_db( $order->get_id(), 'failed', $data );
        $gateway_transaction_id = isset( $data['transactionId'] ) ? sanitize_text_field($data['transactionId']) : 'N/A';
        $reason = isset($data['failureReason']) ? sanitize_text_field($data['failureReason']) : __('Unknown reason.', 'everydaymoney-gateway');

        $order->update_status( 'failed', sprintf( __( 'Everydaymoney payment failed. Transaction ID: %s. Reason: %s', 'everydaymoney-gateway' ), $gateway_transaction_id, $reason ) );
        $this->logger->log( 'Payment failed via webhook for order #' . $order->get_order_number(), 'info' );
    }

    private function handle_payment_webhook_cancelled( $data ) {
        $order = $this->get_order_from_webhook_data( $data );
        if ( ! $order ) {
            $this->logger->log( 'Webhook Cancelled: Order not found for data: ' . print_r($data, true), 'warning' );
            return;
        }
        
        if ($order->has_status('cancelled')) {
            $this->logger->log( 'Webhook Cancelled: Order #' . $order->get_order_number() . ' already marked as cancelled.', 'info' );
            return;
        }

        $this->update_transaction_status_in_db( $order->get_id(), 'cancelled', $data );
        $order->update_status( 'cancelled', __( 'Payment was cancelled via Everydaymoney webhook.', 'everydaymoney-gateway' ) );
        
        // Optional: Restore stock if applicable and not already handled
        // if ( apply_filters( 'wc_everydaymoney_restore_stock_on_cancel', true ) ) {
        //    wc_increase_stock_levels( $order->get_id() );
        // }
        $this->logger->log( 'Payment cancelled via webhook for order #' . $order->get_order_number(), 'info' );
    }

    private function get_order_from_webhook_data( $data ) {
        $order_id_from_meta = $order->get_meta( '_everydaymoney_transaction_id' );
        $gateway_transaction_id = isset( $data['transactionId'] ) ? sanitize_text_field($data['transactionId']) : null;
        $plugin_transaction_ref = isset( $data['transactionRef'] ) ? sanitize_text_field($data['transactionRef']) : null; // This is the one we sent
        $order_id_from_plugin_ref = null;

        if ($plugin_transaction_ref) {
             // Assuming format WC-ORDERNUMBER-TIMESTAMP or WC-ORDERID-TIMESTAMP
            $ref_parts = explode( '-', $plugin_transaction_ref );
            if ( count( $ref_parts ) >= 2 && 'WC' === $ref_parts[0] && !empty($ref_parts[1]) ) {
                // Check if $ref_parts[1] is the order ID or order number.
                // WooCommerce's wc_get_order() can accept order number as string.
                 $order_id_from_plugin_ref = wc_get_order_id_by_order_number($ref_parts[1]);
                 if (!$order_id_from_plugin_ref && is_numeric($ref_parts[1])) {
                    $order_id_from_plugin_ref = intval($ref_parts[1]);
                 }
            }
        }
        
        $order_id_in_payload_meta = isset($data['metadata']['order_id']) ? intval($data['metadata']['order_id']) : null;

        $order = false;
        if ( $order_id_in_payload_meta ) {
            $order = wc_get_order( $order_id_in_payload_meta );
            if ($order) return $order;
        }
        if ( $order_id_from_plugin_ref ) {
             $order = wc_get_order( $order_id_from_plugin_ref );
             if ($order) return $order;
        }

        // Fallback: Search by transaction ID stored in order meta
        if ( $gateway_transaction_id ) {
            $orders = wc_get_orders( array(
                'limit'      => 1,
                'meta_key'   => '_everydaymoney_transaction_id',
                'meta_value' => $gateway_transaction_id,
            ) );
            if ( ! empty( $orders ) ) {
                return $orders[0];
            }
        }
        $this->logger->log('Webhook: Could not find order from data: ' . print_r($data, true), 'warning');
        return false;
    }

    private function update_transaction_status_in_db( $order_id, $status, $webhook_payload ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'everydaymoney_transactions';
        
        // Try to find based on order_id and perhaps an existing transaction_id from payload if available
        $existing_transaction_id = isset($webhook_payload['transactionId']) ? sanitize_text_field($webhook_payload['transactionId']) : null;
        $where = array( 'order_id' => $order_id );
        if ($existing_transaction_id) {
            // If webhook provides transactionId, it's more reliable to update that specific row.
            // However, our table might have only order_id at this point if transactionId wasn't in initial create_charge response.
            // So, we might need to locate row by order_id and update its transaction_id if it's empty.
            
            // First, check if a row with this transaction_id exists
            $row_with_txn_id = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table_name WHERE transaction_id = %s", $existing_transaction_id) );
            if ($row_with_txn_id) {
                $where = array('transaction_id' => $existing_transaction_id);
            }
        }


        $wpdb->update(
            $table_name,
            array(
                'status'         => sanitize_text_field($status),
                'webhook_data'   => wp_json_encode( $webhook_payload ),
                'updated_at'     => current_time( 'mysql', true ),
                // If transaction_id from webhook is new and current row's is empty, update it
                'transaction_id' => ($existing_transaction_id && empty($wpdb->get_var($wpdb->prepare("SELECT transaction_id FROM $table_name WHERE order_id = %d", $order_id)))) ? $existing_transaction_id : $wpdb->get_var($wpdb->prepare("SELECT transaction_id FROM $table_name WHERE order_id = %d", $order_id)) ,
            ),
            $where, // WHERE order_id = %d (or transaction_id = %s if found)
            array( '%s', '%s', '%s', '%s' ), // Format for data
            array( is_numeric($where['order_id']) ? '%d' : '%s' ) // Format for where, adapt if using transaction_id
        );
         if ($wpdb->last_error) {
            $this->logger->log('DB Update Error on ' . $table_name . ': ' . $wpdb->last_error, 'error');
        }
    }

    public function thankyou_page_content() {
        global $wp;
        if ( empty( $wp->query_vars['order-received'] ) ) return;

        $order_id = absint( $wp->query_vars['order-received'] );
        $order = wc_get_order( $order_id );
        
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }
        
        // Message based on order status after potential webhook processing
        if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
            echo '<p>' . esc_html__( 'Your payment is being processed by Everydaymoney. You will receive an email confirmation shortly once payment is verified.', 'everydaymoney-gateway' ) . '</p>';
        } elseif ( $order->has_status( 'failed' ) ) {
            echo '<p class="woocommerce-error">' . esc_html__( 'Unfortunately, your payment could not be processed. Please try again or contact us for assistance.', 'everydaymoney-gateway' ) . '</p>';
        } elseif ($order->has_status(array('processing', 'completed'))) {
             echo '<p class="woocommerce-thankyou-order-received">' . esc_html__( 'Thank you. Your payment has been received and your order is being processed.', 'everydaymoney-gateway' ) . '</p>';
        }
    }

    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
            $text = esc_html__( 'Your payment with Everydaymoney is currently being processed. You will be notified via email once the payment is confirmed.', 'everydaymoney-gateway' );
            if ( $plain_text ) {
                echo $text . PHP_EOL;
            } else {
                echo '<p>' . $text . '</p>';
            }
        }
    }
    
    // Removed payment_scripts method as checkout.js is not essential for this simplified version

    public function save_order_metadata_on_checkout( $order, $data ) {
        if ( $order->get_payment_method() === $this->id ) {
            $order->update_meta_data( '_everydaymoney_checkout_initiated_at', current_time('timestamp', true) );
            // The order object ($order) is not saved here automatically.
            // $order->save() would be needed if you want this meta immediately.
            // However, process_payment runs after this and saves the order.
        }
    }

    public function admin_options() {
        ?>
        <h2><?php echo esc_html( $this->get_method_title() ); ?></h2>
        <?php echo wp_kses_post( wpautop( $this->get_method_description() ) ); ?>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
}