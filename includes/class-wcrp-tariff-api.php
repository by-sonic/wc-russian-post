<?php
defined('ABSPATH') || exit;

class WCRP_Tariff_API {
    
    const API_URL = 'https://tariff.pochta.ru/tariff/v1/calculate';
    
    const OBJECT_TYPES = array(
        '27030' => 'Посылка (стандарт)',
        '47030' => 'Посылка (1 класс)',
        '4030'  => 'Заказное письмо',
        '7030'  => 'Ценное письмо',
        '27020' => 'Посылка стандарт (авиа)',
        '47020' => 'Посылка 1 класс (авиа)',
        '23030' => 'Бандероль',
        '3000'  => 'Письмо простое',
        '3020'  => 'Письмо заказное',
        '16010' => 'EMS',
        '30030' => 'Курьер онлайн',
    );
    
    const PACKAGE_TYPES = array(
        '10' => 'Коробка S (до 2 кг)',
        '11' => 'Коробка M (до 5 кг)',
        '12' => 'Коробка L (до 10 кг)',
        '20' => 'Пакет полиэтиленовый',
        '40' => 'Без упаковки',
    );
    
    public static function calculate($params) {
        $defaults = array(
            'from' => get_option('wcrp_sender_index', ''),
            'to' => '',
            'weight' => 0,
            'object' => '27030',
            'pack' => get_option('wcrp_default_package', '10'),
            'sumoc' => 0,
            'sumin' => 0,
        );
        
        $params = wp_parse_args($params, $defaults);
        
        if (empty($params['from']) || empty($params['to']) || $params['weight'] <= 0) {
            return new WP_Error('invalid_params', __('Недостаточно данных для расчета', 'wc-russian-post'));
        }
        
        $params['from'] = preg_replace('/[^0-9]/', '', $params['from']);
        $params['to'] = preg_replace('/[^0-9]/', '', $params['to']);
        
        if (strlen($params['from']) !== 6 || strlen($params['to']) !== 6) {
            return new WP_Error('invalid_index', __('Некорректный почтовый индекс', 'wc-russian-post'));
        }
        
        $query_params = array(
            'json' => '',
            'object' => $params['object'],
            'from' => $params['from'],
            'to' => $params['to'],
            'weight' => intval($params['weight']),
            'pack' => $params['pack'],
        );
        
        if ($params['sumoc'] > 0) {
            $query_params['sumoc'] = intval($params['sumoc'] * 100);
        }
        
        if ($params['sumin'] > 0) {
            $query_params['sumin'] = intval($params['sumin'] * 100);
        }
        
        $url = self::API_URL . '?' . http_build_query($query_params);
        
        WCRP_Logger::debug('Tariff API request: ' . $url);
        
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));
        
        if (is_wp_error($response)) {
            WCRP_Logger::error('Tariff API error: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            WCRP_Logger::error('Tariff API JSON error: ' . json_last_error_msg());
            return new WP_Error('json_error', __('Ошибка парсинга ответа API', 'wc-russian-post'));
        }
        
        if (isset($data['error'])) {
            $error_msg = is_array($data['error']) ? implode(', ', $data['error']) : $data['error'];
            WCRP_Logger::error('Tariff API returned error: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        WCRP_Logger::debug('Tariff API response: ' . $body);
        
        return self::parse_response($data);
    }
    
    private static function parse_response($data) {
        $result = array(
            'cost' => 0,
            'cost_nds' => 0,
            'nds_rate' => 0,
            'delivery_min' => 0,
            'delivery_max' => 0,
            'raw' => $data,
        );
        
        if (isset($data['pay'])) {
            $result['cost'] = floatval($data['pay']) / 100;
        }
        
        if (isset($data['paynds'])) {
            $result['cost_nds'] = floatval($data['paynds']) / 100;
        }
        
        if (isset($data['ndsrate'])) {
            $result['nds_rate'] = intval($data['ndsrate']);
        }
        
        if (isset($data['delivery']['min'])) {
            $result['delivery_min'] = intval($data['delivery']['min']);
        }
        
        if (isset($data['delivery']['max'])) {
            $result['delivery_max'] = intval($data['delivery']['max']);
        }
        
        if (isset($data['transname'])) {
            $result['service_name'] = $data['transname'];
        }
        
        if (isset($data['ground']['val'])) {
            $result['ground_cost'] = floatval($data['ground']['val']) / 100;
        }
        
        if (isset($data['items']) && is_array($data['items'])) {
            $result['cost_breakdown'] = array();
            foreach ($data['items'] as $item) {
                if (isset($item['name']) && isset($item['val'])) {
                    $result['cost_breakdown'][] = array(
                        'name' => $item['name'],
                        'cost' => floatval($item['val']) / 100,
                    );
                }
            }
        }
        
        return $result;
    }
    
    public static function calculate_multiple_types($from, $to, $weight, $declared_value = 0) {
        $types_to_check = array('27030', '47030', '23030', '16010');
        $results = array();
        
        foreach ($types_to_check as $type) {
            $result = self::calculate(array(
                'from' => $from,
                'to' => $to,
                'weight' => $weight,
                'object' => $type,
                'sumoc' => $declared_value,
            ));
            
            if (!is_wp_error($result)) {
                $results[$type] = array(
                    'name' => self::OBJECT_TYPES[$type],
                    'cost' => $result['cost_nds'],
                    'delivery_min' => $result['delivery_min'],
                    'delivery_max' => $result['delivery_max'],
                );
            }
        }
        
        return $results;
    }
    
    public static function get_delivery_time_text($min, $max) {
        if ($min === $max) {
            return sprintf(_n('%d день', '%d дней', $min, 'wc-russian-post'), $min);
        }
        
        return sprintf(__('%d-%d дней', 'wc-russian-post'), $min, $max);
    }
    
    public static function get_object_types() {
        return self::OBJECT_TYPES;
    }
    
    public static function get_package_types() {
        return self::PACKAGE_TYPES;
    }
}
