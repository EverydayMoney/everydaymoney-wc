<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Everydaymoney API Handler
 *
 * @class       WC_Everydaymoney_API
 * @version     1.0.1
 */
class WC_Everydaymoney_API {

    private $gateway;
    private $api_base_url;
    private $logger;

    public function __construct( $gateway ) {
        $this->gateway      = $gateway;
        $this->logger       = new WC_Everydaymoney_Logger( $this->gateway->debug );
        $this->api_base_url = rtrim( EVERYDAYMONEY_GATEWAY_API_URL, '/' );

        if ( $this->gateway->test_mode ) {
            $test_api_url = defined('EVERYDAYMONEY_GATEWAY_TEST_API_URL') ? EVERYDAYMONEY_GATEWAY_TEST_API_URL : 'https://em-api-staging.everydaymoney.app';
            $this->api_base_url = apply_filters( 'wc_everydaymoney_test_api_url', rtrim($test_api_url, '/') );
            $this->logger->log( 'API Handler initialized in Test Mode. API Base URL: ' . $this->api_base_url, 'debug' );
        } else {
            $this->logger->log( 'API Handler initialized in Live Mode. API Base URL: ' . $this->api_base_url, 'debug' );
        }
    }

    private function get_jwt_token() {
        $transient_key = 'everydaymoney_token_' . md5( $this->gateway->public_key );
        $cached_token = get_transient( $transient_key );

        if ( false !== $cached_token ) {
            $this->logger->log( 'Retrieved JWT token from cache.', 'debug' );
            return $cached_token;
        }

        if ( empty( $this->gateway->public_key ) || empty( $this->gateway->api_secret ) ) {
            $this->logger->log( 'Missing API credentials. Cannot get JWT.', 'error' );
            return false;
        }

        $login_url = $this->api_base_url . '/auth/business/token';
        $auth_string  = $this->gateway->public_key . ':' . $this->gateway->api_secret;
        $base64_token = base64_encode( $auth_string );

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-Api-Key'     => $this->gateway->public_key,
                'Authorization' => 'Basic ' . $base64_token,
            ),
            'timeout' => 45,
        );

        $this->logger->log( 'Requesting JWT from: ' . $login_url, 'debug' );
        $response = wp_remote_post( $login_url, $args );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'JWT Auth WP_Error: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $http_code   = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $parsed_body = json_decode( $body, true );

        $this->logger->log( 'JWT Auth Response Code: ' . $http_code, 'debug' );
        $this->logger->log( 'JWT Auth Response Body: ' . $body, 'debug' );

        if ( ($http_code === 200 || $http_code === 201) && 
             isset( $parsed_body['isError'] ) && false === $parsed_body['isError'] && 
             isset( $parsed_body['result'] ) && is_string( $parsed_body['result'] ) && !empty( $parsed_body['result'] ) ) {
            
            $jwt_token = $parsed_body['result'];
            $expires_in = 3500;
            
            set_transient( $transient_key, $jwt_token, max(60, $expires_in - 100) );
            $this->logger->log( 'Successfully obtained and cached JWT token. Expires in (defaulted or from API): ' . $expires_in . 's', 'info' );
            return $jwt_token;
        } else {
            $error_message = $this->extract_error_message($parsed_body);
            if (($http_code === 200 || $http_code === 201) && (isset( $parsed_body['isError'] ) && false === $parsed_body['isError'] )) {
                 $this->logger->log( 'JWT Auth Succeeded (HTTP ' . $http_code . ') but failed to parse token from response structure. Body: ' . $body, 'error' );
            } else {
                 $this->logger->log( 'JWT Auth Failed. Code: ' . $http_code . ' Message: ' . esc_html( $error_message ) . ' Raw Body: ' . $body, 'error' );
            }
            return false;
        }
    }

    private function make_api_request( $endpoint, $data = array(), $method = 'POST' ) {
        $jwt_token = $this->get_jwt_token();
        if ( ! $jwt_token ) {
            $this->logger->log( 'API Request Failed: Could not obtain JWT token', 'error' );
            return new WP_Error( 'auth_failed', __( 'Authentication failed. Could not retrieve JWT token.', 'everydaymoney-gateway' ) );
        }

        $url = $this->api_base_url . $endpoint;
        $headers = array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $jwt_token,
        );

        $args = array(
            'method'  => strtoupper( $method ),
            'headers' => $headers,
            'timeout' => 45,
        );

        if ( ! empty( $data ) && ( 'POST' === $args['method'] || 'PUT' === $args['method'] ) ) {
            $args['body'] = wp_json_encode( $data );
        } elseif ( ! empty( $data ) && 'GET' === $args['method'] ) {
            $url = add_query_arg( $data, $url );
        }
        
        $this->logger->log( 'API Request (' . $args['method'] . '): ' . $url, 'info' );
        if ('POST' === $args['method'] || 'PUT' === $args['method']) {
            $this->logger->log( 'API Request Body: ' . $args['body'], 'info' );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'API WP_Error (' . $endpoint . '): ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $http_code   = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $parsed_body = json_decode( $body, true );

        $this->logger->log( 'API Response Code (' . $endpoint . '): ' . $http_code, 'info' );
        $this->logger->log( 'API Response Body (' . $endpoint . '): ' . $body, 'info' );

        if ( ($http_code >= 200 && $http_code < 300) && isset( $parsed_body['isError'] ) && false === $parsed_body['isError'] && isset( $parsed_body['result'] ) ) {
            return $parsed_body['result'];
        } else {
            $error_message = $this->extract_error_message($parsed_body);
            
            // Log the full error details for debugging
            $this->logger->log( 'API Error Details (' . $endpoint . '):', 'error' );
            $this->logger->log( '  HTTP Code: ' . $http_code, 'error' );
            $this->logger->log( '  Error Message: ' . $error_message, 'error' );
            $this->logger->log( '  Full Response: ' . print_r($parsed_body, true), 'error' );
            
            // Check if it's an InternalError
            if (strpos($error_message, 'InternalError') !== false) {
                $this->logger->log( 'InternalError detected. This usually indicates a problem with the request data format or missing required fields.', 'error' );
            }
            
            return new WP_Error( 'api_error', esc_html( $error_message ), array( 'status' => $http_code, 'response_body' => $parsed_body ) );
        }
    }

    private function extract_error_message($parsed_body) {
        $error_message = __( 'An unknown API error occurred.', 'everydaymoney-gateway' );
        
        // Check various possible error message locations
        if ( isset( $parsed_body['result']['message'] ) ) {
            $error_message = is_array( $parsed_body['result']['message'] ) ? implode( ', ', $parsed_body['result']['message'] ) : $parsed_body['result']['message'];
        } elseif ( isset( $parsed_body['message'] ) ) {
            $error_message = is_array( $parsed_body['message'] ) ? implode( ', ', $parsed_body['message'] ) : $parsed_body['message'];
        } elseif (isset($parsed_body['error'])) {
            $error_message = is_array($parsed_body['error']) ? implode( ', ', $parsed_body['error']) : $parsed_body['error'];
        } elseif (isset($parsed_body['result']) && is_string($parsed_body['result'])) {
            // Sometimes the error is just a string in the result field
            $error_message = $parsed_body['result'];
        }
        
        return $error_message;
    }

    public function create_charge( $charge_data ) {
        $this->logger->log( '=== Creating Charge Request ===', 'info' );
        $this->logger->log( 'Charge Data: ' . print_r($charge_data, true), 'info' );
        
        // Validate required fields before sending
        $required_fields = array('currency', 'email', 'orderLines');
        foreach ($required_fields as $field) {
            if (empty($charge_data[$field])) {
                $this->logger->log( 'Missing required field: ' . $field, 'error' );
                return new WP_Error( 'missing_field', sprintf( __( 'Missing required field: %s', 'everydaymoney-gateway' ), $field ) );
            }
        }
        
        // Calculate total from order lines
        $total = 0;
        foreach ($charge_data['orderLines'] as $line) {
            $total += $line['amount'] * $line['quantity'];
        }
        $this->logger->log( 'Calculated total from order lines: ' . $total, 'info' );
        
        return $this->make_api_request( '/payment/checkout/api-charge-order', $charge_data, 'POST' );
    }
    
    public function test_connection() {
        $this->logger->log( 'Testing API connection...', 'info' );
        $transient_key = 'everydaymoney_token_' . md5( $this->gateway->public_key );
        delete_transient( $transient_key );
        
        $jwt = $this->get_jwt_token();
        
        if ($jwt && !is_wp_error($jwt)) {
            $this->logger->log( 'API connection test successful.', 'info' );
            return true;
        } elseif (is_wp_error($jwt)) {
            return $jwt;
        } else {
             return new WP_Error( 'test_connection_failed', __( 'Failed to retrieve JWT token during connection test.', 'everydaymoney-gateway' ) );
        }
    }
}