<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WSSW_Rest_API {
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'wssw/v1', '/categories', array( 
            'methods' => 'GET', 
            'callback' => array( $this, 'get_categories' ), 
            'permission_callback' => '__return_true' 
        ) );
        register_rest_route( 'wssw/v1', '/attributes', array( 
            'methods' => 'GET', 
            'callback' => array( $this, 'get_attributes' ), 
            'permission_callback' => '__return_true' 
        ) );
        register_rest_route( 'wssw/v1', '/price-range', array( 
            'methods' => 'GET', 
            'callback' => array( $this, 'get_price_range' ), 
            'permission_callback' => '__return_true' 
        ) );
    }

    public function get_categories( $request ) {
        $p = isset( $request['parent'] ) ? intval( $request['parent'] ) : 0;
        $cats = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $p, 'hide_empty' => false ) );
        
        if ( is_wp_error( $cats ) ) return new WP_Error( 'err', 'Error recuperando categorías', array( 'status' => 500 ) );
        
        $data = array();
        foreach ( $cats as $c ) {
            $data[] = array( 
                'id' => $c->term_id, 
                'name' => $c->name, 
                'link' => get_term_link( $c ), 
                'has_children' => ! empty( get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $c->term_id, 'hide_empty' => false ) ) ) 
            );
        }
        return rest_ensure_response( $data );
    }

    public function get_attributes( $request ) {
        $taxes = wc_get_attribute_taxonomies();
        $data = array();
        foreach ( $taxes as $t ) {
            $name = wc_attribute_taxonomy_name( $t->attribute_name );
            $terms = get_terms( array( 'taxonomy' => $name, 'hide_empty' => false ) );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                $tl = array();
                foreach ( $terms as $tm ) { 
                    $tl[] = array( 'name' => $tm->name, 'link' => add_query_arg( $name, $tm->slug, home_url( '/shop/' ) ) ); 
                }
                $data[] = array( 'label' => $t->attribute_label, 'terms' => $tl );
            }
        }
        return rest_ensure_response( $data );
    }

    public function get_price_range( $request ) {
        $max = 1000000;
        $pids = wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) );
        if ( ! empty( $pids ) ) {
            $prices = array();
            foreach ( $pids as $id ) {
                $prod = wc_get_product( $id );
                if ( $prod ) $prices[] = (float) $prod->get_price();
            }
            if ( ! empty( $prices ) ) $max = max( $prices );
        }
        return rest_ensure_response( array( 'max_price' => $max ) );
    }
}
