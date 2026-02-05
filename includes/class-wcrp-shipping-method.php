<?php
defined('ABSPATH') || exit;

class WCRP_Shipping_Method extends WC_Shipping_Method {
    
    public function __construct($instance_id = 0) {
        $this->id = 'russian_post';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Почта России', 'wc-russian-post');
        $this->method_description = __('Доставка Почтой России с автоматическим расчетом стоимости', 'wc-russian-post');
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );
        
        $this->init();
    }
    
    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option('title', $this->method_title);
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->shipping_type = $this->get_option('shipping_type', '27030');
        $this->free_shipping_min = $this->get_option('free_shipping_min', 0);
        $this->handling_fee = $this->get_option('handling_fee', 0);
        $this->handling_fee_percent = $this->get_option('handling_fee_percent', 0);
        $this->show_delivery_time = $this->get_option('show_delivery_time', 'yes');
        $this->fallback_cost = $this->get_option('fallback_cost', 0);
        
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }
    
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => __('Включить', 'wc-russian-post'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Название метода', 'wc-russian-post'),
                'type' => 'text',
                'default' => __('Почта России', 'wc-russian-post'),
                'desc_tip' => true,
                'description' => __('Название метода доставки в корзине', 'wc-russian-post'),
            ),
            'shipping_type' => array(
                'title' => __('Тип отправления', 'wc-russian-post'),
                'type' => 'select',
                'default' => '27030',
                'options' => WCRP_Tariff_API::get_object_types(),
                'description' => __('Выберите тип почтового отправления', 'wc-russian-post'),
            ),
            'package_type' => array(
                'title' => __('Тип упаковки', 'wc-russian-post'),
                'type' => 'select',
                'default' => '10',
                'options' => WCRP_Tariff_API::get_package_types(),
                'description' => __('Тип упаковки по умолчанию', 'wc-russian-post'),
            ),
            'free_shipping_min' => array(
                'title' => __('Бесплатная доставка от', 'wc-russian-post'),
                'type' => 'price',
                'default' => 0,
                'description' => __('Минимальная сумма заказа для бесплатной доставки (0 = отключено)', 'wc-russian-post'),
                'desc_tip' => true,
            ),
            'handling_fee' => array(
                'title' => __('Фиксированная наценка', 'wc-russian-post'),
                'type' => 'price',
                'default' => 0,
                'description' => __('Дополнительная фиксированная сумма к стоимости доставки', 'wc-russian-post'),
                'desc_tip' => true,
            ),
            'handling_fee_percent' => array(
                'title' => __('Процентная наценка', 'wc-russian-post'),
                'type' => 'number',
                'default' => 0,
                'description' => __('Дополнительный процент к стоимости доставки', 'wc-russian-post'),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => 0,
                    'step' => 1,
                ),
            ),
            'show_delivery_time' => array(
                'title' => __('Показывать срок доставки', 'wc-russian-post'),
                'type' => 'checkbox',
                'default' => 'yes',
                'label' => __('Отображать ориентировочный срок доставки', 'wc-russian-post'),
            ),
            'fallback_cost' => array(
                'title' => __('Стоимость при ошибке', 'wc-russian-post'),
                'type' => 'price',
                'default' => 0,
                'description' => __('Фиксированная стоимость, если API недоступен (0 = скрыть метод)', 'wc-russian-post'),
                'desc_tip' => true,
            ),
            'declared_value' => array(
                'title' => __('Объявленная ценность', 'wc-russian-post'),
                'type' => 'select',
                'default' => 'none',
                'options' => array(
                    'none' => __('Не использовать', 'wc-russian-post'),
                    'full' => __('Полная стоимость заказа', 'wc-russian-post'),
                    'partial' => __('50% от стоимости', 'wc-russian-post'),
                ),
                'description' => __('Объявленная ценность для страхования', 'wc-russian-post'),
            ),
        );
    }
    
    public function calculate_shipping($package = array()) {
        if ($this->enabled !== 'yes') {
            return;
        }
        
        $destination = $package['destination'];
        $postcode = isset($destination['postcode']) ? $destination['postcode'] : '';
        
        if (empty($postcode)) {
            $this->add_fallback_rate(__('Укажите индекс для расчета', 'wc-russian-post'));
            return;
        }
        
        $sender_index = get_option('wcrp_sender_index', '');
        if (empty($sender_index)) {
            WCRP_Logger::warning('Sender postal code not configured');
            $this->add_fallback_rate();
            return;
        }
        
        $total_weight = $this->get_package_weight($package);
        $cart_total = $this->get_package_total($package);
        
        if ($this->free_shipping_min > 0 && $cart_total >= $this->free_shipping_min) {
            $this->add_rate(array(
                'id' => $this->get_rate_id(),
                'label' => $this->title . ' ' . __('(Бесплатно)', 'wc-russian-post'),
                'cost' => 0,
                'package' => $package,
            ));
            return;
        }
        
        $declared_value = $this->get_declared_value($cart_total);
        
        $result = WCRP_Tariff_API::calculate(array(
            'from' => $sender_index,
            'to' => $postcode,
            'weight' => $total_weight,
            'object' => $this->get_option('shipping_type', '27030'),
            'pack' => $this->get_option('package_type', '10'),
            'sumoc' => $declared_value,
        ));
        
        if (is_wp_error($result)) {
            WCRP_Logger::error('Tariff calculation failed: ' . $result->get_error_message());
            $this->add_fallback_rate();
            return;
        }
        
        $cost = isset($result['cost_nds']) ? $result['cost_nds'] : 0;
        
        $handling_fee = floatval($this->get_option('handling_fee', 0));
        $handling_percent = floatval($this->get_option('handling_fee_percent', 0));
        
        if ($handling_fee > 0) {
            $cost += $handling_fee;
        }
        
        if ($handling_percent > 0) {
            $cost += $cost * ($handling_percent / 100);
        }
        
        $label = $this->title;
        
        if ($this->get_option('show_delivery_time', 'yes') === 'yes') {
            $delivery_min = isset($result['delivery_min']) ? $result['delivery_min'] : 0;
            $delivery_max = isset($result['delivery_max']) ? $result['delivery_max'] : 0;
            
            if ($delivery_min > 0 && $delivery_max > 0) {
                $delivery_text = WCRP_Tariff_API::get_delivery_time_text($delivery_min, $delivery_max);
                $label .= ' (' . $delivery_text . ')';
            }
        }
        
        $this->add_rate(array(
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => $cost,
            'package' => $package,
            'meta_data' => array(
                '_wcrp_delivery_min' => isset($result['delivery_min']) ? $result['delivery_min'] : 0,
                '_wcrp_delivery_max' => isset($result['delivery_max']) ? $result['delivery_max'] : 0,
                '_wcrp_shipping_type' => $this->get_option('shipping_type', '27030'),
            ),
        ));
    }
    
    private function get_package_weight($package) {
        $total_weight = 0;
        
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $quantity = $item['quantity'];
            
            $weight = $product->get_weight();
            
            if ($weight) {
                $weight_in_grams = wc_get_weight($weight, 'g');
                $total_weight += $weight_in_grams * $quantity;
            } else {
                $total_weight += 100 * $quantity;
            }
        }
        
        return max($total_weight, 100);
    }
    
    private function get_package_total($package) {
        $total = 0;
        
        foreach ($package['contents'] as $item) {
            $total += $item['line_total'];
        }
        
        return $total;
    }
    
    private function get_declared_value($cart_total) {
        $declared_value_option = $this->get_option('declared_value', 'none');
        
        switch ($declared_value_option) {
            case 'full':
                return $cart_total;
            case 'partial':
                return $cart_total * 0.5;
            default:
                return 0;
        }
    }
    
    private function add_fallback_rate($message = '') {
        $fallback_cost = floatval($this->get_option('fallback_cost', 0));
        
        if ($fallback_cost <= 0) {
            return;
        }
        
        $label = $this->title;
        if (!empty($message)) {
            $label .= ' - ' . $message;
        }
        
        $this->add_rate(array(
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => $fallback_cost,
        ));
    }
}
