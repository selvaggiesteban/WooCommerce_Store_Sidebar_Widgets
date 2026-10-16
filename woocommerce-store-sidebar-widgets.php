<?php
/**
 * Plugin Name: WooCommerce Store Sidebar Widgets (Responsive)
 * Description: Stable sidebar widgets using WP-REST API with Responsive Fullscreen Filter. Filters are wired to the product archive queries (attributes, price and category context).
 * Version: 2.3.0
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
		add_shortcode( 'wssw_category_cards', array( $this, 'render_category_cards' ) );

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
	 * Category entry cards for the home/catalog sections.
	 * Uses the product_cat thumbnail when set, otherwise the image of the most
	 * recent product in the category, otherwise the WooCommerce placeholder.
	 *
	 *_atts: limit, columns, parent, hide_empty, show_count
	 */
	public function render_category_cards( $atts ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'limit'       => 8,
			'columns'     => 4,
			'parent'      => 0,
			'hide_empty'  => 'true',
			'show_count'  => 'true',
		), $atts, 'wssw_category_cards' );

		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'parent'     => (int) $atts['parent'],
			'hide_empty' => 'true' === (string) $atts['hide_empty'] || true === $atts['hide_empty'],
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => max( 1, (int) $atts['limit'] ),
			'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
		) );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '<p class="wssw-category-cards-empty">No hay categorías disponibles.</p>';
		}

		$columns = max( 1, min( 6, (int) $atts['columns'] ) );
		$show_count = 'true' === (string) $atts['show_count'] || true === $atts['show_count'];

		ob_start();
		echo '<div class="wssw-category-cards wssw-columns-' . esc_attr( $columns ) . '">';
		foreach ( $terms as $term ) {
			$src = $this->category_image_src( $term );
			echo '<a class="wssw-category-card" href="' . esc_url( get_term_link( $term ) ) . '">';
			echo '<span class="wssw-category-card-image">';
			echo '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $term->name ) . '" loading="lazy" decoding="async" />';
			echo '</span>';
			echo '<span class="wssw-category-card-title">' . esc_html( $term->name ) . '</span>';
			if ( $show_count && $term->count > 0 ) {
				echo '<span class="wssw-category-card-count">' . esc_html( sprintf( _n( '%s producto', '%s productos', $term->count, 'woocommerce-store-sidebar-widgets' ), number_format_i18n( $term->count ) ) ) . '</span>';
			}
			echo '</a>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Term thumbnail, falling back to the newest product image in the category.
	 *
	 * @param WP_Term $term Category term.
	 * @return string
	 */
	private function category_image_src( $term ) {
		$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' );
			if ( $src ) {
				return $src;
			}
		}

		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'tax_query'      => array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => (int) $term->term_id,
					'include_children' => true,
				),
			),
		) );

		if ( $ids ) {
			$src = wp_get_attachment_image_url( (int) get_post_meta( $ids[0], '_thumbnail_id', true ), 'woocommerce_thumbnail' );
			if ( $src ) {
				return $src;
			}
		}

		return wc_placeholder_img_src();
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
		wp_enqueue_style( 'wssw-style', WSSW_PLUGIN_URL . 'assets/css/style.css', array(), '2.3.0' );
		wp_enqueue_script( 'wssw-script', WSSW_PLUGIN_URL . 'assets/js/scripts.js', array( 'jquery' ), '2.3.0', true );

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
