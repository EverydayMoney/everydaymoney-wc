<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Everydaymoney Payment Gateway.
 *
 * @class       WC_Everydaymoney_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @author      Your Name/Company
 */
class WC_Everydaymoney_Gateway extends WC_Payment_Gateway {

    private $api_base_url;
    private $public_key;
    private $api_secret;
    private $jwt_token;
    private $jwt_token_expires_at;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'everydaymoney_gateway';
        $this->icon               = apply_filters('woocommerce_everydaymoney_gateway_icon', plugins_url('assets/images/icon.png', __FILE__));
        $this->has_fields         = false; // No custom fields on the checkout page for this gateway
        $this->method_title       = __( 'Everydaymoney', 'everydaymoney-gateway' );
        $this->method_description = __( 'Pay securely using Everydaymoney. You will be redirected to complete your purchase.', 'everydaymoney-gateway' );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user-facing properties.
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        $this->public_key   = $this->get_option( 'public_key' );
        $this->api_secret   = $this->get_option( 'api_secret' );

        // Get API base URL from constant defined in main plugin file
        $this->api_base_url = rtrim(EVERYDAYMONEY_GATEWAY_API_URL, '/');

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page_content' ) );

        // Webhook handler - IMPORTANT for production
        // add_action( 'woocommerce_api_wc_everydaymoney_gateway', array( $this, 'handle_webhook' ) );
    }

    /**
     * Initialize Gateway Settings Form Fields.
     */
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
                'default'     => __( 'Everydaymoney Secure Payment', 'everydaymoney-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'everydaymoney-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'everydaymoney-gateway' ),
                'default'     => __( 'Pay using your Everydaymoney account or supported payment methods.', 'everydaymoney-gateway' ),
            ),
            'api_details' => array(
                'title'       => __( 'API Credentials', 'everydaymoney-gateway' ),
                'type'        => 'title',
                'description' => sprintf(
                    __( 'Enter your Everydaymoney API credentials. These can be obtained from your %sEverydaymoney Business Dashboard%s.', 'everydaymoney-gateway' ),
                    '<a href="YOUR_EVERYDAYMONEY_DASHBOARD_URL" target="_blank">', // Replace with actual dashboard URL
                    '</a>'
                ),
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
                'description' => __( 'Enter your API Secret.', 'everydaymoney-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Get JWT token for business authentication.
     * This mimics the loginBusiness logic.
     */
    private function get_jwt_token() {
        // Check if we have a valid token already
        if ( $this->jwt_token && $this->jwt_token_expires_at && time() < $this->jwt_token_expires_at ) {
            return $this->jwt_token;
        }

        // Ensure API base URL is set
        if ( empty($this->api_base_url) || $this->api_base_url === rtrim( 'YOUR_NESTJS_API_BASE_URL', '/' )) { // Check against placeholder
            wc_get_logger()->error( 'Everydaymoney Gateway: API Base URL not configured correctly.', array( 'source' => $this->id ) );
            return false;
        }
        
        $login_url = $this->api_base_url . '/auth/business/login'; // Adjust if your login path is different

        if ( empty($this->public_key) || empty($this->api_secret) ) {
            wc_get_logger()->error( 'Everydaymoney Gateway: API Public Key or Secret not set.', array( 'source' => $this->id ) );
            return false;
        }

        $auth_string = $this->public_key . ':' . $this->api_secret;
        $base64_token = base64_encode($auth_string);

        $args = array(
            'method'    => 'POST',
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'X-Api-Key'     => $this->public_key,
                'Authorization' => 'Basic ' . $base64_token,
            ),
            'timeout'   => 45, // seconds
        );

        // wc_get_logger()->debug( 'Everydaymoney Gateway: Requesting JWT from: ' . $login_url, array( 'source' => $this->id ) );

        $response = wp_remote_post( $login_url, $args );

        if ( is_wp_error( $response ) ) {
            wc_get_logger()->error( 'Everydaymoney Gateway: JWT Auth WP_Error: ' . $response->get_error_message(), array( 'source' => $this->id ) );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $http_code = wp_remote_retrieve_response_code( $response );
        $parsed_body = json_decode( $body, true );

        // wc_get_logger()->debug( 'Everydaymoney Gateway: JWT Auth Response Code: ' . $http_code, array( 'source' => $this->id ) );
        // wc_get_logger()->debug( 'Everydaymoney Gateway: JWT Auth Response Body: ' . $body, array( 'source' => $this->id ) );

        if ( ($http_code === 200 || $http_code === 201) && isset($parsed_body['isError']) && $parsed_body['isError'] === false && isset($parsed_body['result']['token']) ) {
            $this->jwt_token = $parsed_body['result']['token'];
            // You should ideally get expires_in from the API response or decode JWT for 'exp'
            $this->jwt_token_expires_at = time() + 3500; // Store with a small buffer (approx 1 hour)
            return $this->jwt_token;
        } else {
            $error_message = __( 'Unknown error during authentication.', 'everydaymoney-gateway');
            if(isset($parsed_body['result']['message'])) {
                $error_message = is_array($parsed_body['result']['message']) ? implode(', ', $parsed_body['result']['message']) : $parsed_body['result']['message'];
            } elseif (isset($parsed_body['message'])) {
                 $error_message = is_array($parsed_body['message']) ? implode(', ', $parsed_body['message']) : $parsed_body['message'];
            }
            wc_get_logger()->error( 'Everydaymoney Gateway: JWT Auth Failed. Code: ' . $http_code . ' Message: ' . esc_html($error_message) . ' Raw Body: ' . $body, array( 'source' => $this->id ) );
            return false;
        }
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // 1. Authenticate and get JWT
        $jwt = $this->get_jwt_token();
        if ( ! $jwt ) {
            wc_add_notice( __( 'Payment error: Could not authenticate with the payment provider. Please try again or contact support.', 'everydaymoney-gateway' ), 'error' );
            return array(
                'result'   => 'failure',
            );
        }

        // 2. Prepare data for the woocommerce-charge endpoint
        $order_lines = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $product_price_per_unit = $order->get_line_subtotal( $item, false, false ) / $item->get_quantity();
            $order_lines[] = array(
                // 'productVariantId' => null, // As products are not on your system, this is optional
                'itemName'  => $item->get_name(),
                'quantity'  => $item->get_quantity(),
                'amount'    => round( $product_price_per_unit, wc_get_price_decimals() ), // Price per unit
            );
        }

        // Add shipping as a line item if present and has a cost
        if ((float)$order->get_shipping_total() > 0) {
            $order_lines[] = array(
                'itemName' => __( 'Shipping: ', 'everydaymoney-gateway' ) . $order->get_shipping_method(),
                'quantity' => 1,
                'amount'   => (float) $order->get_shipping_total(),
            );
        }

        // Add fees as line items if present
        foreach ($order->get_fees() as $fee_id => $fee) {
            $order_lines[] = array(
                'itemName' => $fee->get_name(),
                'quantity' => 1,
                'amount'   => (float) $fee->get_total(),
            );
        }

        $payload = array(
            'currency'      => $order->get_currency(),
            'email'         => $order->get_billing_email(),
            'phone'         => $order->get_billing_phone(),
            'customerName'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customerKey'   => 'wc_user_' . $order->get_customer_id(), // Example customer key (0 if guest)
            'narration'     => sprintf( __( 'Order #%s from %s', 'everydaymoney-gateway' ), $order->get_order_number(), get_bloginfo( 'name' ) ),
            'transactionRef'=> 'WC-' . $order->get_order_number() . '-' . time(), // Unique transaction ref for your system
            'referenceKey'  => $order->get_order_key(), // WooCommerce order key, useful for matching
            'redirectUrl'   => $this->get_return_url( $order ), // WC Thank you page
            'webhookUrl'    => WC()->api_request_url( 'wc_everydaymoney_gateway' ), // Webhook URL
            'orderLines'    => $order_lines,
            // 'inclusive' => false, // Or make this a setting if applicable
        );
        
        $charge_url = $this->api_base_url . '/payment/checkout/woocommerce-charge'; // Your new endpoint

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $jwt,
            ),
            'body'    => json_encode( $payload ),
            'timeout' => 45,
        );

        // wc_get_logger()->debug( 'Everydaymoney Gateway: Create Charge URL: ' . $charge_url, array( 'source' => $this->id ) );
        // wc_get_logger()->debug( 'Everydaymoney Gateway: Create Charge Payload: ' . print_r($payload, true), array( 'source' => $this->id ) );

        $response = wp_remote_post( $charge_url, $args );

        if ( is_wp_error( $response ) ) {
            wc_get_logger()->error( 'Everydaymoney Gateway: Charge Creation WP_Error: ' . $response->get_error_message(), array( 'source' => $this->id ) );
            wc_add_notice( __( 'Payment error: Could not connect to payment provider. ', 'everydaymoney-gateway' ) . esc_html($response->get_error_message()), 'error' );
            return array( 'result' => 'failure' );
        }

        $body = wp_remote_retrieve_body( $response );
        $http_code = wp_remote_retrieve_response_code( $response );
        $parsed_body = json_decode( $body, true );

        // wc_get_logger()->debug( 'Everydaymoney Gateway: Create Charge Response Code: ' . $http_code, array( 'source' => $this->id ) );
        // wc_get_logger()->debug( 'Everydaymoney Gateway: Create Charge Response Body: ' . $body, array( 'source' => $this->id ) );

        if ( $http_code === 201 && isset($parsed_body['isError']) && $parsed_body['isError'] === false && isset( $parsed_body['result']['checkoutURL'] ) ) {
            // Mark order as pending payment (or on-hold)
            $order->update_status( 'pending', __( 'Awaiting payment via Everydaymoney.', 'everydaymoney-gateway' ) );

            // Reduce stock levels
            if (function_exists('wc_reduce_stock_levels')) {
                wc_reduce_stock_levels( $order_id );
            }

            // Remove cart
            WC()->cart->empty_cart();

            // Return success and redirect URL
            return array(
                'result'   => 'success',
                'redirect' => $parsed_body['result']['checkoutURL'],
            );
        } else {
            $error_message = __( 'Unknown error creating payment.', 'everydaymoney-gateway');
             if(isset($parsed_body['result']['message'])) {
                $error_message = is_array($parsed_body['result']['message']) ? implode(', ', $parsed_body['result']['message']) : $parsed_body['result']['message'];
            } elseif (isset($parsed_body['message'])) {
                 $error_message = is_array($parsed_body['message']) ? implode(', ', $parsed_body['message']) : $parsed_body['message'];
            }
            wc_get_logger()->error( 'Everydaymoney Gateway: Charge Creation Failed. Code: ' . $http_code . ' Message: ' . esc_html($error_message) . ' Raw Body: ' . $body, array( 'source' => $this->id ) );
            wc_add_notice( __( 'Payment error: ', 'everydaymoney-gateway' ) . esc_html( $error_message ), 'error' );
            return array( 'result'   => 'failure' );
        }
    }

    /**
     * Output for the order received page (thank you page).
     */
    public function thankyou_page_content() {
        // Optional: Add custom message on the thank you page if needed.
        // This page is shown when the user returns from the payment gateway
        // *before* any webhook might have confirmed the payment.
        echo '<p>' . esc_html__( 'Thank you. Your order is being processed. You will receive an email confirmation shortly once payment is verified.', 'everydaymoney-gateway' ) . '</p>';
    }

    /**
     * Handle IPN/Webhook from your payment gateway
     * This is crucial for updating order status when payment is confirmed asynchronously.
     */
    public function handle_webhook() {
        // wc_get_logger()->info( 'Everydaymoney Webhook: Received.', array( 'source' => $this->id ) );
        // $raw_post = file_get_contents( 'php://input' );
        // $payload = json_decode( $raw_post, true );

        // if ( empty($payload) ) {
        //     wc_get_logger()->warning( 'Everydaymoney Webhook: Empty payload.', array( 'source' => $this->id ) );
        //     status_header(400);
        //     exit('Empty payload');
        // }
        
        // wc_get_logger()->debug( 'Everydaymoney Webhook Payload: ' . print_r( $payload, true ), array( 'source' => $this->id ) );

        // TODO:
        // 1. Validate the webhook signature (essential for security).
        //    Your API should sign the webhook payload, and you verify it here using a shared secret.
        //
        // 2. Extract necessary information (e.g., transaction reference, status).
        //    Let's assume payload has: $payload['transactionRef'] and $payload['status']
        //    And $payload['transactionRef'] matches the 'transactionRef' you sent when creating the charge.
        //
        //    $transaction_ref_from_webhook = isset($payload['transactionRef']) ? sanitize_text_field($payload['transactionRef']) : null;
        //    $payment_status_from_webhook = isset($payload['status']) ? strtoupper(sanitize_text_field($payload['status'])) : null;
        //
        //    if ( ! $transaction_ref_from_webhook ) {
        //        wc_get_logger()->error( 'Everydaymoney Webhook: Missing transaction reference.', array( 'source' => $this->id ) );
        //        status_header(400);
        //        exit('Missing transaction reference');
        //    }
        //
        // 3. Find the WooCommerce order.
        //    You might need to query orders by post meta if you stored `transactionRef` there, or parse order ID from it.
        //    Example: If 'WC-ORDERID-TIMESTAMP' was your transactionRef:
        //    $parts = explode('-', $transaction_ref_from_webhook);
        //    $order_id = ($parts[0] === 'WC' && isset($parts[1]) && is_numeric($parts[1])) ? intval($parts[1]) : null;
        //
        //    if ( ! $order_id ) {
        //         wc_get_logger()->error( 'Everydaymoney Webhook: Could not parse order ID from transactionRef: ' . $transaction_ref_from_webhook, array( 'source' => $this->id ) );
        //         status_header(400);
        //         exit('Invalid transaction reference format');
        //    }
        //
        //    $order = wc_get_order( $order_id );
        //
        //    if ( ! $order ) {
        //        wc_get_logger()->error( 'Everydaymoney Webhook: Order not found for ID: ' . $order_id, array( 'source' => $this->id ) );
        //        status_header(404); // Or 200 to stop retries if order genuinely not found
        //        exit('Order not found');
        //    }
        //
        // 4. Update order status based on payment status.
        //    if ( $order->get_status() === 'completed' || $order->get_status() === 'processing' ) {
        //        wc_get_logger()->info( 'Everydaymoney Webhook: Order ' . $order->get_order_number() . ' already processed.', array( 'source' => $this->id ) );
        //        status_header(200);
        //        exit('Order already processed');
        //    }
        //
        //    if ( $payment_status_from_webhook === 'SUCCESS' || $payment_status_from_webhook === 'COMPLETED' ) { // Adjust to your API's success status
        //        $order->payment_complete( $transaction_ref_from_webhook ); // Pass gateway's transaction ID
        //        $order->add_order_note( __( 'Everydaymoney payment completed. Transaction ID: ', 'everydaymoney-gateway' ) . $transaction_ref_from_webhook );
        //        wc_get_logger()->info( 'Everydaymoney Webhook: Order ' . $order->get_order_number() . ' marked as payment complete.', array( 'source' => $this->id ) );
        //    } elseif ( $payment_status_from_webhook === 'FAILED' || $payment_status_from_webhook === 'CANCELLED' ) { // Adjust to your API's failure statuses
        //        $order->update_status( 'failed', __( 'Everydaymoney payment failed or was cancelled. Transaction ID: ', 'everydaymoney-gateway' ) . $transaction_ref_from_webhook );
        //        wc_get_logger()->info( 'Everydaymoney Webhook: Order ' . $order->get_order_number() . ' marked as failed.', array( 'source' => $this->id ) );
        //    } else {
        //        wc_get_logger()->info( 'Everydaymoney Webhook: Unhandled payment status for order ' . $order->get_order_number() . ': ' . $payment_status_from_webhook, array( 'source' => $this->id ) );
        //    }
        //
        // status_header(200); // Respond to the gateway that you've received it.
        // exit('Webhook processed.');
    }
}