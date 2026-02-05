<?php
defined('ABSPATH') || exit;

class WCRP_Otpravka_API {
    
    const API_URL = 'https://otpravka.pochta.ru';
    
    private $token;
    private $user_key;
    
    public function __construct() {
        $this->token = get_option('wcrp_otpravka_token', '');
        $this->user_key = base64_encode($this->token . ':');
    }
    
    public function is_configured() {
        return !empty($this->token);
    }
    
    private function request($endpoint, $method = 'GET', $data = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('API Отправки не настроен', 'wc-russian-post'));
        }
        
        $url = self::API_URL . $endpoint;
        
        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'AccessToken ' . $this->token,
                'Content-Type' => 'application/json;charset=UTF-8',
                'Accept' => 'application/json;charset=UTF-8',
            ),
        );
        
        if ($data !== null && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = json_encode($data);
        }
        
        WCRP_Logger::debug("Otpravka API {$method} request to: {$endpoint}");
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            WCRP_Logger::error('Otpravka API error: ' . $response->get_error_message());
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        WCRP_Logger::debug("Otpravka API response ({$code}): " . substr($body, 0, 500));
        
        if ($code >= 400) {
            $error_data = json_decode($body, true);
            $error_msg = isset($error_data['message']) ? $error_data['message'] : __('Ошибка API', 'wc-russian-post');
            return new WP_Error('api_error', $error_msg, array('code' => $code));
        }
        
        return json_decode($body, true);
    }
    
    public function test_connection() {
        $result = $this->request('/1.0/settings');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return array(
            'success' => true,
            'data' => $result,
        );
    }
    
    public function normalize_address($address) {
        return $this->request('/1.0/clean/address', 'POST', array(
            array(
                'id' => 'addr_1',
                'original-address' => $address,
            )
        ));
    }
    
    public function normalize_fio($fio) {
        return $this->request('/1.0/clean/physical', 'POST', array(
            array(
                'id' => 'fio_1',
                'original-fio' => $fio,
            )
        ));
    }
    
    public function normalize_phone($phone) {
        return $this->request('/1.0/clean/phone', 'POST', array(
            array(
                'id' => 'phone_1',
                'original-phone' => $phone,
            )
        ));
    }
    
    public function create_order($order_data) {
        $shipment = $this->prepare_shipment_data($order_data);
        
        if (is_wp_error($shipment)) {
            return $shipment;
        }
        
        $result = $this->request('/1.0/user/backlog', 'PUT', array($shipment));
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (isset($result['result-ids']) && !empty($result['result-ids'])) {
            return array(
                'success' => true,
                'shipment_id' => $result['result-ids'][0],
            );
        }
        
        if (isset($result['errors']) && !empty($result['errors'])) {
            $error = $result['errors'][0];
            return new WP_Error(
                'creation_error',
                isset($error['error-codes']) ? implode(', ', $error['error-codes']) : __('Ошибка создания заказа', 'wc-russian-post')
            );
        }
        
        return new WP_Error('unknown_error', __('Неизвестная ошибка создания заказа', 'wc-russian-post'));
    }
    
    private function prepare_shipment_data($order_data) {
        $defaults = array(
            'order_num' => '',
            'recipient_name' => '',
            'recipient_phone' => '',
            'recipient_address' => '',
            'recipient_index' => '',
            'recipient_region' => '',
            'recipient_city' => '',
            'recipient_street' => '',
            'recipient_house' => '',
            'recipient_flat' => '',
            'weight' => 0,
            'declared_value' => 0,
            'cod_amount' => 0,
            'mail_type' => 'POSTAL_PARCEL',
            'mail_category' => 'ORDINARY',
            'dimensions' => array(),
            'items' => array(),
            'comment' => '',
        );
        
        $data = wp_parse_args($order_data, $defaults);
        
        $shipment = array(
            'address-type-to' => 'DEFAULT',
            'given-name' => $this->extract_first_name($data['recipient_name']),
            'surname' => $this->extract_surname($data['recipient_name']),
            'mail-category' => $data['mail_category'],
            'mail-type' => $data['mail_type'],
            'mass' => intval($data['weight']),
            'order-num' => $data['order_num'],
            'tel-address' => preg_replace('/[^0-9]/', '', $data['recipient_phone']),
        );
        
        if (!empty($data['recipient_index'])) {
            $shipment['index-to'] = $data['recipient_index'];
        }
        
        if (!empty($data['recipient_region'])) {
            $shipment['region-to'] = $data['recipient_region'];
        }
        
        if (!empty($data['recipient_city'])) {
            $shipment['place-to'] = $data['recipient_city'];
        }
        
        if (!empty($data['recipient_street'])) {
            $shipment['street-to'] = $data['recipient_street'];
        }
        
        if (!empty($data['recipient_house'])) {
            $shipment['house-to'] = $data['recipient_house'];
        }
        
        if (!empty($data['recipient_flat'])) {
            $shipment['room-to'] = $data['recipient_flat'];
        }
        
        if ($data['declared_value'] > 0) {
            $shipment['insr-value'] = intval($data['declared_value'] * 100);
        }
        
        if ($data['cod_amount'] > 0) {
            $shipment['payment'] = intval($data['cod_amount'] * 100);
            $shipment['mail-category'] = 'WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY';
        }
        
        if (!empty($data['dimensions'])) {
            if (isset($data['dimensions']['length'])) {
                $shipment['dimension']['length'] = intval($data['dimensions']['length']);
            }
            if (isset($data['dimensions']['width'])) {
                $shipment['dimension']['width'] = intval($data['dimensions']['width']);
            }
            if (isset($data['dimensions']['height'])) {
                $shipment['dimension']['height'] = intval($data['dimensions']['height']);
            }
        }
        
        if (!empty($data['items'])) {
            $shipment['goods'] = array(
                'items' => array(),
            );
            foreach ($data['items'] as $item) {
                $shipment['goods']['items'][] = array(
                    'description' => isset($item['name']) ? $item['name'] : '',
                    'quantity' => isset($item['quantity']) ? intval($item['quantity']) : 1,
                    'value' => isset($item['price']) ? intval($item['price'] * 100) : 0,
                );
            }
        }
        
        if (!empty($data['comment'])) {
            $shipment['comment'] = $data['comment'];
        }
        
        return $shipment;
    }
    
    private function extract_first_name($full_name) {
        $parts = explode(' ', trim($full_name));
        return isset($parts[1]) ? $parts[1] : (isset($parts[0]) ? $parts[0] : '');
    }
    
    private function extract_surname($full_name) {
        $parts = explode(' ', trim($full_name));
        return isset($parts[0]) ? $parts[0] : '';
    }
    
    public function get_order($shipment_id) {
        return $this->request('/1.0/backlog/' . $shipment_id);
    }
    
    public function delete_order($shipment_id) {
        return $this->request('/1.0/backlog/' . $shipment_id, 'DELETE');
    }
    
    public function get_orders_from_backlog($page = 0, $size = 100) {
        return $this->request("/1.0/backlog?page={$page}&size={$size}");
    }
    
    public function move_to_batch($shipment_ids) {
        return $this->request('/1.0/user/shipment', 'POST', $shipment_ids);
    }
    
    public function create_batch($shipment_ids, $send_date = null) {
        $data = array(
            'shipment-ids' => $shipment_ids,
        );
        
        if ($send_date) {
            $data['sending-date'] = $send_date;
        }
        
        return $this->request('/1.0/user/batch', 'POST', $data);
    }
    
    public function get_batches($page = 0, $size = 100) {
        return $this->request("/1.0/batch?page={$page}&size={$size}");
    }
    
    public function search_batch($batch_name) {
        return $this->request("/1.0/batch/{$batch_name}");
    }
    
    public function calculate_delivery($params) {
        $request_data = array(
            'index-from' => get_option('wcrp_sender_index', ''),
            'index-to' => isset($params['to']) ? $params['to'] : '',
            'mail-category' => isset($params['category']) ? $params['category'] : 'ORDINARY',
            'mail-type' => isset($params['type']) ? $params['type'] : 'POSTAL_PARCEL',
            'mass' => isset($params['weight']) ? intval($params['weight']) : 0,
        );
        
        if (isset($params['declared_value']) && $params['declared_value'] > 0) {
            $request_data['declared-value'] = intval($params['declared_value'] * 100);
        }
        
        if (isset($params['cod_amount']) && $params['cod_amount'] > 0) {
            $request_data['payment-method'] = 'CASHLESS';
            $request_data['with-order-of-notice'] = true;
        }
        
        return $this->request('/1.0/tariff', 'POST', $request_data);
    }
    
    public function get_delivery_estimate($from_index, $to_index) {
        return $this->request("/1.0/delivery?from={$from_index}&to={$to_index}");
    }
    
    public function get_post_offices($index = null, $top = null, $filter = null) {
        $params = array();
        
        if ($index) {
            $params['index'] = $index;
        }
        if ($top) {
            $params['top'] = $top;
        }
        if ($filter) {
            $params['filter'] = $filter;
        }
        
        $query = !empty($params) ? '?' . http_build_query($params) : '';
        
        return $this->request('/postoffice/1.0' . $query);
    }
    
    public function get_document_f7p($shipment_ids) {
        return $this->request('/1.0/forms/backlog/f7p', 'POST', $shipment_ids);
    }
    
    public function get_document_f103($batch_name) {
        return $this->request("/1.0/forms/{$batch_name}/f103pdf");
    }
    
    public function get_document_f112($batch_name) {
        return $this->request("/1.0/forms/{$batch_name}/f112pdf");
    }
    
    public function get_stickers($shipment_ids, $format = 'A4') {
        $endpoint = '/1.0/forms/backlog/' . $shipment_ids[0] . '/forms';
        
        if ($format === 'THERMO') {
            $endpoint .= '?print-type=THERMO';
        }
        
        return $this->request($endpoint);
    }
}
