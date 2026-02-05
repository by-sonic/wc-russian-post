<?php
/**
 * Plugin Name: WooCommerce Russian Post Shipping
 * Plugin URI: https://github.com/by-sonic/wc-russian-post
 * Description: Полная интеграция WooCommerce с Почтой России - расчет стоимости, отслеживание, создание заказов через API
 * Version: 1.0.0
 * Author: by sonic
 * Author URI: https://t.me/by_sonicx
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wc-russian-post
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 *
 * @package WC_Russian_Post
 */

defined('ABSPATH') || exit;

define('WCRP_VERSION', '1.0.0');
define('WCRP_PLUGIN_FILE', __FILE__);
define('WCRP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCRP_PLUGIN_URL', plugin_dir_url(__FILE__));

if (!class_exists('WC_Russian_Post')) {

    class WC_Russian_Post {
        
        private static $instance = null;
        
        public static function instance() {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        private function __construct() {
            $this->init_hooks();
        }
        
        private function init_hooks() {
            add_action('plugins_loaded', array($this, 'init'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
            register_activation_hook(WCRP_PLUGIN_FILE, array($this, 'activate'));
            register_deactivation_hook(WCRP_PLUGIN_FILE, array($this, 'deactivate'));
        }
        
        public function init() {
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
                return;
            }
            
            load_plugin_textdomain('wc-russian-post', false, dirname(plugin_basename(WCRP_PLUGIN_FILE)) . '/languages');
            
            $this->includes();
            
            add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
            add_action('woocommerce_order_status_changed', array('WCRP_Order_Sync', 'on_order_status_changed'), 10, 4);
        }
        
        private function includes() {
            require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-logger.php';
            require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-tariff-api.php';
            require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-otpravka-api.php';
            require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-tracking-api.php';
            require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-shipping-method.php';
            require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-order-sync.php';
            require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-tracking-widget.php';
            
            if (is_admin()) {
                require_once WCRP_PLUGIN_DIR . 'includes/class-wcrp-admin-settings.php';
            }
        }
        
        public function add_shipping_method($methods) {
            $methods['russian_post'] = 'WCRP_Shipping_Method';
            return $methods;
        }
        
        public function woocommerce_missing_notice() {
            echo '<div class="error"><p>' . 
                 esc_html__('WooCommerce Russian Post требует установленный и активный WooCommerce.', 'wc-russian-post') . 
                 '</p></div>';
        }
        
        public function enqueue_frontend_scripts() {
            if (is_order_received_page() || is_account_page()) {
                wp_enqueue_style(
                    'wcrp-tracking',
                    WCRP_PLUGIN_URL . 'assets/css/tracking.css',
                    array(),
                    WCRP_VERSION
                );
                
                wp_enqueue_script(
                    'wcrp-tracking',
                    WCRP_PLUGIN_URL . 'assets/js/tracking.js',
                    array('jquery'),
                    WCRP_VERSION,
                    true
                );
                
                wp_localize_script('wcrp-tracking', 'wcrp_tracking', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wcrp_tracking_nonce'),
                    'i18n' => array(
                        'loading' => __('Загрузка...', 'wc-russian-post'),
                        'error' => __('Ошибка загрузки данных', 'wc-russian-post'),
                    )
                ));
            }
        }
        
        public function enqueue_admin_scripts($hook) {
            if (strpos($hook, 'wc-russian-post') !== false || $hook === 'woocommerce_page_wc-settings') {
                wp_enqueue_style(
                    'wcrp-admin',
                    WCRP_PLUGIN_URL . 'assets/css/admin.css',
                    array(),
                    WCRP_VERSION
                );
                
                wp_enqueue_script(
                    'wcrp-admin',
                    WCRP_PLUGIN_URL . 'assets/js/admin.js',
                    array('jquery'),
                    WCRP_VERSION,
                    true
                );
                
                wp_localize_script('wcrp-admin', 'wcrp_admin', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wcrp_admin_nonce'),
                ));
            }
        }
        
        public function activate() {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'wcrp_tracking';
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                order_id bigint(20) NOT NULL,
                track_number varchar(50) NOT NULL,
                status varchar(100) DEFAULT '',
                last_update datetime DEFAULT NULL,
                tracking_data longtext DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY order_id (order_id),
                KEY track_number (track_number)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            add_option('wcrp_version', WCRP_VERSION);
            
            $default_options = array(
                'wcrp_sender_index' => '',
                'wcrp_sender_city' => '',
                'wcrp_otpravka_token' => '',
                'wcrp_tracking_api_key' => '',
                'wcrp_default_package' => '10',
                'wcrp_auto_create_orders' => 'no',
                'wcrp_email_tracking' => 'yes',
            );
            
            foreach ($default_options as $key => $value) {
                if (get_option($key) === false) {
                    add_option($key, $value);
                }
            }
            
            flush_rewrite_rules();
        }
        
        public function deactivate() {
            flush_rewrite_rules();
        }
    }
    
    function WCRP() {
        return WC_Russian_Post::instance();
    }
    
    WCRP();
}
