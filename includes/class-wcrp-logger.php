<?php
defined('ABSPATH') || exit;

class WCRP_Logger {
    
    private static $logger = null;
    private static $context = array('source' => 'wc-russian-post');
    
    public static function log($message, $level = 'info') {
        if (self::$logger === null && function_exists('wc_get_logger')) {
            self::$logger = wc_get_logger();
        }
        
        if (self::$logger) {
            switch ($level) {
                case 'debug':
                    self::$logger->debug($message, self::$context);
                    break;
                case 'info':
                    self::$logger->info($message, self::$context);
                    break;
                case 'warning':
                    self::$logger->warning($message, self::$context);
                    break;
                case 'error':
                    self::$logger->error($message, self::$context);
                    break;
                default:
                    self::$logger->info($message, self::$context);
            }
        } else {
            $log_dir = WCRP_PLUGIN_DIR . 'logs/';
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            
            $log_file = $log_dir . 'wcrp-' . date('Y-m-d') . '.log';
            $timestamp = date('Y-m-d H:i:s');
            $formatted = "[{$timestamp}] [{$level}] {$message}\n";
            
            file_put_contents($log_file, $formatted, FILE_APPEND | LOCK_EX);
        }
    }
    
    public static function debug($message) {
        self::log($message, 'debug');
    }
    
    public static function info($message) {
        self::log($message, 'info');
    }
    
    public static function warning($message) {
        self::log($message, 'warning');
    }
    
    public static function error($message) {
        self::log($message, 'error');
    }
}
