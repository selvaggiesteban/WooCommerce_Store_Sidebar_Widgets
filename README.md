# WooCommerce Store Sidebar Widgets

Store filter widgets (categories, attributes, price) with a responsive fullscreen
filter overlay, wired to the actual product archive queries.

Requires WordPress + WooCommerce (tested with the xstore theme).

## Shortcodes

### 1. `[wssw_categories]`
Hierarchical product categories tree (renders into `#wssw-categories-root`,
populated via REST `wssw/v1/categories?tree=1`). The branch of the current
product category is expanded; clicking a category navigates to its archive.

### 2. `[wssw_attributes]`
Attribute groups with term tags (renders into `#wssw-attributes-root`,
REST `wssw/v1/attributes`). Clicking a tag toggles its `pa_*` filter in the
URL and reloads the archive (comma-OR within a group, AND across groups).

### 3. `[wssw_price]`
Dual price range sliders + "Filtrar" button (renders into `#wssw-price-root`,
REST `wssw/v1/price-range`). Applies WooCommerce-native `min_price` /
`max_price` URL parameters.

### 4. `[wssw_responsive_filters]`
The complete filter block (toggle button, fullscreen overlay on mobile,
inline columns on desktop, active-filter chips, results counter).

## Filtering mechanism (v2.3.0)

Filter state lives in the URL and the plugin injects it into the page's own
product query, so results always render with the theme's grid markup:

- `?pa_<attribute>=slug1,slug2` — slugs OR'ed within the same attribute,
  AND'ed across different attributes.
- `?min_price=` / `?max_price=` — WooCommerce-native price params
  (overlap semantics against every product `_price` row, incl. variations).
- Product category archives keep their category context (`product_cat`)
  for all filters.

Query injection points:

- `woocommerce_shortcode_products_query` — the WC `[products]` shortcode
  (xstore archive/home templates); category context is applied when the
  shortcode does not set its own `category`/`ids`/`skus`/`attribute`/
  `terms`/`tag` attributes.
- `woocommerce_products_widget_query_args` — widget-based grids
  (xstore `etheme_product_grid`, WooCommerce products widget).

URL parsing + query building live in `includes/class-query.php`
(`WSSW_Query::parse()` / `WSSW_Query::inject()`).

Client (`assets/js/scripts.js`) renders the controls from REST, builds
state-aware links, tracks active filters as removable chips with
"Limpiar filtros", shows a "N productos encontrados" counter
(`wssw/v1/products?count_only=1`), rebuilds pagination links so they keep
the active filters, and opens/closes the mobile fullscreen overlay.

All navigation uses capture-phase click handlers so theme pjax / ajax
filters don't intercept the links.

## REST endpoints

- `GET /wp-json/wssw/v1/categories` — `?parent=N` children, `?tree=1` full tree
- `GET /wp-json/wssw/v1/attributes` — attribute groups with term slugs
- `GET /wp-json/wssw/v1/price-range` — `?category=slug` (defaults to catalog)
- `GET /wp-json/wssw/v1/products` — filtered products JSON
  (`category`, `pa_*`, `min_price`, `max_price`, `orderby`, `page`,
  `per_page`, `count_only`)

## Known limitations

- Ordering is left to the theme/WC (`orderby` in the URL is not applied by
  the xstore product-grid widget).
- Result counts are fetched asynchronously; the grid itself is server
  rendered.

## Installation

1. Upload the `woocommerce-store-sidebar-widgets` folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress dashboard.
3. Place `[wssw_responsive_filters]` in Elementor templates or any other
   page builder.
