<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSSW_Categories_Widget {
	public function __construct() {
		add_shortcode( 'woo_custom_categories', array( $this, 'render_shortcode' ) );
	}

	public function render_shortcode() {
		try {
			$current_cat = get_queried_object();
			$parent_id = 0;
			$is_category_page = is_product_category();

			if ( $is_category_page && $current_cat ) {
				$parent_id = $current_cat->term_id;
			}

			ob_start();
			echo '<div class="wssw-categories-container">';

			if ( $parent_id > 0 ) {
				echo '<a href="' . esc_url( get_product_category_link( 0 ) ) . '" class="wssw-back-link">← Volver atrás</a>';
			}

			echo '<ul class="wssw-category-list">';
			$this->render_category_level( $parent_id, 0 );
			echo '</ul>';

			echo '</div>';
			return ob_get_clean();
		} catch ( Exception $e ) {
			error_log( 'WSSW Categories Widget Error: ' . $e->getMessage() );
			return '<p>Error cargando categorías.</p>';
		}
	}

	private function render_category_level( $parent_id, $depth = 0 ) {
		if ( $depth > 5 ) {
			return;
		}

		$args = array(
			'taxonomy'     => 'product_cat',
			'parent'       => $parent_id,
			'hide_empty'   => false,
		);
		$categories = get_terms( $args );

		if ( ! empty( $categories ) ) {
			foreach ( $categories as $cat ) {
				$children = get_terms( array(
					'taxonomy' => 'product_cat',
					'parent'   => $cat->term_id,
					'hide_empty' => false,
				) );
				$has_children = ! empty( $children );

				echo '<li class="wssw-category-item' . ( $has_children ? ' has-children' : '' ) . '">';
				echo '<div class="wssw-category-link-wrapper">';
				echo '<a href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
				if ( $has_children ) {
					echo '<span class="wssw-toggle-icon">▼</span>';
				}
				echo '</div>';

				if ( $has_children ) {
					echo '<ul class="wssw-subcategory-list">';
					$this->render_category_level( $cat->term_id, $depth + 1 );
					echo '</ul>';
				}
				echo '</li>';
			}
		}
	}
}
