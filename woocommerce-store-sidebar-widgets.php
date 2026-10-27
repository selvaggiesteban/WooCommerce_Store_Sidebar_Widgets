<?php
/**
 * Plugin Name: WooCommerce Store Sidebar Widgets (Responsive)
 * Description: Stable sidebar widgets using WP-REST API with Responsive Fullscreen Filter. Filters are wired to the product archive queries (attributes, price and category context).
 * Version: 2.3.2
 * Author: Esteban Selvaggi
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSSW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSSW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WSSW_PLUGIN_DIR . 'includes/class-query.php';
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
		add_shortcode( 'wssw_responsive_filters', array( $this, 'render_responsive_filters' ) );

		// Wire URL filters (pa_* / min_price / max_price) into the product grids:
		// - WC [products] shortcode (xstore archive/home templates)
		// - xstore/etheme product grid widget (via woocommerce_products_widget_query_args)
		add_filter( 'woocommerce_shortcode_products_query', array( $this, 'filter_shortcode_query' ), 20, 3 );
		add_filter( 'woocommerce_products_widget_query_args', array( $this, 'filter_widget_query' ), 20, 1 );
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

	public function render_responsive_filters() {
		return '
		<div class="wssw-responsive-filter-wrapper">
			<button id="wssw-filter-toggle" class="wssw-filter-mobile-btn" aria-controls="wssw-filter-overlay" aria-expanded="false">Filtrar</button>
			<div id="wssw-filter-overlay" class="wssw-filter-overlay">
				<div class="wssw-filter-overlay-header">
					<span class="wssw-overlay-title">Filtros</span>
					<button id="wssw-filter-close" class="wssw-filter-close-btn" aria-label="Cerrar filtros">&times;</button>
				</div>
				<div class="wssw-filter-overlay-content">
					<div class="wssw-active-filters" hidden></div>
					<div class="wssw-filter-section">
						<p class="wssw-section-header">Filtrar por Categorías</p>
						' . $this->render_categories() . '
					</div>
					<div class="wssw-filter-section">
						<p class="wssw-section-header">Filtrar por Atributos</p>
						' . $this->render_attributes() . '
					</div>
					<div class="wssw-filter-section">
						<p class="wssw-section-header">Filtrar por precios</p>
						' . $this->render_price() . '
					</div>
				</div>
			</div>
		</div>';
	}

	/**
	 * Inject URL filters (+ product_cat archive context) into [products] queries.
	 *
	 * @param array  $query_args Query args.
	 * @param array  $attributes Shortcode attributes.
	 * @param string $type       Shortcode type.
	 * @return array
	 */
	public function filter_shortcode_query( $query_args, $attributes = array(), $type = '' ) {
		if ( 'products' !== $type ) {
			return $query_args;
		}

		$category = null;
		$explicit = array( 'category', 'ids', 'skus', 'attribute', 'terms', 'tag' );
		$has_explicit = false;
		foreach ( $explicit as $key ) {
			if ( ! empty( $attributes[ $key ] ) ) {
				$has_explicit = true;
				break;
			}
		}
		if ( ! $has_explicit ) {
			$category = WSSW_Query::current_category();
		}

		return WSSW_Query::inject( $query_args, WSSW_Query::AUTO, $category );
	}

	/**
	 * Inject URL filters (+ archive context) into xstore/WC product widget queries.
	 *
	 * @param array $query_args Query args.
	 * @return array
	 */
	public function filter_widget_query( $query_args ) {
		return WSSW_Query::inject( $query_args );
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'wssw-style', WSSW_PLUGIN_URL . 'assets/css/style.css', array(), '2.3.2' );
		wp_enqueue_script( 'wssw-script', WSSW_PLUGIN_URL . 'assets/js/scripts.js', array( 'jquery' ), '2.3.2', true );

		$current_cat_id   = 0;
		$current_cat_slug = '';
		if ( is_product_category() ) {
			$term            = get_queried_object();
			$current_cat_id  = (int) get_queried_object_id();
			$current_cat_slug = $term instanceof WP_Term ? $term->slug : '';
		} elseif ( is_singular() || is_page() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['product_cat'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$term = get_term_by( 'slug', sanitize_title( wp_unslash( $_GET['product_cat'] ) ), 'product_cat' );
				if ( $term ) {
					$current_cat_id   = (int) $term->term_id;
					$current_cat_slug = $term->slug;
				}
			}
		}

		wp_localize_script( 'wssw-script', 'wssw_api', array(
			'root'             => esc_url_raw( rest_url( 'wssw/v1/' ) ),
			'current_cat'      => (int) $current_cat_id,
			'current_cat_slug' => (string) $current_cat_slug,
		) );
	}
}

new WSSW_Plugin_V2();
