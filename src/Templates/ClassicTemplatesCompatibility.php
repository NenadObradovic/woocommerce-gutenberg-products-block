<?php
namespace Automattic\WooCommerce\Blocks\Templates;

use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;

/**
 * ClassicTemplatesCompatibility class.
 *
 * To bridge the gap on compatibility with widget blocks and classic PHP core templates.
 *
 * @internal
 */
class ClassicTemplatesCompatibility {

	/**
	 * Instance of the asset data registry.
	 *
	 * @var AssetDataRegistry
	 */
	protected $asset_data_registry;

	/**
	 * Constructor.
	 *
	 * @param AssetDataRegistry $asset_data_registry Instance of the asset data registry.
	 */
	public function __construct( AssetDataRegistry $asset_data_registry ) {
		$this->asset_data_registry = $asset_data_registry;
		$this->init();
	}

	/**
	 * Initialization method.
	 */
	protected function init() {
		if ( ! wc_current_theme_is_fse_theme() ) {
			add_action( 'template_redirect', array( $this, 'set_classic_template_data' ) );
		}
	}

	/**
	 * Executes the methods which set the necessary data needed for filter blocks to work correctly as widgets in Classic templates.
	 *
	 * @return void
	 */
	public function set_classic_template_data() {
		$this->set_filterable_product_data();
		$this->set_php_template_data();
	}

	/**
	 * This method passes the value `has_filterable_products` to the front-end for product archive pages,
	 * so that widget product filter blocks are aware of the context they are in and can render accordingly.
	 *
	 * @return void
	 */
	public function set_filterable_product_data() {
		if ( is_shop() || is_product_taxonomy() ) {
			$this->asset_data_registry->add( 'has_filterable_products', true, null );
		}
	}

	/**
	 * This method passes the value `is_rendering_php_template` to the front-end of Classic themes,
	 * so that widget product filter blocks are aware of how to filter the products.
	 *
	 * @return void
	 */
	public function set_php_template_data() {
		$this->asset_data_registry->add( 'is_rendering_php_template', true, null );
	}
}
