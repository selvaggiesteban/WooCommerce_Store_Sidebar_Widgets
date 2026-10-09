<?php
/**
 * Plugin Name: WooCommerce Store Sidebar Widgets (API Version)
 * Description: Stable sidebar widgets using WP-REST API.
 * Version: 2.1.4
 * Author: Esteban Selvaggi
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSSW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSSW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WSSW_PLUGIN_DIR . 'includes/class-rest-api.php';

class WSSW_Plugin_V2 {
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        if ( class_exists( 'WSSW_Rest_API' ) ) {
            new WSSW_Rest_API();
        }
        add_shortcode( 'wssw_categories', array( $this, 'render_categories' ) );
        add_shortcode( 'wssw_attributes', array( $this, 'render_attributes' ) );
        add_shortcode( 'wssw_price', array( $this, 'render_price' ) );
    }

    public function render_categories() {
        return '<div id="wssw-categories-root">Cargando categorías...</div>';
    }

    public function render_attributes() {
        return '<div id="wssw-attributes-root">Cargando atributos...</div>';
    }

    public function render_price() {
        return '<div id="wssw-price-root">Cargando filtro de precio...</div>';
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'wssw-style', WSSW_PLUGIN_URL . 'assets/css/style.css', array(), '2.1.4' );
        wp_enqueue_script( 'wssw-script', WSSW_PLUGIN_URL . 'assets/js/scripts.js', array( 'jquery' ), '2.1.4', true );
        
        $current_cat_id = 0;
        if ( is_product_category() ) {
            $current_cat_id = get_queried_object_id();
        } 
        elseif ( is_singular() || is_page() ) {
            if ( isset( $_GET['product_cat'] ) ) {
                $term = get_term_by( 'slug', $_GET['product_cat'], 'product_cat' );
                if ( $term ) $current_cat_id = $term->term_id;
            }
        }

        wp_localize_script( 'wssw-script', 'wssw_api', array( 
            'root' => esc_url_raw( rest_url( 'wssw/v1/' ) ),
            'current_cat' => (int)$current_cat_id
        ) );
    }
}

new WSSW_Plugin_V2();
