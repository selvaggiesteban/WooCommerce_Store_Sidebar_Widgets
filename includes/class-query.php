<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared filter parsing + query injection helpers (v2.3.0).
 *
 * Filter state lives in the URL:
 *   ?pa_<attribute>=slug-slug2  (comma separated = OR within one attribute)
 *   ?pa_<other>=slug           (a different attribute = AND across attributes)
 *   ?min_price=1000&max_price=5000
 *
 * The same builder feeds the archive hooks (shortcode + widget queries) and
 * the /wssw/v1/products REST endpoint, so page HTML and REST JSON always agree.
 */
class WSSW_Query {

	const AUTO = 'auto';

	/**
	 * Registered attribute taxonomy names (pa_*).
	 *
	 * @return string[]
	 */
	public static function attribute_taxonomies() {
		$taxes = array();
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $taxes;
		}
		foreach ( wc_get_attribute_taxonomies() as $t ) {
			$taxes[] = wc_attribute_taxonomy_name( $t->attribute_name );
		}
		return $taxes;
	}

	/**
	 * Parse filter state out of an array of request vars (defaults to $_GET).
	 *
	 * @param array|null $src Request vars.
	 * @return array { attr: array<string, string[]>, min: float|null, max: float|null }
	 */
	public static function parse( $src = null ) {
		$filters = array( 'attr' => array(), 'min' => null, 'max' => null );

		if ( null === $src ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$src = $_GET;
		}
		if ( ! is_array( $src ) ) {
			return $filters;
		}

		foreach ( $src as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'pa_' ) ) {
				continue;
			}
			if ( ! taxonomy_exists( $key ) || ! is_string( $value ) ) {
				continue;
			}
			$slugs = array_filter( array_map( 'sanitize_title', explode( ',', $value ) ) );
			if ( $slugs ) {
				$filters['attr'][ $key ] = array_values( array_unique( $slugs ) );
			}
		}

		if ( isset( $src['min_price'] ) && '' !== $src['min_price'] && null !== $src['min_price'] ) {
			$filters['min'] = max( 0, (float) $src['min_price'] );
		}
		if ( isset( $src['max_price'] ) && '' !== $src['max_price'] && null !== $src['max_price'] ) {
			$filters['max'] = max( 0, (float) $src['max_price'] );
		}
		if ( null !== $filters['min'] && null !== $filters['max'] && $filters['min'] > $filters['max'] ) {
			$swap      = $filters['min'];
			$filters['min'] = $filters['max'];
			$filters['max'] = $swap;
		}

		return $filters;
	}

	/**
	 * Any attribute/price filter present?
	 *
	 * @param array $filters Parsed filters.
	 * @return bool
	 */
	public static function has_any( $filters ) {
		return ! empty( $filters['attr'] ) || null !== $filters['min'] || null !== $filters['max'];
	}

	/**
	 * Current product_cat archive term (with children used by callers), or null.
	 *
	 * @return WP_Term|null
	 */
	public static function current_category() {
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term && 'product_cat' === $term->taxonomy ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Build a tax_query (AND across attribute groups, OR within a group,
	 * plus the archive category when provided).
	 *
	 * @param array           $filters Parsed filters.
	 * @param WP_Term|null    $category Category term.
	 * @return array|null
	 */
	public static function build_tax_query( $filters, $category = null ) {
		$tax_query = array();

		if ( $category instanceof WP_Term ) {
			$tax_query[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => (int) $category->term_id,
				'include_children' => true,
			);
		}

		foreach ( (array) $filters['attr'] as $taxonomy => $slugs ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs, // IN = OR within the group.
				'operator' => 'IN',
			);
		}

		return $tax_query ? $tax_query : null;
	}

	/**
	 * Build meta_query clauses for the price range.
	 *
	 * Products store one `_price` row per price point (variables store every
	 * variation price), so "row <= max AND row >= min" reproduces WooCommerce's
	 * native overlap semantics (range intersects the product price range).
	 *
	 * @param array $filters Parsed filters.
	 * @return array
	 */
	public static function build_meta_query( $filters ) {
		$meta = array();
		if ( null !== $filters['min'] ) {
			$meta[] = array(
				'key'     => '_price',
				'value'   => $filters['min'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}
		if ( null !== $filters['max'] ) {
			$meta[] = array(
				'key'     => '_price',
				'value'   => $filters['max'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		return $meta;
	}

	/**
	 * Merge new clauses into an existing tax/meta query keeping its relation.
	 *
	 * @param array $existing Existing query clauses.
	 * @param array $new      New clauses (list of clause arrays).
	 * @return array
	 */
	private static function merge_clauses( $existing, $new ) {
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$relation = ! empty( $existing['relation'] ) ? strtoupper( $existing['relation'] ) : 'AND';
		unset( $existing['relation'] );

		$merged = array( 'relation' => $relation );
		foreach ( $existing as $clause ) {
			$merged[] = $clause;
		}
		foreach ( $new as $clause ) {
			$merged[] = $clause;
		}
		return $merged;
	}

	/**
	 * Inject current filters (+ archive category context) into query args.
	 *
	 * @param array        $query_args Query args to modify.
	 * @param array|string $filters    Parsed filters or self::AUTO to read $_GET.
	 * @param mixed        $category   WP_Term, null (no category) or self::AUTO.
	 * @return array
	 */
	public static function inject( $query_args, $filters = self::AUTO, $category = self::AUTO ) {
		if ( ! is_array( $query_args ) ) {
			return $query_args;
		}
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $query_args;
		}
		if ( ! apply_filters( 'wssw_inject_filters', true ) ) {
			return $query_args;
		}

		if ( self::AUTO === $filters ) {
			$filters = self::parse();
		}
		if ( self::AUTO === $category ) {
			$category = self::current_category();
		}

		$want_category = $category instanceof WP_Term;
		if ( ! $want_category && ! self::has_any( $filters ) ) {
			return $query_args;
		}

		$tax = self::build_tax_query( $filters, $want_category ? $category : null );
		if ( $tax ) {
			$existing_tax = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
			$query_args['tax_query'] = self::merge_clauses( $existing_tax, $tax );
		}

		$meta = self::build_meta_query( $filters );
		if ( $meta ) {
			$existing_meta = isset( $query_args['meta_query'] ) ? $query_args['meta_query'] : array();
			$query_args['meta_query'] = self::merge_clauses( $existing_meta, $meta );
		}

		return self::stabilize_orderby( $query_args );
	}

	/**
	 * Deterministic tie-break for date-ordered grids.
	 *
	 * Bulk-imported products share the same post_date; with ORDER BY date only,
	 * LIMIT/OFFSET pagination orders those ties non-deterministically and rows
	 * are duplicated (or skipped) between pages. Appending ID keeps the visible
	 * order while making pagination stable.
	 *
	 * @param array $query_args Query args.
	 * @return array
	 */
	private static function stabilize_orderby( $query_args ) {
		$orderby = isset( $query_args['orderby'] ) ? $query_args['orderby'] : '';
		if ( is_array( $orderby ) || ( '' !== $orderby && 'date' !== $orderby ) ) {
			return $query_args;
		}
		$order  = isset( $query_args['order'] ) ? strtoupper( (string) $query_args['order'] ) : 'DESC';
		$dir    = ( 'ASC' === $order ) ? 'ASC' : 'DESC';
		$query_args['orderby'] = array(
			'date' => $dir,
			'ID'   => $dir,
		);
		return $query_args;
	}
}
