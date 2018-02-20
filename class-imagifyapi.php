<?php
/**
 * WP Imagify API
 *
 * @package WP-Imagify-API
 */

/*
 * Plugin Name: Imagify API
 * Plugin URI: https://github.com/wp-api-libraries/wp-imagify-api
 * Description: Perform API requests to Imagify in WordPress.
 * Author: WP API Libraries
 * Version: 1.0.0
 * Author URI: https://wp-api-libraries.com
 * GitHub Plugin URI: https://github.com/wp-api-libraries/wp-imagify-api
 * GitHub Branch: master
 */

/* Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

if ( ! class_exists( 'WpImagifyBase' ) ) {
	include_once 'wp-api-libraries-base.php';
}

/* Check if class exists. */
if ( class_exists( 'ImagifyAPI' ) ) {
	return;
}

/**
 * Imagify.io API for WordPress.
 */
class ImagifyAPI extends WpImagifyBase {

	/**
	 * The Imagify API endpoint.
	 *
	 * @var string
	 */
	protected $base_uri = 'https://app.imagify.io/api/';

	/**
	 * The Imagify API key.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * HTTP headers. Each http call must fill it (even if it's with an empty array).
	 *
	 * @var array
	 */
	private $headers = array();

	/**
	 * All (default) HTTP headers. They must not be modified once the class is
	 * instanciated, or it will affect any following HTTP calls.
	 *
	 * @var array
	 */
	private $all_headers = array();

	/**
	 * The constructor.
	 *
	 * @param string $api_key The API key.
	 */
	public function __construct( $api_key = null ) {

		if ( null === $api_key ) {
			// Check if the WordPress plugin is activated and the API key is stored in the options.
			if ( defined( 'IMAGIFY_VERSION' ) && function_exists( 'get_imagify_option' ) ) {
				$api_key       = get_imagify_option( 'api_key', false );
				$this->api_key = $api_key ? $api_key : $this->api_key;
			}

			// Check if the API key is defined with the PHP constant (it's ovveride the WordPress plugin option.
			if ( defined( 'IMAGIFY_API_KEY' ) && IMAGIFY_API_KEY ) {
				$this->api_key = IMAGIFY_API_KEY;
			}
		} else {
			$this->api_key = $api_key;
		}

	}

	/**
	 * Set headers.
	 *
	 * @return void
	 */
	protected function set_headers() {
		$this->args['headers'] = array(
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Authorization' => 'token ' . $this->api_key,
		);
	}

	/**
	 * Clear all arguments.
	 *
	 * @return void
	 */
	protected function clear() {
		$this->args = array();
	}

	/**
	 * Helper function for $this->build_request( $route, $args = array(), $method = 'GET' )->fetch();
	 *
	 * @param  string $route  The route to execute the call onto.
	 * @param  array  $args   (Default: array()) Arguments to pass (see build_request).
	 * @param  string $method (Default: 'GET') The method.
	 * @return object         The body of the response.
	 */
	private function run( $route, $args = array(), $method = 'GET' ) {
		return $this->build_request( $route, $args, $method )->fetch();
	}

	/**
	 * Handle the request response and maybe trigger an error.
	 *
	 * @access private
	 * @since  1.6.7
	 * @author Grégory Viguier
	 *
	 * @param  string $response  The request response.
	 * @param  int    $http_code The request HTTP code.
	 * @param  string $error     An error message.
	 * @return object
	 */
	private function handle_response( $response, $http_code, $error = '' ) {
		$response = json_decode( $response );

		if ( 200 !== $http_code && isset( $response->code, $response->detail ) ) {
			return new WP_Error( $http_code, $response->detail );
		}

		if ( 413 === $http_code ) {
			return new WP_Error( $http_code, 'Your image is too big to be uploaded on our server.' );
		}

		if ( 200 !== $http_code ) {
			$error = trim( (string) $error );
			$error = '' !== $error ? ' - ' . htmlentities( $error ) : '';
			return new WP_Error( $http_code, "Unknown error occurred ({$http_code}{$error})" );
		}

		return $response;
	}

	/**
	 * Get your Imagify account infos.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @return object
	 */
	public function get_user() {
		static $user;

		if ( ! isset( $user ) ) {
			$user = $this->run( 'users/me' );
		}

		return $user;
	}

	/**
	 * Create a user on your Imagify account.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @param  array $data All user data.
	 * @return object
	 */
	public function create_user( $data ) {
		$this->headers = array();
		$data          = array_merge(
			$data, array(
				'from_plugin' => true,
				'partner'     => imagify_get_partner(),
			)
		);

		if ( ! $data['partner'] ) {
			unset( $data['partner'] );
		}

		$response = $this->run( 'users', $data, 'POST' );

		if ( ! is_wp_error( $response ) ) {
			imagify_delete_partner();
		}

		return $response;
	}

	/**
	 * Update an existing user on your Imagify account.
	 *
	 * Needs to be tested/updated.
	 *
	 * Interestingly, their plugin doesn't appear to actually ever use this route.
	 * So while I know it exists, there's very little information I can find detailing its usage.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @param  string $data All user data.
	 * @return object
	 */
	public function update_user( $data ) {
		return $this->run( 'users/me', $data, 'PUT' );
	}

	/**
	 * Check your Imagify API key status.
	 *
	 * Includes a really cheap caching (gets deleted across reinstantiation.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @param  string $test_api_key The api key to test.
	 * @return object
	 */
	public function get_status( $test_api_key ) {
		static $status = array();

		if ( isset( $status[ $test_api_key ] ) ) {
			return $status[ $test_api_key ];
		}

		$partner = imagify_get_partner();

		$this->build_request( 'status', array( 'partner' => $partner ) );

		$this->args['headers']['Authorization'] = 'token ' . $test_api_key;

		$status[ $test_api_key ] = $this->fetch();

		return $status[ $test_api_key ];
	}

	/**
	 * Get the Imagify API version.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @return object
	 */
	public function get_api_version() {
		static $api_version;

		if ( ! isset( $api_version ) ) {
			$api_version = $this->run( 'version' );
		}

		return $api_version;
	}

	/**
	 * Get Public Info.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @return object
	 */
	public function get_public_info() {
		return $this->run( 'public-info' );
	}

	// @codingStandardsIgnoreStart

	/**
	 * Optimize an image from its binary content.
	 *
	 * Untested.
	 *
	 * @param  string $image   Image path (eg: /app/public/wp-content/uploads/2018/02/hennifer-lopez.png ).
	 * @param  array  $options (optional) Optimization options:
	 *                         array(
	 *                             'level'     => string ('normal' | 'aggressive' (default) | 'ultra'),
	 *                             'resize'    => array(
	 *                                 'width'   => int,
	 *                                 'height'  => int,
	 *                                 'percent' => int
	 *                             ),
	 *                             'keep_exif' => bool (default: false)
	 *                         ) as an example.
	 * @return array
	 */
	public function upload_image( $image, $options ) {
		if ( ! is_string( $image ) || ! is_file( $image ) ) {
			return (object) array(
				'success' => false,
				'message' => 'Image incorrect!',
			);
		} elseif ( ! is_readable( $image ) ) {
			return (object) array(
				'success' => false,
				'message' => 'Image not readable!',
			);
		}
		$default = array(
			'level'     => 'aggressive',
			'resize'    => array(),
			'keep_exif' => false,
			'timeout'   => 45,
		);
		$options = array_merge( $default, $options );

		$data = array(
			'image' => curl_file_create( $image ), // yea yea I know.
			'data'  => wp_json_encode(
				array(
					'aggressive' => ( 'aggressive' === $options['level'] ) ? true : false,
					'ultra'      => ( 'ultra' === $options['level'] ) ? true : false,
					'resize'     => $options['resize'],
					'keep_exif'  => $options['keep_exif'],
				)
			),
		);
		return $this->curl_request(
			array(
				'post_data' => $data,
				'timeout'   => $options['timeout'],
			)
		);
	}

	/**
	 * Make an HTTP call using curl.
	 *
	 * Expected to only be used for images, otherwise use this->build_request( /.../ )->fetch(); // or this->run( /.../ );
	 *
	 * @param  array $options Optional request options.
	 * @return object
	 */
	private function curl_request( $options = array() ) {
		$url     = '/upload/';
		$default = array(
			'method'    => 'POST',
			'post_data' => null,
		);
		$options = array_merge( $default, $options );

		$this->build_request(); // Set headers.
		try {
			$ch     = curl_init();
			$is_ssl = ( isset( $_SERVER['HTTPS'] ) && ( 'on' === strtolower( $_SERVER['HTTPS'] ) || 1 === intval( $_SERVER['HTTPS'] ) ) ) || ( isset( $_SERVER['SERVER_PORT'] ) && ( 443 === intval( $_SERVER['SERVER_PORT'] ) ) );
			if ( 'POST' === $options['method'] ) {
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $options['post_data'] );
			}
			curl_setopt( $ch, CURLOPT_URL, self::API_ENDPOINT . $url );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'Imagify PHP Class' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $this->args['headers'] );
			curl_setopt( $ch, CURLOPT_TIMEOUT, $options['timeout'] );
			@curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $is_ssl );

			$response  = json_decode( curl_exec( $ch ) );
			$error     = curl_error( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
		} catch ( \Exception $e ) {
			$this->clear();
			return (object) array(
				'success' => false,
				'message' => 'Unknown error occurred',
			);
		}

		if ( 200 !== $http_code && isset( $response->code, $response->detail ) ) {
			$this->clear();
			return $response;
		} elseif ( 200 !== $http_code ) {
			$this->clear();
			return (object) array(
				'success' => false,
				'message' => 'Unknown error occurred',
			);
		}

		$this->clear();
		return $response;
	}

	// @codingStandardsIgnoreEnd

	/**
	 * Optimize an image from its URL.
	 *
	 * Needs to be tested/an update. It is possible that we may need to use curl for this.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @param  string $data All options. Details here: --.                  <-- Thanks imagify.
	 * @return object
	 */
	public function fetch_image( $data ) {
		return $this->run( 'fetch', $data, 'POST' );
	}

	/**
	 * Get prices for plans.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @return object
	 */
	public function get_plans_prices() {
		return $this->run( 'pricing/plan' );
	}

	/**
	 * Get prices for packs (one time).
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @return object
	 */
	public function get_packs_prices() {
		return $this->run( 'pricing/pack' );
	}

	/**
	 * Get all prices (packs & plans included).
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @return object
	 */
	public function get_all_prices() {
		return $this->run( 'pricing/all' );
	}

	/**
	 * Get all prices (packs & plans included).
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @param  string $coupon A coupon code.
	 * @return object
	 */
	public function check_coupon_code( $coupon ) {
		return $this->run( 'coupons/' . $coupon );
	}

	/**
	 * Get information about current discount.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @return object
	 */
	public function check_discount() {
		return $this->run( 'pricing/discount' );
	}
}
