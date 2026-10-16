<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSSW_Price_Widget {
	public function __construct() {
		add_shortcode( 'woo_custom_price_filter', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_wssw_filter_price', array( $this, 'ajax_filter_price' ) );
		add_action( 'wp_ajax_nopriv_wssw_filter_price', array( $this, 'ajax_filter_price' ) );
	}

	public function render_shortcode() {
		$min_price = 0;
		$max_price = 1000000; // Default high

		try {
			$max_price = get_transient( 'wssw_max_price' );
			if ( false === $max_price ) {
				$products = wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) );
				if ( ! empty( $products ) ) {
					$prices = array();
					foreach ( $products as $product_id ) {
						$product = wc_get_product( $product_id );
						if ( $product ) {
							$prices[] = (float) $product->get_price();
						}
					}
					if ( ! empty( $prices ) ) {
						$max_price = max( $prices );
						set_transient( 'wssw_max_price', $max_price, DAY_IN_SECONDS );
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'WSSW Price Widget Error: ' . $e->getMessage() );
		}

		ob_start();
		?>
		<div class="wssw-price-filter">
			<h4 class="wssw-attribute-title">Filtrar por Precio</h4>
			<div class="wssw-price-slider-container">
				<input type="range" id="wssw-price-min" min="0" max="<?php echo esc_attr( $max_price ); ?>" value="0" class="wssw-price-slider">
				<input type="range" id="wssw-price-max" min="0" max="<?php echo esc_attr( $max_price ); ?>" value="<?php echo esc_attr( $max_price ); ?>" class="wssw-price-slider">
				<div class="wssw-price-values">
					<span>Desde: <strong id="wssw-min-val">0</strong> $</span>
					<span>Hasta: <strong id="wssw-max-val"><?php echo esc_html( $max_price ); ?></strong> $</span>
				</div>
				<button id="wssw-filter-btn" class="wssw-filter-button">Filtrar</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function ajax_filter_price() {
		check_ajax_referer( 'wssw_price_filter_nonce', 'nonce' );

		$min = isset( $_POST['min'] ) ? intval( $_POST['min'] ) : 0;
		$max = isset( $_POST['max'] ) ? intval( $_POST['max'] ) : 1000000;

		// Use WooCommerce native query for price filter
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => '_price',
					'value'   => array( $min, $max ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				),
			),
		);

		$query = new WP_Query( $args );

		ob_start();
		if ( $query->have_posts() ) {
			woocommerce_product_loop_start();
			while ( $query->have_posts() ) {
				$query->the_post();
				wc_get_template_part( 'content', 'product' );
			}
			woocommerce_product_loop_end();
		} else {
			echo '<p>No se encontraron productos en este rango de precio.</p>';
		}
		wp_reset_postdata();

		echo ob_get_clean();
		wp_die();
	}
}
