# WooCommerce Store Sidebar Widgets

A high-performance, asynchronous sidebar filtering system for WooCommerce stores. This plugin replaces traditional server-side PHP widgets with a modern REST API and Client-Side Rendering (CSR) architecture to ensure stability and compatibility with dynamic page builders like Elementor.

## Features

- **Asynchronous Category Navigation**: Hierarchical category browsing with dynamic subcategory loading via WP-REST API.
- **Attribute Filtering**: Fast, tag-based attribute filtering for product variations.
- **Dynamic Price Range**: Interactive price slider with real-time value updates and automatic range detection.
- **Elementor Compatible**: Designed to function within dynamic templates without causing server-side fatal errors or layout shifts.
- **Deep Linking**: Intelligent state detection that identifies the current category and renders the appropriate branch of the hierarchy.

## Technical Architecture

The plugin utilizes a decoupled architecture to maximize performance and stability:
- **Backend**: Custom REST API endpoints (`/wp-json/wssw/v1/`) that provide structured JSON data for categories, attributes, and price ranges.
- **Frontend**: A lightweight JavaScript layer that handles data fetching and DOM manipulation, reducing server load and eliminating PHP execution conflicts during page rendering.
- **CSS**: Optimized Flexbox layouts for a responsive and modern user interface.

## Installation

1. Upload the `woocommerce-store-sidebar-widgets` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress Admin Dashboard.

## Usage

The plugin provides three shortcodes to be placed within your sidebar or store templates:

- `[wssw_categories]`: Renders the hierarchical category navigator.
- `[wssw_attributes]`: Renders the attribute filter tags.
- `[wssw_price]`: Renders the price range slider.

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- jQuery (included by default in WordPress)

## License

Proprietary. All rights reserved.
