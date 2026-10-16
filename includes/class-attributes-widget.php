<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSSW_Attributes_Widget {
	public function __construct() {
		add_shortcode( 'woo_custom_attributes', array( $this, 'render_shortcode' ) );
	}

	public function render_shortcode() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( empty( $attribute_taxonomies ) ) {
			return '<p>No se encontraron atributos.</p>';
		}

		ob_start();
		echo '<div class="wssw-attributes-container">';
		
		foreach ( $attribute_taxonomies as $tax ) {
			$taxonomy_name = wc_attribute_taxonomy_name( $tax->attribute_name );
			$terms = get_terms( array(
				'taxonomy' => $taxonomy_name,
				'hide_empty' => false,
			) );

			if ( ! empty( $terms ) ) {
				echo '<div class="wssw-attribute-group">';
				echo '<h4 class="wssw-attribute-title">' . esc_html( $tax->attribute_label ) . '</h4>';
				echo '<div class="wssw-attribute-tags">';
				
				foreach ( $terms as $term ) {
					// Build WooCommerce filter URL
					$link = add_query_arg( $taxonomy_name, $term->slug, home_url( '/shop/' ) );
					echo '<a href="' . esc_url( $link ) . '" class="wssw-attribute-tag">' . esc_html( $term->name ) . '</a>';
				}
				
				echo '</div>';
				echo '</div>';
			}
		}
		
		echo '</div>';
		return ob_get_clean();
	}
}
