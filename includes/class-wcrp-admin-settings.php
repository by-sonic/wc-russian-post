<?php
defined('ABSPATH') || exit;

class WCRP_Admin_Settings {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wcrp_test_tariff', array($this, 'ajax_test_tariff'));
        add_action('wp_ajax_wcrp_test_otpravka', array($this, 'ajax_test_otpravka'));
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_order_meta'));
    }
    
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('Почта России', 'wc-russian-post'),
            __('Почта России', 'wc-russian-post'),
            'manage_woocommerce',
            'wc-russian-post',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting('wcrp_settings', 'wcrp_sender_index');
        register_setting('wcrp_settings', 'wcrp_sender_city');
        register_setting('wcrp_settings', 'wcrp_otpravka_token');
        register_setting('wcrp_settings', 'wcrp_tracking_api_key');
        register_setting('wcrp_settings', 'wcrp_default_package');
        register_setting('wcrp_settings', 'wcrp_auto_create_orders');
        register_setting('wcrp_settings', 'wcrp_create_on_status');
        register_setting('wcrp_settings', 'wcrp_email_tracking');
    }
    
    public function render_settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="wrap wcrp-settings-wrap">
            <h1><?php esc_html_e('Настройки Почты России', 'wc-russian-post'); ?></h1>
            
            <nav class="nav-tab-wrapper wcrp-nav-tabs">
                <a href="?page=wc-russian-post&tab=general" 
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Основные', 'wc-russian-post'); ?>
                </a>
                <a href="?page=wc-russian-post&tab=api" 
                   class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('API интеграция', 'wc-russian-post'); ?>
                </a>
                <a href="?page=wc-russian-post&tab=orders" 
                   class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Заказы', 'wc-russian-post'); ?>
                </a>
                <a href="?page=wc-russian-post&tab=tracking" 
                   class="nav-tab <?php echo $active_tab === 'tracking' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Отслеживание', 'wc-russian-post'); ?>
                </a>
            </nav>
            
            <form method="post" action="options.php">
                <?php settings_fields('wcrp_settings'); ?>
                
                <div class="wcrp-tab-content">
                    <?php
                    switch ($active_tab) {
                        case 'api':
                            $this->render_api_tab();
                            break;
                        case 'orders':
                            $this->render_orders_tab();
                            break;
                        case 'tracking':
                            $this->render_tracking_tab();
                            break;
                        default:
                            $this->render_general_tab();
                    }
                    ?>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    private function render_general_tab() {
        ?>
        <h2><?php esc_html_e('Настройки отправителя', 'wc-russian-post'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wcrp_sender_index"><?php esc_html_e('Почтовый индекс отправителя', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="wcrp_sender_index" 
                           name="wcrp_sender_index" 
                           value="<?php echo esc_attr(get_option('wcrp_sender_index')); ?>" 
                           class="regular-text"
                           pattern="[0-9]{6}"
                           placeholder="101000">
                    <p class="description"><?php esc_html_e('6-значный почтовый индекс вашего склада/офиса', 'wc-russian-post'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wcrp_sender_city"><?php esc_html_e('Город отправителя', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="wcrp_sender_city" 
                           name="wcrp_sender_city" 
                           value="<?php echo esc_attr(get_option('wcrp_sender_city')); ?>" 
                           class="regular-text"
                           placeholder="Москва">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wcrp_default_package"><?php esc_html_e('Упаковка по умолчанию', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <select id="wcrp_default_package" name="wcrp_default_package">
                        <?php
                        $current = get_option('wcrp_default_package', '10');
                        foreach (WCRP_Tariff_API::get_package_types() as $code => $name) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($code),
                                selected($current, $code, false),
                                esc_html($name)
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Тест расчета стоимости', 'wc-russian-post'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Проверить тариф', 'wc-russian-post'); ?></th>
                <td>
                    <div class="wcrp-test-form">
                        <input type="text" id="wcrp_test_to" placeholder="<?php esc_attr_e('Индекс получателя', 'wc-russian-post'); ?>" class="small-text">
                        <input type="number" id="wcrp_test_weight" placeholder="<?php esc_attr_e('Вес (г)', 'wc-russian-post'); ?>" class="small-text" value="1000">
                        <button type="button" class="button" id="wcrp_test_tariff_btn"><?php esc_html_e('Рассчитать', 'wc-russian-post'); ?></button>
                    </div>
                    <div id="wcrp_tariff_result" class="wcrp-test-result"></div>
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wcrp_test_tariff_btn').on('click', function() {
                var btn = $(this);
                var resultDiv = $('#wcrp_tariff_result');
                
                btn.prop('disabled', true);
                resultDiv.html('<?php echo esc_js(__('Загрузка...', 'wc-russian-post')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcrp_test_tariff',
                        to: $('#wcrp_test_to').val(),
                        weight: $('#wcrp_test_weight').val(),
                        nonce: '<?php echo wp_create_nonce('wcrp_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        } else {
                            resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        resultDiv.html('<div class="notice notice-error"><p><?php echo esc_js(__('Ошибка соединения', 'wc-russian-post')); ?></p></div>');
                    },
                    complete: function() {
                        btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    private function render_api_tab() {
        ?>
        <h2><?php esc_html_e('API Отправка (otpravka.pochta.ru)', 'wc-russian-post'); ?></h2>
        <p class="description">
            <?php 
            printf(
                esc_html__('Получите токен в %sличном кабинете Отправки%s', 'wc-russian-post'),
                '<a href="https://otpravka.pochta.ru/settings" target="_blank">',
                '</a>'
            ); 
            ?>
        </p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wcrp_otpravka_token"><?php esc_html_e('Access Token', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <input type="password" 
                           id="wcrp_otpravka_token" 
                           name="wcrp_otpravka_token" 
                           value="<?php echo esc_attr(get_option('wcrp_otpravka_token')); ?>" 
                           class="large-text">
                    <p class="description"><?php esc_html_e('Токен для создания отправлений и работы с API', 'wc-russian-post'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Проверить подключение', 'wc-russian-post'); ?></th>
                <td>
                    <button type="button" class="button" id="wcrp_test_otpravka_btn"><?php esc_html_e('Тест подключения', 'wc-russian-post'); ?></button>
                    <span id="wcrp_otpravka_result" class="wcrp-test-result"></span>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('API отслеживания (gdeposylka.ru)', 'wc-russian-post'); ?></h2>
        <p class="description">
            <?php 
            printf(
                esc_html__('Получите бесплатный ключ на %sgdeposylka.ru%s', 'wc-russian-post'),
                '<a href="https://gdeposylka.ru/tracking-api/api-v4" target="_blank">',
                '</a>'
            ); 
            ?>
        </p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wcrp_tracking_api_key"><?php esc_html_e('API ключ', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <input type="password" 
                           id="wcrp_tracking_api_key" 
                           name="wcrp_tracking_api_key" 
                           value="<?php echo esc_attr(get_option('wcrp_tracking_api_key')); ?>" 
                           class="large-text">
                    <p class="description"><?php esc_html_e('Для автоматического отслеживания посылок', 'wc-russian-post'); ?></p>
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wcrp_test_otpravka_btn').on('click', function() {
                var btn = $(this);
                var resultSpan = $('#wcrp_otpravka_result');
                
                btn.prop('disabled', true);
                resultSpan.html('<?php echo esc_js(__('Проверка...', 'wc-russian-post')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcrp_test_otpravka',
                        nonce: '<?php echo wp_create_nonce('wcrp_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            resultSpan.html('<span style="color:green;">' + response.data.message + '</span>');
                        } else {
                            resultSpan.html('<span style="color:red;">' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        resultSpan.html('<span style="color:red;"><?php echo esc_js(__('Ошибка соединения', 'wc-russian-post')); ?></span>');
                    },
                    complete: function() {
                        btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    private function render_orders_tab() {
        ?>
        <h2><?php esc_html_e('Автоматическое создание отправлений', 'wc-russian-post'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wcrp_auto_create_orders"><?php esc_html_e('Автосоздание', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               id="wcrp_auto_create_orders" 
                               name="wcrp_auto_create_orders" 
                               value="yes"
                               <?php checked(get_option('wcrp_auto_create_orders'), 'yes'); ?>>
                        <?php esc_html_e('Автоматически создавать отправления в Почте России', 'wc-russian-post'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wcrp_create_on_status"><?php esc_html_e('Создавать при статусе', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <select id="wcrp_create_on_status" name="wcrp_create_on_status">
                        <?php
                        $current = get_option('wcrp_create_on_status', 'processing');
                        $statuses = wc_get_order_statuses();
                        foreach ($statuses as $status => $label) {
                            $status_key = str_replace('wc-', '', $status);
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($status_key),
                                selected($current, $status_key, false),
                                esc_html($label)
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }
    
    private function render_tracking_tab() {
        ?>
        <h2><?php esc_html_e('Настройки отслеживания', 'wc-russian-post'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wcrp_email_tracking"><?php esc_html_e('Email уведомления', 'wc-russian-post'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               id="wcrp_email_tracking" 
                               name="wcrp_email_tracking" 
                               value="yes"
                               <?php checked(get_option('wcrp_email_tracking', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Отправлять email с трек-номером покупателю', 'wc-russian-post'); ?>
                    </label>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Шорткод для страницы отслеживания', 'wc-russian-post'); ?></h2>
        <p><?php esc_html_e('Вставьте этот шорткод на любую страницу:', 'wc-russian-post'); ?></p>
        <code>[wcrp_tracking]</code>
        
        <h3><?php esc_html_e('Параметры шорткода:', 'wc-russian-post'); ?></h3>
        <ul>
            <li><code>title</code> - <?php esc_html_e('Заголовок формы', 'wc-russian-post'); ?></li>
            <li><code>button_text</code> - <?php esc_html_e('Текст кнопки', 'wc-russian-post'); ?></li>
            <li><code>placeholder</code> - <?php esc_html_e('Placeholder поля ввода', 'wc-russian-post'); ?></li>
        </ul>
        <p><?php esc_html_e('Пример:', 'wc-russian-post'); ?> <code>[wcrp_tracking title="Отследить заказ" button_text="Найти"]</code></p>
        <?php
    }
    
    public function ajax_test_tariff() {
        check_ajax_referer('wcrp_admin_nonce', 'nonce');
        
        $to = isset($_POST['to']) ? sanitize_text_field($_POST['to']) : '';
        $weight = isset($_POST['weight']) ? intval($_POST['weight']) : 1000;
        
        if (empty($to)) {
            wp_send_json_error(array('message' => __('Укажите индекс получателя', 'wc-russian-post')));
        }
        
        $result = WCRP_Tariff_API::calculate(array(
            'to' => $to,
            'weight' => $weight,
        ));
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        $message = sprintf(
            __('Стоимость: %s руб. Срок доставки: %s', 'wc-russian-post'),
            number_format($result['cost_nds'], 2, '.', ' '),
            WCRP_Tariff_API::get_delivery_time_text($result['delivery_min'], $result['delivery_max'])
        );
        
        wp_send_json_success(array('message' => $message));
    }
    
    public function ajax_test_otpravka() {
        check_ajax_referer('wcrp_admin_nonce', 'nonce');
        
        $api = new WCRP_Otpravka_API();
        
        if (!$api->is_configured()) {
            wp_send_json_error(array('message' => __('Токен не настроен', 'wc-russian-post')));
        }
        
        $result = $api->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => __('Подключение успешно!', 'wc-russian-post')));
    }
    
    public function add_order_meta_box() {
        add_meta_box(
            'wcrp_order_tracking',
            __('Почта России', 'wc-russian-post'),
            array($this, 'render_order_meta_box'),
            'shop_order',
            'side',
            'high'
        );
        
        add_meta_box(
            'wcrp_order_tracking',
            __('Почта России', 'wc-russian-post'),
            array($this, 'render_order_meta_box'),
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }
    
    public function render_order_meta_box($post_or_order) {
        $order_id = is_a($post_or_order, 'WC_Order') ? $post_or_order->get_id() : $post_or_order->ID;
        $shipment_info = WCRP_Order_Sync::get_order_shipment_info($order_id);
        
        wp_nonce_field('wcrp_order_meta', 'wcrp_order_nonce');
        ?>
        <div class="wcrp-order-meta">
            <p>
                <label for="wcrp_track_number"><strong><?php esc_html_e('Трек-номер:', 'wc-russian-post'); ?></strong></label>
                <input type="text" 
                       id="wcrp_track_number" 
                       name="wcrp_track_number" 
                       value="<?php echo esc_attr($shipment_info['track_number']); ?>" 
                       class="widefat">
            </p>
            
            <?php if (!empty($shipment_info['track_number'])) : ?>
                <p>
                    <a href="<?php echo esc_url(WCRP_Tracking_API::get_tracking_url($shipment_info['track_number'])); ?>" 
                       target="_blank" class="button">
                        <?php esc_html_e('Отследить на Почте России', 'wc-russian-post'); ?>
                    </a>
                </p>
            <?php endif; ?>
            
            <?php if (!empty($shipment_info['shipment_id'])) : ?>
                <p>
                    <small><?php esc_html_e('ID отправления:', 'wc-russian-post'); ?> <?php echo esc_html($shipment_info['shipment_id']); ?></small>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function save_order_meta($order_id) {
        if (!isset($_POST['wcrp_order_nonce']) || !wp_verify_nonce($_POST['wcrp_order_nonce'], 'wcrp_order_meta')) {
            return;
        }
        
        if (isset($_POST['wcrp_track_number'])) {
            $track_number = sanitize_text_field($_POST['wcrp_track_number']);
            $current = get_post_meta($order_id, '_wcrp_track_number', true);
            
            if ($track_number !== $current) {
                WCRP_Order_Sync::set_track_number($order_id, $track_number);
            }
        }
    }
}

new WCRP_Admin_Settings();
