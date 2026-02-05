<?php
defined('ABSPATH') || exit;

class WCRP_Order_Sync {
    
    public static function on_order_status_changed($order_id, $old_status, $new_status, $order) {
        $auto_create = get_option('wcrp_auto_create_orders', 'no');
        
        if ($auto_create !== 'yes') {
            return;
        }
        
        $create_on_status = get_option('wcrp_create_on_status', 'processing');
        
        if ($new_status !== $create_on_status) {
            return;
        }
        
        $shipping_methods = $order->get_shipping_methods();
        $is_russian_post = false;
        
        foreach ($shipping_methods as $method) {
            if (strpos($method->get_method_id(), 'russian_post') !== false) {
                $is_russian_post = true;
                break;
            }
        }
        
        if (!$is_russian_post) {
            return;
        }
        
        self::create_shipment($order_id);
    }
    
    public static function create_shipment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', __('Заказ не найден', 'wc-russian-post'));
        }
        
        $existing_shipment = get_post_meta($order_id, '_wcrp_shipment_id', true);
        if ($existing_shipment) {
            return new WP_Error('already_created', __('Отправление уже создано', 'wc-russian-post'));
        }
        
        $api = new WCRP_Otpravka_API();
        
        if (!$api->is_configured()) {
            return new WP_Error('not_configured', __('API Отправки не настроен', 'wc-russian-post'));
        }
        
        $order_data = self::prepare_order_data($order);
        
        $result = $api->create_order($order_data);
        
        if (is_wp_error($result)) {
            WCRP_Logger::error("Failed to create shipment for order {$order_id}: " . $result->get_error_message());
            $order->add_order_note(
                sprintf(__('Ошибка создания отправления в Почте России: %s', 'wc-russian-post'), $result->get_error_message())
            );
            return $result;
        }
        
        $shipment_id = $result['shipment_id'];
        
        update_post_meta($order_id, '_wcrp_shipment_id', $shipment_id);
        update_post_meta($order_id, '_wcrp_shipment_created', current_time('mysql'));
        
        $order->add_order_note(
            sprintf(__('Отправление создано в Почте России. ID: %s', 'wc-russian-post'), $shipment_id)
        );
        
        WCRP_Logger::info("Shipment created for order {$order_id}: {$shipment_id}");
        
        return array(
            'success' => true,
            'shipment_id' => $shipment_id,
        );
    }
    
    private static function prepare_order_data($order) {
        $items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $item->get_total() / $item->get_quantity(),
            );
        }
        
        $total_weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $weight = $product->get_weight();
                if ($weight) {
                    $total_weight += wc_get_weight($weight, 'g') * $item->get_quantity();
                } else {
                    $total_weight += 100 * $item->get_quantity();
                }
            }
        }
        
        $shipping_type = 'POSTAL_PARCEL';
        $mail_category = 'ORDINARY';
        
        foreach ($order->get_shipping_methods() as $method) {
            $type_code = $method->get_meta('_wcrp_shipping_type');
            if ($type_code) {
                $shipping_type = self::get_mail_type($type_code);
                break;
            }
        }
        
        $cod_amount = 0;
        if ($order->get_payment_method() === 'cod') {
            $cod_amount = $order->get_total();
            $mail_category = 'WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY';
        }
        
        $data = array(
            'order_num' => $order->get_order_number(),
            'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'recipient_phone' => $order->get_billing_phone(),
            'recipient_index' => $order->get_shipping_postcode(),
            'recipient_region' => $order->get_shipping_state(),
            'recipient_city' => $order->get_shipping_city(),
            'recipient_street' => $order->get_shipping_address_1(),
            'recipient_house' => '',
            'recipient_flat' => $order->get_shipping_address_2(),
            'weight' => max($total_weight, 100),
            'declared_value' => $order->get_total(),
            'cod_amount' => $cod_amount,
            'mail_type' => $shipping_type,
            'mail_category' => $mail_category,
            'items' => $items,
            'comment' => $order->get_customer_note(),
        );
        
        return $data;
    }
    
    private static function get_mail_type($code) {
        $types = array(
            '27030' => 'POSTAL_PARCEL',
            '47030' => 'POSTAL_PARCEL',
            '23030' => 'BANDEROL',
            '16010' => 'EMS',
            '30030' => 'ONLINE_COURIER',
        );
        
        return isset($types[$code]) ? $types[$code] : 'POSTAL_PARCEL';
    }
    
    public static function set_track_number($order_id, $track_number) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', __('Заказ не найден', 'wc-russian-post'));
        }
        
        update_post_meta($order_id, '_wcrp_track_number', sanitize_text_field($track_number));
        
        $tracking_api = new WCRP_Tracking_API();
        $tracking_api->save_order_tracking($order_id, $track_number);
        
        $order->add_order_note(
            sprintf(__('Трек-номер Почты России: %s', 'wc-russian-post'), $track_number)
        );
        
        if (get_option('wcrp_email_tracking', 'yes') === 'yes') {
            self::send_tracking_email($order, $track_number);
        }
        
        WCRP_Logger::info("Track number set for order {$order_id}: {$track_number}");
        
        return true;
    }
    
    private static function send_tracking_email($order, $track_number) {
        $to = $order->get_billing_email();
        $subject = sprintf(
            __('[%s] Ваш заказ #%s отправлен', 'wc-russian-post'),
            get_bloginfo('name'),
            $order->get_order_number()
        );
        
        $tracking_url = WCRP_Tracking_API::get_tracking_url($track_number);
        
        $message = sprintf(
            __("Здравствуйте, %s!\n\nВаш заказ #%s был отправлен Почтой России.\n\nТрек-номер: %s\n\nОтследить посылку: %s\n\nС уважением,\n%s", 'wc-russian-post'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $track_number,
            $tracking_url,
            get_bloginfo('name')
        );
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        wp_mail($to, $subject, $message, $headers);
    }
    
    public static function get_order_shipment_info($order_id) {
        return array(
            'shipment_id' => get_post_meta($order_id, '_wcrp_shipment_id', true),
            'track_number' => get_post_meta($order_id, '_wcrp_track_number', true),
            'created_at' => get_post_meta($order_id, '_wcrp_shipment_created', true),
        );
    }
    
    public static function delete_shipment($order_id) {
        $shipment_id = get_post_meta($order_id, '_wcrp_shipment_id', true);
        
        if (!$shipment_id) {
            return new WP_Error('no_shipment', __('Отправление не найдено', 'wc-russian-post'));
        }
        
        $api = new WCRP_Otpravka_API();
        $result = $api->delete_order($shipment_id);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        delete_post_meta($order_id, '_wcrp_shipment_id');
        delete_post_meta($order_id, '_wcrp_shipment_created');
        
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(__('Отправление удалено из Почты России', 'wc-russian-post'));
        }
        
        return true;
    }
}
