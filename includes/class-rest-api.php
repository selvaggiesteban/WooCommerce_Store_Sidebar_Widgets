<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WSSW REST API (v2.3.0).
 *
 * Routes:
 *   GET /wssw/v1/categories   ?parent=N (flat children) or ?tree=1 (full tree)
 *   GET /wssw/v1/attributes   attribute groups + term slugs/counts
 *   GET /wssw/v1/price-range  catalog min/max (?category=slug optional)
 *   GET /wssw/v1/products     filtered products JSON (?category=&pa_*=..&min_price=&max_price=&orderby=&page=&per_page=&count_only=1)
 */
class WSSW_Rest_API {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'wssw/v1', '/categories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_categories' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wssw/v1', '/attributes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_attributes' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wssw/v1', '/price-range', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_price_range' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wssw/v1', '/products', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_products' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Categories. ?tree=1 returns the full hierarchy, ?parent=N the children of N.
	 */
	public function get_categories( $request ) {
		$want_tree = ! empty( $request['tree'] );

		if ( $want_tree ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
			if ( is_wp_error( $terms ) ) {
				return new WP_Error( 'wssw_terms_error', 'Error recuperando categorías', array( 'status' => 500 ) );
			}
			$by_parent = array();
			foreach ( $terms as $t ) {
				$by_parent[ (int) $t->parent ][] = $t;
			}
			$build = function ( $parent ) use ( &$build, $by_parent ) {
				$out = array();
				if ( empty( $by_parent[ $parent ] ) ) {
					return $out;
				}
				foreach ( $by_parent[ $parent ] as $t ) {
					$children = $build( (int) $t->term_id );
					$out[]    = array(
						'id'          => (int) $t->term_id,
						'name'        => $t->name,
						'slug'        => $t->slug,
						'link'        => get_term_link( $t ),
						'count'       => (int) $t->count,
						'has_children' => (bool) $children,
						'children'    => $children,
					);
				}
				return $out;
			};
			return rest_ensure_response( $build( 0 ) );
		}

		$p    = isset( $request['parent'] ) ? intval( $request['parent'] ) : 0;
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $p, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $cats ) ) {
			return new WP_Error( 'wssw_terms_error', 'Error recuperando categorías', array( 'status' => 500 ) );
		}
		$data = array();
		foreach ( $cats as $c ) {
			$data[] = array(
				'id'           => (int) $c->term_id,
				'name'         => $c->name,
				'slug'         => $c->slug,
				'link'         => get_term_link( $c ),
				'count'        => (int) $c->count,
				'has_children' => ! empty( get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $c->term_id, 'hide_empty' => false ) ) ),
			);
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Attribute groups with term slugs (needed for active-filter labels + tags).
	 */
	public function get_attributes( $request ) {
		$taxes = WSSW_Query::attribute_taxonomies();
		$data  = array();
		foreach ( $taxes as $name ) {
			$tax  = get_taxonomy( $name );
			$terms = get_terms( array( 'taxonomy' => $name, 'hide_empty' => false ) );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}
			$tl = array();
			foreach ( $terms as $tm ) {
				$tl[] = array(
					'name'  => $tm->name,
					'slug'  => $tm->slug,
					'count' => (int) $tm->count,
					'link'  => add_query_arg( $name, $tm->slug, home_url( '/shop/' ) ),
				);
			}
			$data[] = array(
				'taxonomy' => $name,
				'label'    => $tax && $tax->labels ? $tax->labels->singular_name : $name,
				'terms'    => $tl,
			);
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Catalog price bounds (WooCommerce lookup table, optional ?category=slug).
	 */
	public function get_price_range( $request ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_product_meta_lookup';
		$min   = null;
		$max   = null;

		$category = isset( $request['category'] ) ? sanitize_title( $request['category'] ) : '';
		$cat_ids  = array();
		if ( $category ) {
			$term = get_term_by( 'slug', $category, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$cat_ids = get_objects_in_term( $term->term_id, 'product_cat' );
			}
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			if ( $cat_ids ) {
				$in     = implode( ',', array_map( 'intval', $cat_ids ) );
				$min    = $wpdb->get_var( "SELECT MIN(min_price) FROM {$table} WHERE product_id IN ($in)" ); // phpcs:ignore WordPress.DB
				$max    = $wpdb->get_var( "SELECT MAX(max_price) FROM {$table} WHERE product_id IN ($in)" ); // phpcs:ignore WordPress.DB
			} else {
				$min = $wpdb->get_var( "SELECT MIN(min_price) FROM {$table}" ); // phpcs:ignore WordPress.DB
				$max = $wpdb->get_var( "SELECT MAX(max_price) FROM {$table}" ); // phpcs:ignore WordPress.DB
			}
		}

		if ( null === $min || null === $max ) {
			$min = 0;
			$max = 1000000;
			$pids = wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) );
			if ( ! empty( $pids ) ) {
				$prices = array();
				foreach ( $pids as $id ) {
					$prod = wc_get_product( $id );
					if ( $prod ) {
						$prices[] = (float) $prod->get_price();
					}
				}
				if ( ! empty( $prices ) ) {
					$max = max( $prices );
				}
			}
		}

		return rest_ensure_response( array(
			'min_price' => (float) $min,
			'max_price' => (float) $max,
		) );
	}

	/**
	 * Filtered products (JSON). Same query builder used by the archive hooks,
	 * so results always match what the page grid shows.
	 */
	public function get_products( $request ) {
		$src       = $request->get_params();
		$filters   = WSSW_Query::parse( $src );
		$category  = null;
		$cat_slug  = isset( $src['category'] ) ? sanitize_title( $src['category'] ) : '';
		if ( $cat_slug ) {
			$term = get_term_by( 'slug', $cat_slug, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$category = $term;
			}
		}

		$per_page  = isset( $src['per_page'] ) ? (int) $src['per_page'] : 12;
		if ( 0 === $per_page || $per_page > 100 ) {
			$per_page = 100;
		}
		$page      = isset( $src['page'] ) ? max( 1, (int) $src['page'] ) : 1;
		$count_only = ! empty( $src['count_only'] );

		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'tax_query'           => array( 'relation' => 'AND' ),
			'meta_query'          => array(),
		);

		$visibility = function_exists( 'wc_get_product_visibility_term_ids' ) ? wc_get_product_visibility_term_ids() : array();
		if ( ! empty( $visibility['exclude-from-catalog'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( $visibility['exclude-from-catalog'] ),
				'operator' => 'NOT IN',
			);
		}

		$args = WSSW_Query::inject( $args, $filters, $category );

		$orderby = isset( $src['orderby'] ) ? sanitize_text_field( wp_unslash( $src['orderby'] ) ) : 'date';
		if ( function_exists( 'WC' ) && WC()->query ) {
			$parts           = explode( '-', $orderby );
			$ordering        = WC()->query->get_catalog_ordering_args( $parts[0], isset( $parts[1] ) ? strtoupper( $parts[1] ) : 'DESC' );
			$args['orderby'] = $ordering['orderby'];
			$args['order']   = $ordering['order'];
			if ( ! empty( $ordering['meta_key'] ) ) {
				$args['meta_key'] = $ordering['meta_key'];
			}
		}

		// Same deterministic tie-break as the archive hooks (bulk-imported
		// products share post_date; ties must paginate identically).
		if ( empty( $args['orderby'] ) || 'date' === $args['orderby'] ) {
			$dir                = ( isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'ASC' : 'DESC';
			$args['orderby']    = array(
				'date' => $dir,
				'ID'   => $dir,
			);
		}

		$query = new WP_Query( $args );

		$response = array(
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'page'     => $page,
			'per_page' => $per_page,
		);

		if ( ! $count_only ) {
			$products = array();
			foreach ( $query->posts as $post ) {
				$pid = $post instanceof WP_Post ? $post->ID : (int) $post;
				$prod = wc_get_product( $pid );
				if ( ! $prod ) {
					continue;
				}
				$cats = array();
				foreach ( (array) wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) ) as $cname ) {
					$cats[] = $cname;
				}
				$products[] = array(
					'id'          => (int) $pid,
					'name'        => $prod->get_name(),
					'url'         => $prod->get_permalink(),
					'image'       => wp_get_attachment_image_url( $prod->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src(),
					'price'       => (float) $prod->get_price(),
					'price_html'  => $prod->get_price_html(),
					'in_stock'    => $prod->is_in_stock(),
					'categories'  => $cats,
					'attributes'  => self::product_attribute_slugs( $prod ),
				);
			}
			$response['products'] = $products;
		}

		wp_reset_postdata();
		return rest_ensure_response( $response );
	}

	/**
	 * Attribute taxonomy => list of slugs, for verification + consumers.
	 *
	 * @param WC_Product $prod Product.
	 * @return array
	 */
	private static function product_attribute_slugs( $prod ) {
		$out = array();
		foreach ( WSSW_Query::attribute_taxonomies() as $tax ) {
			$terms = wp_get_post_terms( $prod->get_id(), $tax, array( 'fields' => 'slugs' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$out[ $tax ] = $terms;
			}
		}
		return $out;
	}
}
