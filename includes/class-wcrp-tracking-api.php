<?php
defined('ABSPATH') || exit;

class WCRP_Tracking_API {
    
    const GDEPOSYLKA_URL = 'https://gdeposylka.ru/api/v4/tracker';
    const POCHTA_TRACKING_URL = 'https://tracking.russianpost.ru/rtm34';
    
    private $api_key;
    
    public function __construct() {
        $this->api_key = get_option('wcrp_tracking_api_key', '');
    }
    
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    public function track($track_number) {
        $track_number = preg_replace('/\s+/', '', strtoupper($track_number));
        
        if (strlen($track_number) < 10 || strlen($track_number) > 20) {
            return new WP_Error('invalid_track', __('Некорректный трек-номер', 'wc-russian-post'));
        }
        
        $cached = $this->get_cached_tracking($track_number);
        if ($cached !== false) {
            return $cached;
        }
        
        if ($this->is_configured()) {
            $result = $this->track_via_gdeposylka($track_number);
        } else {
            $result = $this->track_via_pochta($track_number);
        }
        
        if (!is_wp_error($result)) {
            $this->cache_tracking($track_number, $result);
        }
        
        return $result;
    }
    
    private function track_via_gdeposylka($track_number) {
        $url = self::GDEPOSYLKA_URL . '/russian-post/' . $track_number;
        
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'X-Authorization-Token' => $this->api_key,
                'Accept' => 'application/json',
            ),
        ));
        
        if (is_wp_error($response)) {
            WCRP_Logger::error('GdePosilka tracking error: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['result']) || $data['result'] !== 'success') {
            $error_msg = isset($data['message']) ? $data['message'] : __('Ошибка отслеживания', 'wc-russian-post');
            return new WP_Error('tracking_error', $error_msg);
        }
        
        return $this->parse_gdeposylka_response($data);
    }
    
    private function parse_gdeposylka_response($data) {
        $result = array(
            'track_number' => isset($data['data']['tracking_number']) ? $data['data']['tracking_number'] : '',
            'status' => '',
            'status_description' => '',
            'is_delivered' => false,
            'checkpoints' => array(),
            'origin' => array(),
            'destination' => array(),
            'weight' => null,
            'last_update' => null,
        );
        
        if (isset($data['data']['status'])) {
            $result['status'] = $data['data']['status'];
        }
        
        if (isset($data['data']['status_name'])) {
            $result['status_description'] = $data['data']['status_name'];
        }
        
        if (isset($data['data']['is_delivered'])) {
            $result['is_delivered'] = (bool) $data['data']['is_delivered'];
        }
        
        if (isset($data['data']['origin'])) {
            $result['origin'] = $data['data']['origin'];
        }
        
        if (isset($data['data']['destination'])) {
            $result['destination'] = $data['data']['destination'];
        }
        
        if (isset($data['data']['weight'])) {
            $result['weight'] = $data['data']['weight'];
        }
        
        if (isset($data['data']['checkpoints']) && is_array($data['data']['checkpoints'])) {
            foreach ($data['data']['checkpoints'] as $checkpoint) {
                $result['checkpoints'][] = array(
                    'date' => isset($checkpoint['time']) ? $checkpoint['time'] : '',
                    'location' => isset($checkpoint['location']) ? $checkpoint['location'] : '',
                    'status' => isset($checkpoint['status_name']) ? $checkpoint['status_name'] : '',
                    'status_code' => isset($checkpoint['status']) ? $checkpoint['status'] : '',
                    'description' => isset($checkpoint['description']) ? $checkpoint['description'] : '',
                );
            }
            
            if (!empty($result['checkpoints'])) {
                $result['last_update'] = $result['checkpoints'][0]['date'];
            }
        }
        
        return $result;
    }
    
    private function track_via_pochta($track_number) {
        return new WP_Error(
            'api_not_configured',
            __('Для отслеживания настройте API ключ в настройках плагина', 'wc-russian-post')
        );
    }
    
    private function get_cached_tracking($track_number) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrp_tracking';
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE track_number = %s AND last_update > DATE_SUB(NOW(), INTERVAL 2 HOUR)",
            $track_number
        ));
        
        if ($row && !empty($row->tracking_data)) {
            return json_decode($row->tracking_data, true);
        }
        
        return false;
    }
    
    private function cache_tracking($track_number, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrp_tracking';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE track_number = %s",
            $track_number
        ));
        
        $status = isset($data['status_description']) ? $data['status_description'] : '';
        
        if ($existing) {
            $wpdb->update(
                $table,
                array(
                    'status' => $status,
                    'last_update' => current_time('mysql'),
                    'tracking_data' => json_encode($data),
                ),
                array('id' => $existing),
                array('%s', '%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'track_number' => $track_number,
                    'order_id' => 0,
                    'status' => $status,
                    'last_update' => current_time('mysql'),
                    'tracking_data' => json_encode($data),
                ),
                array('%s', '%d', '%s', '%s', '%s')
            );
        }
    }
    
    public function save_order_tracking($order_id, $track_number) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrp_tracking';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d",
            $order_id
        ));
        
        if ($existing) {
            $wpdb->update(
                $table,
                array('track_number' => $track_number),
                array('id' => $existing),
                array('%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'order_id' => $order_id,
                    'track_number' => $track_number,
                    'status' => '',
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s')
            );
        }
        
        update_post_meta($order_id, '_wcrp_track_number', $track_number);
    }
    
    public function get_order_tracking($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrp_tracking';
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d",
            $order_id
        ));
        
        if (!$row) {
            $track_number = get_post_meta($order_id, '_wcrp_track_number', true);
            if ($track_number) {
                return array(
                    'track_number' => $track_number,
                    'status' => '',
                    'tracking_data' => null,
                );
            }
            return null;
        }
        
        return array(
            'track_number' => $row->track_number,
            'status' => $row->status,
            'last_update' => $row->last_update,
            'tracking_data' => $row->tracking_data ? json_decode($row->tracking_data, true) : null,
        );
    }
    
    public function update_all_active_trackings() {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrp_tracking';
        
        $active_trackings = $wpdb->get_results(
            "SELECT DISTINCT track_number FROM {$table} 
             WHERE track_number != '' 
             AND (status IS NULL OR status NOT LIKE '%доставлен%')
             AND (last_update IS NULL OR last_update < DATE_SUB(NOW(), INTERVAL 2 HOUR))
             LIMIT 50"
        );
        
        $updated = 0;
        
        foreach ($active_trackings as $tracking) {
            $result = $this->track($tracking->track_number);
            if (!is_wp_error($result)) {
                $updated++;
            }
            usleep(100000);
        }
        
        return $updated;
    }
    
    public static function get_tracking_url($track_number) {
        return 'https://www.pochta.ru/tracking#' . urlencode($track_number);
    }
    
    public static function get_status_badge_class($status) {
        $status_lower = mb_strtolower($status);
        
        if (strpos($status_lower, 'доставлен') !== false || strpos($status_lower, 'вручен') !== false) {
            return 'wcrp-status-delivered';
        }
        
        if (strpos($status_lower, 'прибыл') !== false || strpos($status_lower, 'поступил') !== false) {
            return 'wcrp-status-arrived';
        }
        
        if (strpos($status_lower, 'в пути') !== false || strpos($status_lower, 'отправлен') !== false) {
            return 'wcrp-status-transit';
        }
        
        if (strpos($status_lower, 'возврат') !== false || strpos($status_lower, 'неудач') !== false) {
            return 'wcrp-status-returned';
        }
        
        return 'wcrp-status-default';
    }
}
