<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller Widget - Sidebar widget for personal booking info
 */
class VatCar_Controller_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'vatcar_controller_widget',
            'My ATC Bookings',
            ['description' => 'Displays personal ATC booking information for logged-in controllers']
        );
    }

    /**
     * Front-end display of widget
     */
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        echo VatCar_Controller_Dashboard::render_widget();

        echo $args['after_widget'];
    }

    /**
     * Back-end widget form
     */
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title (optional):', 'vatcar-atc'); ?>
            </label>
            <input 
                class="widefat" 
                id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                type="text" 
                value="<?php echo esc_attr($title); ?>">
        </p>
        <p class="description">
            This widget displays personal booking information for logged-in controllers. 
            Non-controllers will not see any content.
        </p>
        <?php
    }

    /**
     * Sanitize widget form values as they are saved
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        return $instance;
    }
}

/**
 * Register the widget
 */
function vatcar_register_controller_widget() {
    register_widget('VatCar_Controller_Widget');
}
add_action('widgets_init', 'vatcar_register_controller_widget');
