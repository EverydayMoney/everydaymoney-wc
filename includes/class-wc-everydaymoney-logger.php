<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Everydaymoney Logger.
 *
 * @class       WC_Everydaymoney_Logger
 * @version     1.1.0
 */
class WC_Everydaymoney_Logger {

    /**
     * @var WC_Logger Logger instance
     */
    private static $wc_logger;

    /**
     * @var bool Debug mode enabled/disabled
     */
    private $debug_enabled = false;

    /**
     * Constructor.
     *
     * @param bool $debug_enabled Whether debug logging is enabled.
     */
    public function __construct( $debug_enabled = false ) {
        $this->debug_enabled = (bool) $debug_enabled;
    }

    /**
     * Get the WooCommerce logger instance.
     *
     * @return WC_Logger
     */
    private static function get_wc_logger() {
        if ( null === self::$wc_logger ) {
            self::$wc_logger = wc_get_logger();
        }
        return self::$wc_logger;
    }

    /**
     * Log a message.
     *
     * @param string $message Message to log.
     * @param string $level   Log level (debug, info, notice, warning, error, critical, alert, emergency).
     */
    public function log( $message, $level = 'info' ) {
        if ( ! $this->debug_enabled && 'debug' === $level ) {
            return;
        }
        if ( ! $this->debug_enabled && 'info' === $level && ! apply_filters( 'wc_everydaymoney_force_log_info', false ) ) {
            return;
        }

        $logger = self::get_wc_logger();
        $context = array( 'source' => 'everydaymoney-gateway' );

        $message_to_log = is_scalar($message) ? $message : print_r($message, true);

        switch ( $level ) {
            case 'emergency': $logger->emergency( $message_to_log, $context ); break;
            case 'alert':     $logger->alert( $message_to_log, $context );     break;
            case 'critical':  $logger->critical( $message_to_log, $context );  break;
            case 'error':     $logger->error( $message_to_log, $context );     break;
            case 'warning':   $logger->warning( $message_to_log, $context );   break;
            case 'notice':    $logger->notice( $message_to_log, $context );    break;
            case 'info':      $logger->info( $message_to_log, $context );      break;
            case 'debug':
            default:
                if ($this->debug_enabled) {
                     $logger->debug( $message_to_log, $context );
                }
                break;
        }
    }
}