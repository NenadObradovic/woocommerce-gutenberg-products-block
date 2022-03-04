<?php
namespace Automattic\WooCommerce\StoreApi\Routes\V1;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\SchemaController;
use Automattic\WooCommerce\StoreApi\Schemas\V1\AbstractSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use Automattic\WooCommerce\StoreApi\Utilities\DraftOrderTrait;
use Automattic\WooCommerce\StoreApi\Utilities\OrderController;
/**
 * Abstract Cart Route
 *
 * @internal This API is used internally by Blocks--it is still in flux and may be subject to revisions.
 */
abstract class AbstractCartRoute extends AbstractRoute {
	use DraftOrderTrait;

	/**
	 * The routes schema.
	 *
	 * @var string
	 */
	const SCHEMA_TYPE = 'cart';

	/**
	 * Schema class for the cart.
	 *
	 * @var CartSchema
	 */
	protected $cart_schema;

	/**
	 * Cart controller class instance.
	 *
	 * @var CartController
	 */
	protected $cart_controller;

	/**
	 * Order controller class instance.
	 *
	 * @var OrderController
	 */
	protected $order_controller;

	/**
	 * Constructor.
	 *
	 * @param SchemaController $schema_controller Schema Controller instance.
	 * @param AbstractSchema   $schema Schema class for this route.
	 */
	public function __construct( SchemaController $schema_controller, AbstractSchema $schema ) {
		$this->schema_controller = $schema_controller;
		$this->schema            = $schema;
		$this->cart_schema       = $this->schema_controller->get( CartSchema::IDENTIFIER );
		$this->cart_item_schema  = $this->schema_controller->get( CartItemSchema::IDENTIFIER );
		$this->cart_controller   = new CartController();
		$this->order_controller  = new OrderController();
	}

	/**
	 * Are we updating data or getting data?
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return boolean
	 */
	protected function is_update_request( \WP_REST_Request $request ) {
		return in_array( $request->get_method(), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true );
	}

	/**
	 * Get the route response based on the type of request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_response( \WP_REST_Request $request ) {
		$this->cart_controller->load_cart();
		$this->calculate_totals();

		try {
			$response = parent::get_response( $request );
		} catch ( RouteException $error ) {
			$response = $this->get_route_error_response( $error->getErrorCode(), $error->getMessage(), $error->getCode(), $error->getAdditionalData() );
		} catch ( \Exception $error ) {
			$response = $this->get_route_error_response( 'unknown_server_error', $error->getMessage(), 500 );
		}

		if ( is_wp_error( $response ) ) {
			$response = $this->error_to_response( $response );
		} elseif ( $this->is_update_request( $request ) ) {
			$this->cart_updated( $request );
		}

		if ( $this->has_rate_limit( $request ) ) {
			$this->update_rate_limit();
		}

		return $this->add_response_headers(
			$response,
			array_merge(
				$this->requires_nonce( $request ) ? $this->get_nonce_headers() : [],
				$this->has_rate_limit( $request ) ? $this->get_rate_limit_headers() : []
			)
		);
	}

	/**
	 * Triggered after an update to cart data. Re-calculates totals and updates draft orders (if they already exist) to
	 * keep all data in sync.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	protected function cart_updated( \WP_REST_Request $request ) {
		$draft_order = $this->get_draft_order();

		if ( $draft_order ) {
			$this->order_controller->update_order_from_cart( $draft_order );

			/**
			 * Fires when the order is synced with cart data from a cart route.
			 *
			 * @param \WC_Order $draft_order Order object.
			 * @param \WC_Customer $customer Customer object.
			 * @param \WP_REST_Request $request Full details about the request.
			 */
			do_action( 'woocommerce_blocks_cart_update_order_from_request', $draft_order, $request );
		}
	}

	/**
	 * Ensures the cart totals are calculated before an API response is generated.
	 */
	protected function calculate_totals() {
		wc()->cart->get_cart();
		wc()->cart->calculate_fees();
		wc()->cart->calculate_shipping();
		wc()->cart->calculate_totals();
	}

	/**
	 * Checks if a nonce is required for the route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function requires_nonce( \WP_REST_Request $request ) {
		return $this->is_update_request( $request );
	}

	/**
	 * Checks if rate limits are enabled for the route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function has_rate_limit( \WP_REST_Request $request ) {
		return ! empty( $this->rate_limit_id ) && $this->is_update_request( $request );
	}

	/**
	 * Get a list of nonce headers.
	 *
	 * @return array
	 */
	protected function get_nonce_headers() {
		return [
			'X-WC-Store-API-Nonce'           => wp_create_nonce( 'wc_store_api' ),
			'X-WC-Store-API-Nonce-Timestamp' => time(),
			'X-WC-Store-API-User'            => get_current_user_id(),
		];
	}

	/**
	 * For non-GET endpoints, require and validate a nonce to prevent CSRF attacks.
	 *
	 * Nonces will mismatch if the logged in session cookie is different! If using a client to test, set this cookie
	 * to match the logged in cookie in your browser.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|boolean
	 */
	protected function check_nonce( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WC-Store-API-Nonce' );

		/**
		 * Filters the Store API nonce check.
		 *
		 * This can be used to disable the nonce check when testing API endpoints via a REST API client.
		 *
		 * @param boolean $disable_nonce_check If true, nonce checks will be disabled.
		 * @return boolean
		 */
		if ( apply_filters( 'woocommerce_store_api_disable_nonce_check', false ) ) {
			return true;
		}

		if ( null === $nonce ) {
			return $this->get_route_error_response( 'woocommerce_store_api_missing_nonce', __( 'Missing the X-WC-Store-API-Nonce header. This endpoint requires a valid nonce.', 'woo-gutenberg-products-block' ), 401 );
		}

		if ( ! wp_verify_nonce( $nonce, 'wc_store_api' ) ) {
			return $this->get_route_error_response( 'woocommerce_store_api_missing_nonce', __( 'X-WC-Store-API-Nonce is invalid.', 'woo-gutenberg-products-block' ), 403 );
		}

		return true;
	}

	/**
	 * Get route response when something went wrong.
	 *
	 * @param string $error_code String based error code.
	 * @param string $error_message User facing error message.
	 * @param int    $http_status_code HTTP status. Defaults to 500.
	 * @param array  $additional_data  Extra data (key value pairs) to expose in the error response.
	 * @return \WP_Error WP Error object.
	 */
	protected function get_route_error_response( $error_code, $error_message, $http_status_code = 500, $additional_data = [] ) {
		switch ( $http_status_code ) {
			case 409:
				// If there was a conflict, return the cart so the client can resolve it.
				$cart = $this->cart_controller->get_cart_instance();

				return new \WP_Error(
					$error_code,
					$error_message,
					array_merge(
						$additional_data,
						[
							'status' => $http_status_code,
							'cart'   => $this->cart_schema->get_item_response( $cart ),
						]
					)
				);
		}
		return new \WP_Error( $error_code, $error_message, [ 'status' => $http_status_code ] );
	}

	/**
	 * Runs before a request is handled.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return boolean True if the user has permission to make the request.
	 */
	public function permission_callback( \WP_REST_Request $request ) {
		// Load cart early so sessions are available.
		$this->cart_controller->load_cart();
		$return = true;

		if ( $this->requires_nonce( $request ) ) {
			$result = $this->check_nonce( $request );

			if ( is_wp_error( $result ) ) {
				$return = $result;
			}
		}

		if ( $this->has_rate_limit( $request ) ) {
			$result = $this->check_rate_limit();

			if ( is_wp_error( $result ) ) {
				$return = $result;
			}
		}

		if ( is_wp_error( $return ) ) {
			$return->add_data(
				[
					'headers' => array_merge(
						$this->requires_nonce( $request ) ? $this->get_nonce_headers() : [],
						$this->has_rate_limit( $request ) ? $this->get_rate_limit_headers() : []
					),
				]
			);
		}

		return $return;
	}

	/**
	 * Get list of rate limit headers.
	 *
	 * @return array
	 */
	protected function get_rate_limit_headers() {
		$rate_limit = $this->get_rate_limit();
		$headers    = [
			'X-RateLimit-Limit'     => $rate_limit->limit,
			'X-RateLimit-Remaining' => $rate_limit->remaining,
			'X-RateLimit-Reset'     => $rate_limit->reset,
		];

		if ( $this->is_rate_limit_exceeded() ) {
			$headers['Retry-After'] = $rate_limit->reset - time();
		}

		return $headers;
	}

	/**
	 * Check the current rate limits and return an error if exceeded..
	 *
	 * @return \WP_Error|boolean
	 */
	protected function check_rate_limit() {
		/**
		 * Filters the Store API rate limit check.
		 *
		 * This can be used to disable the rate limit check when testing API endpoints via a REST API client.
		 *
		 * @param boolean $disable_rate_limit_check If true, checks will be disabled.
		 * @return boolean
		 */
		if ( apply_filters( 'woocommerce_store_api_disable_rate_limit_check', false ) ) {
			return true;
		}

		if ( $this->is_rate_limit_exceeded() ) {
			$rate_limit = $this->get_rate_limit();

			return $this->get_route_error_response(
				'woocommerce_store_api_rate_limit_exceeded',
				sprintf(
					'Too many requests. Please wait %d seconds before trying again.',
					$rate_limit->reset - time()
				),
				429
			);
		}

		return true;
	}

	/**
	 * Get current rate limits.
	 *
	 * @return object
	 */
	protected function get_rate_limit() {
		$session = (array) wc()->session->get( $this->rate_limit_id . '-rate-limit', [] );

		return (object) [
			'limit'     => $this->rate_limit_limit,
			'remaining' => $session['remaining'] ?? $this->rate_limit_limit,
			'reset'     => $session['reset'] ?? time() + $this->rate_limit_reset,
		];
	}

	/**
	 * Check if rate limit was exceeded.
	 *
	 * @return boolean
	 */
	protected function is_rate_limit_exceeded() {
		$rate_limit = $this->get_rate_limit();

		return ! $rate_limit->remaining && time() < $rate_limit->reset;
	}

	/**
	 * Update session rate limit after successful response.
	 */
	public function update_rate_limit() {
		$rate_limit = $this->get_rate_limit();
		$expired    = time() >= $rate_limit->reset;

		wc()->session->set(
			$this->rate_limit_id . '-rate-limit',
			[
				'remaining' => $expired ? $rate_limit->limit - 1 : max( 0, $rate_limit->remaining - 1 ),
				'reset'     => $expired ? time() + $this->rate_limit_reset : $rate_limit->reset,
			]
		);
	}
}
