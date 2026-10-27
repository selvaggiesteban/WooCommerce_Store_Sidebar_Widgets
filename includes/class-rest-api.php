<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WSSW REST API (v2.3.2) — Context-aware filters.
 *
 * Routes now accept ?category=slug to scope results to the current archive.
 * - /categories?category=slug → children of that category (subcategories)
 * - /attributes?category=slug → only attributes with terms on products in that category
 * - /price-range?category=slug → min/max for that category (already works)
 * - /products?category=slug   → filtered products (already works)
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
	 * Categories.
	 * - ?tree=1 → full tree (unchanged, for backward compat)
	 * - ?parent=N → children of N (unchanged)
	 * - ?category=slug → children of that category (NEW: context-aware)
	 * - no params → top-level categories (shop page)
	 */
	public function get_categories( $request ) {
		$want_tree = ! empty( $request['tree'] );

		// Context category (NEW)
		$context_cat = null;
		$cat_slug    = isset( $request['category'] ) ? sanitize_title( $request['category'] ) : '';
		if ( $cat_slug ) {
			$term = get_term_by( 'slug', $cat_slug, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$context_cat = $term;
			}
		}

		if ( $want_tree ) {
			// If context category provided with tree=1, return tree starting from that category
			$root_parent = 0;
			if ( $context_cat ) {
				$root_parent = (int) $context_cat->term_id;
				// Include the context category itself as the root
				$include_self = true;
			}

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
						'id'            => (int) $t->term_id,
						'name'          => $t->name,
						'slug'          => $t->slug,
						'link'          => get_term_link( $t ),
						'count'         => (int) $t->count,
						'has_children'  => (bool) $children,
						'children'      => $children,
					);
				}
				return $out;
			};

			// If context category, build tree starting from it
			if ( $context_cat ) {
				// Get the context category data
				$context_term = get_term( $context_cat->term_id, 'product_cat' );
				if ( $context_term && ! is_wp_error( $context_term ) ) {
					$children = $build( (int) $context_cat->term_id );
					$response = array(
						'id'            => (int) $context_cat->term_id,
						'name'          => $context_term->name,
						'slug'          => $context_term->slug,
						'link'          => get_term_link( $context_term ),
						'count'         => (int) $context_term->count,
						'has_children'  => (bool) $children,
						'children'      => $children,
					);
					return rest_ensure_response( array( $response ) );
				}
			}

			return rest_ensure_response( $build( $root_parent ) );
		}

		// Determine parent: explicit ?parent=N takes priority, else context category, else 0 (top-level)
		$p = isset( $request['parent'] ) ? intval( $request['parent'] ) : 0;
		if ( 0 === $p && $context_cat ) {
			$p = (int) $context_cat->term_id;
		}

		$cats = get_terms( array(
			'taxonomy'   => 'product_cat',
			'parent'     => $p,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );
		if ( is_wp_error( $cats ) ) {
			return new WP_Error( 'wssw_terms_error', 'Error recuperando categorías', array( 'status' => 500 ) );
		}
		$data = array();
		foreach ( $cats as $c ) {
			$data[] = array(
				'id'            => (int) $c->term_id,
				'name'          => $c->name,
				'slug'          => $c->slug,
				'link'          => get_term_link( $c ),
				'count'         => (int) $c->count,
				'has_children'  => ! empty( get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $c->term_id, 'hide_empty' => false ) ) ),
			);
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Attributes — filtered to current category context.
	 * Accepts ?category=slug to scope terms to products in that category.
	 */
	public function get_attributes( $request ) {
		$taxes = WSSW_Query::attribute_taxonomies();
		$data  = array();

		// Context category (NEW)
		$context_cat = null;
		$cat_slug    = isset( $request['category'] ) ? sanitize_title( $request['category'] ) : '';
		if ( $cat_slug ) {
			$term = get_term_by( 'slug', $cat_slug, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$context_cat = $term;
			}
		}

		// If context category, get product IDs in that category (including children)
		$context_product_ids = array();
		if ( $context_cat ) {
			$context_product_ids = get_objects_in_term( $context_cat->term_id, 'product_cat' );
			if ( is_wp_error( $context_product_ids ) ) {
				$context_product_ids = array();
			}
			$context_product_ids = array_map( 'intval', $context_product_ids );
		}

		foreach ( $taxes as $name ) {
			$tax  = get_taxonomy( $name );
			$args = array( 'taxonomy' => $name, 'hide_empty' => false );

			// If we have context product IDs, filter terms to only those used by those products
			if ( $context_product_ids ) {
				$args['object_ids'] = $context_product_ids;
			}

			$terms = get_terms( $args );
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
	 * Catalog price bounds — already accepts ?category=slug (unchanged).
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
				$min    = $wpdb->get_var( "SELECT MIN(min_price) FROM {$table} WHERE product_id IN ($in)" );
				$max    = $wpdb->get_var( "SELECT MAX(max_price) FROM {$table} WHERE product_id IN ($in)" );
			} else {
				$min = $wpdb->get_var( "SELECT MIN(min_price) FROM {$table}" );
				$max = $wpdb->get_var( "SELECT MAX(max_price) FROM {$table}" );
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
	 * Filtered products — unchanged (already supports ?category=).
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