<?php
defined('ABSPATH') || exit;

class WCRP_Tracking_Widget {
    
    public function __construct() {
        add_action('init', array($this, 'register_shortcode'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_tracking_on_order'));
        add_action('wp_ajax_wcrp_track_shipment', array($this, 'ajax_track_shipment'));
        add_action('wp_ajax_nopriv_wcrp_track_shipment', array($this, 'ajax_track_shipment'));
    }
    
    public function register_shortcode() {
        add_shortcode('wcrp_tracking', array($this, 'render_tracking_form'));
    }
    
    public function render_tracking_form($atts) {
        $atts = shortcode_atts(array(
            'title' => __('Отследить посылку', 'wc-russian-post'),
            'button_text' => __('Отследить', 'wc-russian-post'),
            'placeholder' => __('Введите трек-номер', 'wc-russian-post'),
        ), $atts);
        
        ob_start();
        ?>
        <div class="wcrp-tracking-widget">
            <?php if (!empty($atts['title'])) : ?>
                <h3 class="wcrp-tracking-title"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>
            
            <form class="wcrp-tracking-form" onsubmit="return wcrpTrackShipment(event);">
                <div class="wcrp-tracking-input-wrap">
                    <input type="text" 
                           class="wcrp-tracking-input" 
                           name="track_number" 
                           placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                           required>
                    <button type="submit" class="wcrp-tracking-button">
                        <?php echo esc_html($atts['button_text']); ?>
                    </button>
                </div>
            </form>
            
            <div class="wcrp-tracking-result" style="display: none;"></div>
        </div>
        
        <script>
        function wcrpTrackShipment(e) {
            e.preventDefault();
            var form = e.target;
            var trackNumber = form.querySelector('.wcrp-tracking-input').value;
            var resultDiv = form.parentNode.querySelector('.wcrp-tracking-result');
            var button = form.querySelector('.wcrp-tracking-button');
            
            button.disabled = true;
            button.textContent = '<?php echo esc_js(__('Загрузка...', 'wc-russian-post')); ?>';
            resultDiv.style.display = 'none';
            
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'wcrp_track_shipment',
                    track_number: trackNumber,
                    nonce: '<?php echo wp_create_nonce('wcrp_tracking_nonce'); ?>'
                },
                success: function(response) {
                    resultDiv.innerHTML = response.data.html;
                    resultDiv.style.display = 'block';
                },
                error: function() {
                    resultDiv.innerHTML = '<div class="wcrp-error"><?php echo esc_js(__('Ошибка загрузки данных', 'wc-russian-post')); ?></div>';
                    resultDiv.style.display = 'block';
                },
                complete: function() {
                    button.disabled = false;
                    button.textContent = '<?php echo esc_js($atts['button_text']); ?>';
                }
            });
            
            return false;
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    public function display_tracking_on_order($order) {
        $order_id = $order->get_id();
        $track_number = get_post_meta($order_id, '_wcrp_track_number', true);
        
        if (empty($track_number)) {
            return;
        }
        
        $tracking_url = WCRP_Tracking_API::get_tracking_url($track_number);
        ?>
        <div class="wcrp-order-tracking">
            <h2><?php esc_html_e('Отслеживание посылки', 'wc-russian-post'); ?></h2>
            
            <div class="wcrp-tracking-info">
                <p>
                    <strong><?php esc_html_e('Трек-номер:', 'wc-russian-post'); ?></strong>
                    <span class="wcrp-track-number"><?php echo esc_html($track_number); ?></span>
                    <a href="<?php echo esc_url($tracking_url); ?>" target="_blank" class="wcrp-track-link">
                        <?php esc_html_e('Отследить на сайте Почты России', 'wc-russian-post'); ?>
                    </a>
                </p>
            </div>
            
            <div class="wcrp-tracking-details" id="wcrp-tracking-<?php echo esc_attr($order_id); ?>">
                <button type="button" class="button wcrp-load-tracking" data-track="<?php echo esc_attr($track_number); ?>">
                    <?php esc_html_e('Загрузить статус отправления', 'wc-russian-post'); ?>
                </button>
                <div class="wcrp-tracking-content"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.wcrp-load-tracking').on('click', function() {
                var btn = $(this);
                var trackNumber = btn.data('track');
                var container = btn.siblings('.wcrp-tracking-content');
                
                btn.prop('disabled', true).text('<?php echo esc_js(__('Загрузка...', 'wc-russian-post')); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'wcrp_track_shipment',
                        track_number: trackNumber,
                        nonce: '<?php echo wp_create_nonce('wcrp_tracking_nonce'); ?>'
                    },
                    success: function(response) {
                        container.html(response.data.html);
                        btn.hide();
                    },
                    error: function() {
                        container.html('<div class="wcrp-error"><?php echo esc_js(__('Ошибка загрузки данных', 'wc-russian-post')); ?></div>');
                        btn.prop('disabled', false).text('<?php echo esc_js(__('Повторить', 'wc-russian-post')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function ajax_track_shipment() {
        check_ajax_referer('wcrp_tracking_nonce', 'nonce');
        
        $track_number = isset($_POST['track_number']) ? sanitize_text_field($_POST['track_number']) : '';
        
        if (empty($track_number)) {
            wp_send_json_error(array(
                'html' => '<div class="wcrp-error">' . __('Укажите трек-номер', 'wc-russian-post') . '</div>'
            ));
        }
        
        $tracking_api = new WCRP_Tracking_API();
        $result = $tracking_api->track($track_number);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'html' => '<div class="wcrp-error">' . esc_html($result->get_error_message()) . '</div>'
            ));
        }
        
        $html = $this->render_tracking_result($result);
        
        wp_send_json_success(array('html' => $html));
    }
    
    private function render_tracking_result($data) {
        ob_start();
        ?>
        <div class="wcrp-tracking-result-content">
            <div class="wcrp-tracking-header">
                <div class="wcrp-tracking-number">
                    <strong><?php esc_html_e('Трек-номер:', 'wc-russian-post'); ?></strong>
                    <?php echo esc_html($data['track_number']); ?>
                </div>
                
                <?php if (!empty($data['status_description'])) : ?>
                    <div class="wcrp-tracking-status <?php echo esc_attr(WCRP_Tracking_API::get_status_badge_class($data['status_description'])); ?>">
                        <?php echo esc_html($data['status_description']); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($data['checkpoints'])) : ?>
                <div class="wcrp-tracking-timeline">
                    <?php foreach ($data['checkpoints'] as $index => $checkpoint) : ?>
                        <div class="wcrp-checkpoint <?php echo $index === 0 ? 'wcrp-checkpoint-latest' : ''; ?>">
                            <div class="wcrp-checkpoint-marker"></div>
                            <div class="wcrp-checkpoint-content">
                                <div class="wcrp-checkpoint-date">
                                    <?php echo esc_html($checkpoint['date']); ?>
                                </div>
                                <div class="wcrp-checkpoint-status">
                                    <?php echo esc_html($checkpoint['status']); ?>
                                </div>
                                <?php if (!empty($checkpoint['location'])) : ?>
                                    <div class="wcrp-checkpoint-location">
                                        <?php echo esc_html($checkpoint['location']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="wcrp-no-data"><?php esc_html_e('Информация о перемещении пока недоступна', 'wc-russian-post'); ?></p>
            <?php endif; ?>
            
            <div class="wcrp-tracking-footer">
                <a href="<?php echo esc_url(WCRP_Tracking_API::get_tracking_url($data['track_number'])); ?>" 
                   target="_blank" class="wcrp-external-link">
                    <?php esc_html_e('Подробнее на сайте Почты России', 'wc-russian-post'); ?>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

new WCRP_Tracking_Widget();
