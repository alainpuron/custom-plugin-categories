<?php
/**
 * Plugin Name: Extension Organizer
 * Description: Organize your extensions and add-ons into custom categories directly on the admin page.
 * Version: 3.0
 * Author: Alain Puron
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_Plugin_Organizer {

    private $categories_option = 'spo_categories';
    private $plugin_cats_option = 'spo_plugin_cats';

    public function __construct() {
        add_filter( 'manage_plugins_columns', array( $this, 'add_category_column' ) );
        add_action( 'manage_plugins_custom_column', array( $this, 'render_category_column' ), 10, 3 );
        add_filter( 'views_plugins', array( $this, 'add_category_views' ) );
        add_filter( 'all_plugins', array( $this, 'filter_plugins_by_category' ) );
        add_filter( 'bulk_actions-plugins', array( $this, 'register_bulk_actions' ) );
        add_action( 'load-plugins.php', array( $this, 'handle_bulk_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_plugin_category_script' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_spo_add_category', array( $this, 'ajax_add_category' ) );
        add_action( 'wp_ajax_spo_edit_category', array( $this, 'ajax_edit_category' ) );
        add_action( 'wp_ajax_spo_delete_category', array( $this, 'ajax_delete_category' ) );
        add_action( 'wp_ajax_spo_assign_plugin', array( $this, 'ajax_assign_plugin' ) );
        add_action( 'wp_ajax_spo_reorder_categories', array( $this, 'ajax_reorder_categories' ) ); // New Sort Endpoint
    }

    public function add_category_column( $columns ) {
        $columns['plugin_category'] = 'Category';
        return $columns;
    }

    public function render_category_column( $column_name, $plugin_file, $plugin_data ) {
        if ( 'plugin_category' === $column_name ) {
            $plugin_cats = get_option( $this->plugin_cats_option, array() );
            $categories  = get_option( $this->categories_option, array() );
            
            $current_cat_id = isset( $plugin_cats[ $plugin_file ] ) ? $plugin_cats[ $plugin_file ] : '';
            $cat_name = ( $current_cat_id && isset( $categories[$current_cat_id] ) ) ? $categories[$current_cat_id] : 'Uncategorized';

            echo '<select class="spo-inline-assign" data-plugin="' . esc_attr($plugin_file) . '">';
            echo '<option value="">Uncategorized</option>';
            foreach ( $categories as $id => $name ) {
                $selected = selected( $current_cat_id, $id, false );
                echo '<option value="' . esc_attr($id) . '" ' . $selected . '>' . esc_html($name) . '</option>';
            }
            echo '</select>';
            echo '<span class="plugin-cat-label" style="display:none;" data-category="' . esc_attr($cat_name) . '" data-cat-id="' . esc_attr($current_cat_id) . '"></span>';
        }
    }

    public function enqueue_plugin_category_script( $hook ) {
        if ( 'plugins.php' !== $hook ) return;
        
        // Added jquery-ui-sortable dependency
        wp_enqueue_script( 'plugin-cat-js', plugin_dir_url( __FILE__ ) . 'assets/js/plugin-categories.js', array('jquery', 'jquery-ui-sortable'), '3.0', true );
        
        wp_localize_script( 'plugin-cat-js', 'pluginCatApp', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'spo_ajax_nonce' ),
            // Pass the ordered categories to JS so we render them in the exact saved order
            'ordered_cats' => get_option( $this->categories_option, array() )
        ));
    }

    public function add_category_views( $views ) {
        $categories  = get_option( $this->categories_option, array() );
        $plugin_cats = get_option( $this->plugin_cats_option, array() );
        $current = isset( $_GET['plugin_status'] ) ? sanitize_text_field( $_GET['plugin_status'] ) : 'all';

        foreach ( $categories as $id => $name ) {
            $count = count( array_filter( $plugin_cats, function( $cat_id ) use ( $id ) { return $cat_id === $id; }) );
            if ( $count > 0 ) {
                $class = ( $current === 'spo_cat_' . $id ) ? ' class="current"' : '';
                $url   = admin_url( 'plugins.php?plugin_status=spo_cat_' . $id );
                $views[ 'spo_cat_' . $id ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $name ), $count );
            }
        }
        return $views;
    }

    public function filter_plugins_by_category( $all_plugins ) {
        if ( ! isset( $_GET['plugin_status'] ) ) return $all_plugins;
        $status = sanitize_text_field( $_GET['plugin_status'] );
        if ( strpos( $status, 'spo_cat_' ) === 0 ) {
            $cat_id      = str_replace( 'spo_cat_', '', $status );
            $plugin_cats = get_option( $this->plugin_cats_option, array() );
            foreach ( $all_plugins as $plugin_file => $plugin_data ) {
                if ( ! isset( $plugin_cats[ $plugin_file ] ) || $plugin_cats[ $plugin_file ] !== $cat_id ) {
                    unset( $all_plugins[ $plugin_file ] );
                }
            }
        }
        return $all_plugins;
    }

    public function register_bulk_actions( $bulk_actions ) {
        $categories = get_option( $this->categories_option, array() );
        foreach ( $categories as $id => $name ) {
            $bulk_actions[ 'spo_add_to_' . $id ] = 'Add to: ' . $name;
        }
        if ( ! empty( $categories ) ) {
            $bulk_actions['spo_remove_cat'] = 'Remove from Category';
        }
        return $bulk_actions;
    }

    public function handle_bulk_actions() {
        if ( ! isset( $_REQUEST['checked'] ) || ! is_array( $_REQUEST['checked'] ) ) return;
        $action = isset( $_REQUEST['action'] ) && $_REQUEST['action'] !== '-1' ? sanitize_text_field( $_REQUEST['action'] ) : false;
        if ( ! $action && isset( $_REQUEST['action2'] ) && $_REQUEST['action2'] !== '-1' ) {
            $action = sanitize_text_field( $_REQUEST['action2'] );
        }
        if ( ! $action ) return;

        $plugin_cats = get_option( $this->plugin_cats_option, array() );
        $changed     = false;

        if ( strpos( $action, 'spo_add_to_' ) === 0 ) {
            $cat_id = str_replace( 'spo_add_to_', '', $action );
            foreach ( $_REQUEST['checked'] as $plugin_file ) $plugin_cats[ $plugin_file ] = $cat_id;
            $changed = true;
        } elseif ( $action === 'spo_remove_cat' ) {
            foreach ( $_REQUEST['checked'] as $plugin_file ) {
                if ( isset( $plugin_cats[ $plugin_file ] ) ) unset( $plugin_cats[ $plugin_file ] );
            }
            $changed = true;
        }

        if ( $changed ) {
            update_option( $this->plugin_cats_option, $plugin_cats );
            wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
            exit;
        }
    }

    // --- AJAX HANDLERS ---

    public function ajax_add_category() {
        check_ajax_referer( 'spo_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['cat_name'] ) ) wp_send_json_error();

        $categories = get_option( $this->categories_option, array() );
        $new_cat_name = sanitize_text_field( $_POST['cat_name'] );
        $id = uniqid();
        
        $categories[$id] = $new_cat_name;
        update_option( $this->categories_option, $categories );
        wp_send_json_success();
    }

    public function ajax_edit_category() {
        check_ajax_referer( 'spo_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['cat_id'] ) || empty( $_POST['cat_name'] ) ) wp_send_json_error();

        $categories = get_option( $this->categories_option, array() );
        $id = sanitize_text_field( $_POST['cat_id'] );
        $new_name = sanitize_text_field( $_POST['cat_name'] );

        if ( isset( $categories[$id] ) ) {
            $categories[$id] = $new_name;
            update_option( $this->categories_option, $categories );
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    public function ajax_delete_category() {
        check_ajax_referer( 'spo_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['cat_id'] ) ) wp_send_json_error();

        $categories = get_option( $this->categories_option, array() );
        $id = sanitize_text_field( $_POST['cat_id'] );

        if ( isset( $categories[$id] ) ) {
            unset( $categories[$id] );
            update_option( $this->categories_option, $categories );
            $plugin_cats = get_option( $this->plugin_cats_option, array() );
            foreach ( $plugin_cats as $plugin_file => $cat_id ) {
                if ( $cat_id === $id ) unset( $plugin_cats[ $plugin_file ] );
            }
            update_option( $this->plugin_cats_option, $plugin_cats );
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    public function ajax_assign_plugin() {
        check_ajax_referer( 'spo_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['plugin_file'] ) ) wp_send_json_error();

        $plugin_cats = get_option( $this->plugin_cats_option, array() );
        $plugin_file = sanitize_text_field( $_POST['plugin_file'] );
        $cat_id = sanitize_text_field( $_POST['cat_id'] );

        if ( empty( $cat_id ) ) {
            unset( $plugin_cats[$plugin_file] );
        } else {
            $plugin_cats[$plugin_file] = $cat_id;
        }

        update_option( $this->plugin_cats_option, $plugin_cats );
        wp_send_json_success();
    }

    // New Drag and Drop Sorting Method
    public function ajax_reorder_categories() {
        check_ajax_referer( 'spo_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['order'] ) ) wp_send_json_error();

        $order = array_map('sanitize_text_field', $_POST['order']);
        $categories = get_option( $this->categories_option, array() );
        $new_categories = array();

        // Rebuild the array in the new order
        foreach ($order as $id) {
            if (isset($categories[$id])) {
                $new_categories[$id] = $categories[$id];
            }
        }
        // Append any categories that might have been missed
        foreach ($categories as $id => $name) {
            if (!isset($new_categories[$id])) {
                $new_categories[$id] = $name;
            }
        }

        update_option( $this->categories_option, $new_categories );
        wp_send_json_success();
    }
}

new Simple_Plugin_Organizer();