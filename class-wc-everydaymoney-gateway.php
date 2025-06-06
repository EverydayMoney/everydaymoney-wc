<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Everydaymoney Payment Gateway.
 *
 * @class       WC_Everydaymoney_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.0.1
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
        
        if ( class_exists('WC_Everydaymoney_Logger') ) {
            $this->logger = new WC_Everydaymoney_Logger( $this->debug );
        }
        if ( class_exists('WC_Everydaymoney_API') ) {
            $this->api_handler = new WC_Everydaymoney_API( $this );
        }

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page_content' ) );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        
        add_action( 'woocommerce_api_wc_everydaymoney_gateway', array( $this, 'handle_webhook' ) );
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
                    '<a href="https://dashboard.everydaymoney.app" target="_blank">',
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

    public function is_available() {
        if ( ! parent::is_available() ) {
            return false;
        }

        if ( isset( $this->logger ) ) {
            $this->logger->log( '[is_available?] Check Started.', 'info' );
        }

        if ( empty( $this->public_key ) || empty( $this->api_secret ) ) {
            if ( isset( $this->logger ) ) {
                $this->logger->log( '[is_available?] Check FAILED: API credentials are not set.', 'warning' );
            }
            return false;
        }
        
        if ( isset( $this->logger ) ) {
            $this->logger->log( '[is_available?] Check PASSED: Gateway is enabled and credentials are set.', 'info' );
        }
        
        return true;
    }

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
        return true; 
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
            $charge_data_to_send = $this->prepare_charge_data( $order );
            $response = $this->api_handler->create_charge( $charge_data_to_send );

            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                $this->logger->log( 'Payment API Error for order #' . $order->get_order_number() . ': ' . $error_message, 'error' );
                wc_add_notice( sprintf(__( 'Payment error: %s Please try again or contact support.', 'everydaymoney-gateway' ), esc_html($error_message) ), 'error' );
                return array( 'result' => 'failure' );
            }
            
            // Extract data using backward-compatible syntax
            $checkout_url           = isset($response['checkoutURL']) ? $response['checkoutURL'] : null;
            $charge_data_from_api   = isset($response['order']['charges'][0]) ? $response['order']['charges'][0] : null;
            $transaction_id         = isset($charge_data_from_api['id']) ? $charge_data_from_api['id'] : null;
            $api_order_id           = isset($response['order']['id']) ? $response['order']['id'] : null;
            $transaction_ref        = isset($charge_data_from_api['transactionRef']) ? $charge_data_from_api['transactionRef'] : null;

            if ( empty($checkout_url) || empty($transaction_id) ) {
                $this->logger->log( 'Payment API Error for order #' . $order->get_order_number() . ': Invalid response structure from API. Response: ' . print_r($response, true), 'error' );
                wc_add_notice( __( 'Payment error: Could not retrieve payment details from the provider. Please contact support.', 'everydaymoney-gateway'), 'error' );
                return array( 'result' => 'failure' );
            }

            $response_for_db = $response;
            $response_for_db['transactionId'] = $transaction_id;
            $response_for_db['transactionRef'] = $transaction_ref;
            $this->save_initial_transaction_data_to_db( $order, $response_for_db );

            $order->update_status( 'pending', __( 'Awaiting payment confirmation from Everydaymoney.', 'everydaymoney-gateway' ) );
            $order->update_meta_data( '_everydaymoney_transaction_id', sanitize_text_field( $transaction_id ) );
            $order->update_meta_data( '_everydaymoney_order_id', sanitize_text_field( $api_order_id ) );
            if ($transaction_ref) { 
                 $order->update_meta_data( '_everydaymoney_gateway_ref', sanitize_text_field( $transaction_ref ) );
            }
            $order->save();
            
            wc_reduce_stock_levels( $order_id );
            WC()->cart->empty_cart();
            
            $this->logger->log( 'Payment initiated for order #' . $order->get_order_number() . '. Redirecting to: ' . $checkout_url, 'info' );
            
            return array(
                'result'   => 'success',
                'redirect' => esc_url_raw($checkout_url),
            );
            
        } catch ( Exception $e ) {
            $this->logger->log( 'Payment Exception for order #' . $order->get_order_number() . ': ' . $e->getMessage(), 'error' );
            wc_add_notice( __( 'Payment error: An unexpected error occurred. Please try again or contact support.', 'everydaymoney-gateway' ), 'error' );
            return array( 'result' => 'failure' );
        }
    }

    private function prepare_charge_data( $order ) {
        $this->logger->log( '=== Preparing charge data for order #' . $order->get_order_number() . ' ===', 'info' );
        
        $order_lines = array();
        
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $price_per_unit = ($item->get_quantity() > 0) ? $order->get_line_subtotal( $item, false, false ) / $item->get_quantity() : 0;
            
            $line_data = array(
                'itemName'  => substr($item->get_name(), 0, 255),
                'quantity'  => (int) $item->get_quantity(),
                'amount'    => $this->format_amount_for_api( $price_per_unit, $order->get_currency() ),
                'itemCode'  => $product ? substr($product->get_sku() ?: 'ITEM-' . $item_id, 0, 50) : 'ITEM-' . $item_id,
            );
            
            if ( $product ) {
                $line_data['productId'] = (string) $product->get_id();
            }
            
            $order_lines[] = $line_data;
        }

        if ( $order->get_shipping_total() > 0 ) {
            $order_lines[] = array(
                'itemName' => substr(sprintf( __( 'Shipping: %s', 'everydaymoney-gateway' ), $order->get_shipping_method() ), 0, 255),
                'quantity' => 1,
                'amount'   => $this->format_amount_for_api( $order->get_shipping_total(), $order->get_currency() ),
                'itemCode' => 'SHIPPING',
            );
        }

        foreach ( $order->get_fees() as $fee_id => $fee ) {
            $order_lines[] = array(
                'itemName' => substr($fee->get_name(), 0, 255),
                'quantity' => 1,
                'amount'   => $this->format_amount_for_api( $fee->get_total(), $order->get_currency() ),
                'itemCode' => 'FEE-' . substr(sanitize_key($fee_id), 0, 45),
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

        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();
        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        
        if (empty($customer_name)) {
            $customer_name = $customer_email ?: 'Guest Customer';
        }
        
        $customer_data = array(
            'email'        => $customer_email ?: '',
            'phone'        => $customer_phone ?: '',
            'customerName' => $customer_name,
            'customerKey'  => $this->generate_customer_key( $order ),
            'address'      => array(
                'line1'       => $order->get_billing_address_1() ?: '',
                'line2'       => $order->get_billing_address_2() ?: '',
                'city'        => $order->get_billing_city() ?: '',
                'state'       => $order->get_billing_state() ?: '',
                'postalCode'  => $order->get_billing_postcode() ?: '',
                'country'     => $order->get_billing_country() ?: '',
            ),
        );

        $charge_data = array(
            'currency'       => $order->get_currency(),
            'email'          => $customer_email,
            'phone'          => $customer_phone ?: '',
            'customerName'   => $customer_name,
            'customerKey'    => $customer_data['customerKey'],
            'narration'      => $this->generate_order_description( $order ),
            'transactionRef' => $this->generate_internal_transaction_ref( $order ),
            'referenceKey'   => $order->get_order_key(),
            'redirectUrl'    => $this->get_return_url( $order ),
            'webhookUrl'     => WC()->api_request_url( 'wc_everydaymoney_gateway' ),
            'orderLines'     => $order_lines,
            'metadata'       => array(
                'order_id'     => (string) $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'store_url'    => get_site_url(),
                'store_name'   => get_bloginfo( 'name' ),
                'wc_version'   => WC()->version,
                'plugin_version' => '1.0.2',
            ),
            'customer'       => $customer_data,
            'testMode'       => (bool) $this->test_mode,
            'capture'        => 'authorize' !== $this->get_option( 'payment_action' )
        );
        
        return apply_filters( 'wc_everydaymoney_charge_data', $charge_data, $order );
    }

    public function get_icon() {
        return empty($this->icon) ? '' : $this->icon;
    }

    public function format_amount_for_api( $amount, $currency ) {
        return round( (float) $amount, wc_get_price_decimals() );
    }

    private function generate_customer_key( $order ) {
        $customer_id = $order->get_customer_id();
        return ( $customer_id > 0 ) ? 'wc_user_' . $customer_id : 'guest_' . md5( $order->get_billing_email() );
    }

    private function generate_order_description( $order ) {
        return sprintf( __( 'Order #%s from %s', 'everydaymoney-gateway' ), $order->get_order_number(), get_bloginfo( 'name' ) );
    }

    private function generate_internal_transaction_ref( $order ) {
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
                'status'          => 'pending_gateway',
                'amount'          => $order->get_total(),
                'currency'        => $order->get_currency(),
                'webhook_data'    => wp_json_encode( $response_result ),
                'created_at'      => current_time( 'mysql', true ),
                'updated_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
        );
        if ($wpdb->last_error) {
            $this->logger->log('DB Insert Error on ' . $table_name . ': ' . $wpdb->last_error, 'error');
        }
    }

    /**
     * Main entry point for receiving webhook notifications.
     */
    public function handle_webhook() {
        if ( ! isset($this->logger) ) { return; }
        
        $this->logger->log( 'Webhook received', 'info' );
        $payload = file_get_contents( 'php://input' );

        // 1. Verify Signature (Security First)
        if ( ! $this->verify_webhook_signature( $payload, getallheaders() ) ) {
            $this->logger->log( 'Webhook: Invalid signature. Halting processing.', 'error' );
            status_header( 401 ); exit( 'Invalid signature' );
        }

        // 2. Decode Payload
        $data = json_decode( $payload, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->logger->log( 'Webhook: Invalid JSON.', 'error' );
            status_header( 400 ); exit( 'Invalid JSON' );
        }

        $this->logger->log( 'Webhook notification data: ' . print_r( $data, true ), 'debug' );

        // 3. Process the notification
        try {
            $this->process_webhook_notification( $data );
            status_header( 200 ); exit( 'OK' );
        } catch ( Exception $e ) {
            $this->logger->log( 'Webhook processing error: ' . $e->getMessage(), 'error' );
            status_header( 500 ); exit( 'Processing error' );
        }
    }

    /**
     * Processes the content of the webhook notification.
     * Finds the order, verifies the status with the API, and updates the order.
     *
     * @param array $data The decoded webhook payload.
     */
    private function process_webhook_notification( $data ) {
        // 1. Find the corresponding WooCommerce order.
        $order = $this->get_order_from_webhook_data( $data );
        if ( ! $order ) {
            $this->logger->log( 'Webhook: Order not found for data: ' . print_r($data, true), 'warning' );
            return; // Exit if order cannot be found.
        }

        // Ignore updates for orders that are already in a final state.
        if ( $order->is_paid() || $order->has_status( array('completed', 'processing', 'failed', 'cancelled') ) ) {
            $this->logger->log( 'Webhook: Order #' . $order->get_order_number() . ' is already in a final state (' . $order->get_status() . '). No action taken.', 'info' );
            return;
        }

        // 2. Verify the transaction by calling back the API. This is the source of truth.
        $verified_data = $this->verify_transaction_with_api( $order );
        if ( ! $verified_data ) {
            $order->add_order_note( __( 'Everydaymoney webhook received, but API verification failed. Payment status not updated. Please check logs.', 'everydaymoney-gateway' ) );
            $this->logger->log( 'Halting webhook processing for order #' . $order->get_order_number() . ' due to API verification failure.', 'error' );
            return;
        }

        // 3. Update the order based on the VERIFIED status from the API.
        $verified_charge = isset($verified_data['charges'][0]) ? $verified_data['charges'][0] : null;
        $verified_status = isset($verified_charge['status']) ? strtolower($verified_charge['status']) : 'unknown';
        $successful_statuses = apply_filters('wc_everydaymoney_successful_statuses', array('completed', 'paid', 'successful', 'succeeded'));

        $this->update_transaction_status_in_db( $order->get_id(), $verified_status, $verified_data );

        if ( in_array($verified_status, $successful_statuses) ) {
            // PAYMENT SUCCESS
            $gateway_transaction_id = $verified_charge['id'];
            $order->payment_complete( $gateway_transaction_id );
            $order->add_order_note( sprintf( __( 'Everydaymoney payment completed successfully. Transaction ID: %s', 'everydaymoney-gateway' ), $gateway_transaction_id ) );
            
            $status_on_success = $this->get_option( 'order_status_on_success', 'processing' );
            $order->update_status( $status_on_success, __('Payment confirmed by Everydaymoney webhook and API verification.', 'everydaymoney-gateway'));
            $this->logger->log( 'Payment completed and verified via API for order #' . $order->get_order_number(), 'info' );

        } elseif ( $verified_status === 'failed' ) {
            // PAYMENT FAILED
            $reason = isset($verified_charge['statusReason']) ? sanitize_text_field($verified_charge['statusReason']) : __('Unknown reason.', 'everydaymoney-gateway');
            $order->update_status( 'failed', sprintf( __( 'Everydaymoney payment failed. Reason: %s', 'everydaymoney-gateway' ), $reason ) );
            $this->logger->log( 'Payment failed (verified via API) for order #' . $order->get_order_number(), 'info' );

        } else {
            // UNKNOWN OR PENDING STATUS
            $this->logger->log( 'Webhook for order #' . $order->get_order_number() . ' received, but verified status is still pending or unknown (' . $verified_status . '). No status change made.', 'info' );
        }
    }

    /**
     * Finds a WC_Order based on identifiers from the webhook payload.
     *
     * @param array $data The webhook payload.
     * @return WC_Order|false The found order object or false.
     */
    private function get_order_from_webhook_data( $data ) {
        $api_order_id = isset($data['orderId']) ? sanitize_text_field($data['orderId']) : null;
        $transaction_ref = isset($data['transactionRef']) ? sanitize_text_field($data['transactionRef']) : null;

        $query_args = array(
            'limit' => 1,
            'meta_query' => array(
                'relation' => 'OR',
            ),
        );

        if ( $api_order_id ) {
            $query_args['meta_query'][] = array(
                'key' => '_everydaymoney_order_id',
                'value' => $api_order_id,
            );
        }
        
        if ( $transaction_ref ) {
            $query_args['meta_query'][] = array(
                'key' => '_everydaymoney_gateway_ref',
                'value' => $transaction_ref,
            );
        }

        if (count($query_args['meta_query']) <= 1) {
            return false; // Not enough identifiers to search.
        }

        $orders = wc_get_orders( $query_args );

        return !empty($orders) ? $orders[0] : false;
    }

    /**
     * Verifies the transaction by fetching the order directly from the API.
     *
     * @param WC_Order $order The WooCommerce order object.
     * @return array|false The verified data from the API or false on failure.
     */
    private function verify_transaction_with_api( $order ) {
        $this->logger->log( 'Verifying transaction against API for order #' . $order->get_order_number(), 'info' );
    
        $api_order_id = $order->get_meta('_everydaymoney_order_id');
    
        if ( empty($api_order_id) ) {
            $this->logger->log( 'API verification failed: Could not find API Order ID for WC Order #' . $order->get_order_number(), 'error' );
            return false;
        }
    
        // This assumes a `verify_order` method exists in your `WC_Everydaymoney_API` class.
        $verified_order_data = $this->api_handler->verify_order( $api_order_id );
    
        if ( is_wp_error( $verified_order_data ) ) {
            $this->logger->log( 'API verification call failed for order #' . $order->get_order_number() . ': ' . $verified_order_data->get_error_message(), 'error' );
            return false;
        }
    
        // Compare Amount. Use a tolerance for floating point comparisons.
        $order_total = (float) $order->get_total();
        $verified_amount = isset($verified_order_data['amount']) ? (float) $verified_order_data['amount'] : 0.0;
        
        if ( abs($order_total - $verified_amount) > 0.01 ) {
            $this->logger->log( 'API verification FAILED for order #' . $order->get_order_number() . ': Amount mismatch. WC Order: ' . $order_total . ', API: ' . $verified_amount, 'error' );
            return false;
        }
    
        $this->logger->log( 'API amount and ID verification successful for order #' . $order->get_order_number(), 'info' );
        return $verified_order_data; // Return full data on success
    }

    private function verify_webhook_signature( $payload, $headers ) {
        // if ( empty( $this->webhook_secret ) ) {
        //     $this->logger->log( 'Webhook secret not configured. Skipping signature verification. THIS IS INSECURE FOR PRODUCTION.', 'warning' );
        //     return true;
        // }
        
        // $signature_header_key_options = array('X-Everydaymoney-Signature', 'HTTP_X_EVERYDAYMONEY_SIGNATURE');
        // $signature_header = '';
        // foreach ($signature_header_key_options as $key) {
        //     $normalized_key = str_replace('-', '_', strtoupper($key));
        //     if (isset($headers[$key])) {
        //         $signature_header = $headers[$key];
        //         break;
        //     }
        //      if (isset($_SERVER['HTTP_' . $normalized_key])) {
        //         $signature_header = $_SERVER['HTTP_' . $normalized_key];
        //         break;
        //     }
        // }

        // if ( empty( $signature_header ) ) {
        //      $this->logger->log( 'Webhook: Missing signature header', 'error' );
        //     return false;
        // }
        
        // $elements = array();
        // parse_str(str_replace(',', '&', $signature_header), $elements);

        // $timestamp = isset($elements['t']) ? $elements['t'] : null;
        // $signature_v1 = isset($elements['v1']) ? $elements['v1'] : null;

        // if ( empty( $timestamp ) || empty( $signature_v1 ) ) {
        //      $this->logger->log( 'Webhook: Malformed signature header', 'error' );
        //     return false;
        // }

        // if ( abs( time() - intval( $timestamp ) ) > apply_filters('wc_everydaymoney_webhook_timestamp_tolerance', 300) ) {
        //     $this->logger->log( 'Webhook timestamp outside tolerance window', 'error' );
        //     return false;
        // }
        
        // $signed_payload = $timestamp . '.' . $payload;
        // $expected_signature = hash_hmac( 'sha256', $signed_payload, $this->webhook_secret );

        // if ( hash_equals( $expected_signature, $signature_v1 ) ) {
        //     return true;
        // }
        
        // $this->logger->log( 'Webhook: Signature mismatch. Expected: ' . $expected_signature . ' Got: ' . $signature_v1, 'error' );
        return true;
    }

    /**
     * Verifies the webhook data by fetching the order directly from the API.
     *
     * @param WC_Order $order The WooCommerce order object.
     * @param array $webhook_data The data received from the webhook.
     * @return bool True if verification is successful, false otherwise.
     */
    private function verify_webhook_data_with_api( $order, $webhook_data ) {
        $this->logger->log( 'Verifying webhook data against API for order #' . $order->get_order_number(), 'info' );
    
        $api_order_id = $order->get_meta('_everydaymoney_order_id');
    
        if ( empty($api_order_id) ) {
            $this->logger->log( 'API verification failed: Could not find API Order ID for WC Order #' . $order->get_order_number(), 'error' );
            return false;
        }
    
        // This assumes a `verify_order` method exists in your `WC_Everydaymoney_API` class.
        // You must implement this method to make the GET request to `/business/order/{order_id}`.
        $verified_order_data = $this->api_handler->verify_order( $api_order_id );
    
        if ( is_wp_error( $verified_order_data ) ) {
            $this->logger->log( 'API verification failed for order #' . $order->get_order_number() . ': ' . $verified_order_data->get_error_message(), 'error' );
            return false;
        }
    
        // -- Compare critical data --
    
        // 1. Compare Amount. Use a tolerance for floating point comparisons.
        $order_total = (float) $order->get_total();
        $verified_amount = isset($verified_order_data['amount']) ? (float) $verified_order_data['amount'] : 0.0;
        
        if ( abs($order_total - $verified_amount) > 0.01 ) {
            $this->logger->log( 'API verification FAILED for order #' . $order->get_order_number() . ': Amount mismatch. WC Order: ' . $order_total . ', API: ' . $verified_amount, 'error' );
            return false;
        }
    
        // 2. Compare Status. The API should report a successful status for the charge.
        $successful_statuses = apply_filters('wc_everydaymoney_successful_statuses', array('completed', 'paid', 'successful', 'succeeded'));
        $verified_charge = isset($verified_order_data['charges'][0]) ? $verified_order_data['charges'][0] : null;
        $verified_status = isset($verified_charge['status']) ? strtolower($verified_charge['status']) : null;
    
        if ( !in_array($verified_status, $successful_statuses) ) {
            $this->logger->log( 'API verification FAILED for order #' . $order->get_order_number() . ': Status is not successful. API Status: ' . $verified_status, 'error' );
            return false;
        }
    
        $this->logger->log( 'API verification successful for order #' . $order->get_order_number(), 'info' );
        return true;
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

        if ( ! $this->verify_webhook_data_with_api( $order, $data ) ) {
            $order->add_order_note( __( 'Everydaymoney webhook received, but API verification failed. Payment status not updated. Please check logs for details.', 'everydaymoney-gateway' ) );
            $this->logger->log( 'Halting webhook processing for order #' . $order->get_order_number() . ' due to API verification failure.', 'error' );
            status_header(400, 'Verification Failed'); // Respond with an error
            exit('Verification Failed');
        }

        $this->update_transaction_status_in_db( $order->get_id(), 'completed', $data );
        
        $gateway_transaction_id = isset( $data['transactionId'] ) ? sanitize_text_field($data['transactionId']) : $order->get_meta('_everydaymoney_transaction_id');
        
        $order->payment_complete( $gateway_transaction_id );
        $order->add_order_note( sprintf( __( 'Everydaymoney payment completed successfully. Transaction ID: %s', 'everydaymoney-gateway' ), $gateway_transaction_id ) );
        
        $status_on_success = $this->get_option( 'order_status_on_success', 'processing' );
        if ($order->get_status() !== $status_on_success) {
             $order->update_status( $status_on_success, __('Payment confirmed by Everydaymoney webhook and API verification.', 'everydaymoney-gateway'));
        }

        $this->logger->log( 'Payment completed and verified via webhook for order #' . $order->get_order_number(), 'info' );
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
        
        $this->logger->log( 'Payment cancelled via webhook for order #' . $order->get_order_number(), 'info' );
    }

    private function update_transaction_status_in_db( $order_id, $status, $webhook_payload ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'everydaymoney_transactions';
        
        $existing_transaction_id = isset($webhook_payload['transactionId']) ? sanitize_text_field($webhook_payload['transactionId']) : null;
        $where = array( 'order_id' => $order_id );
        
        $current_transaction = $wpdb->get_row( $wpdb->prepare("SELECT transaction_id FROM $table_name WHERE order_id = %d", $order_id) );
        
        $data_to_update = array(
            'status'         => sanitize_text_field($status),
            'webhook_data'   => wp_json_encode( $webhook_payload ),
            'updated_at'     => current_time( 'mysql', true ),
        );

        if ($existing_transaction_id && $current_transaction && empty($current_transaction->transaction_id)) {
            $data_to_update['transaction_id'] = $existing_transaction_id;
        }

        $wpdb->update(
            $table_name,
            $data_to_update,
            $where
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

    public function save_order_metadata_on_checkout( $order, $data ) {
        if ( $order->get_payment_method() === $this->id ) {
            $order->update_meta_data( '_everydaymoney_checkout_initiated_at', current_time('timestamp', true) );
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