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
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WpImagifyBase' ) ) {
	include_once( 'wp-api-libraries-base.php' );
}

/* Check if class exists. */
if ( class_exists( 'ImagifyAPI' ) ){
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
	 */
	public function __construct( $api_key = null ) {

		if( null === $api_key){
			// Check if the WordPress plugin is activated and the API key is stored in the options.
			if ( defined( 'IMAGIFY_VERSION' ) && function_exists( 'get_imagify_option' ) ) {
				$api_key       = get_imagify_option( 'api_key', false );
				$this->api_key = $api_key ? $api_key : $this->api_key;
			}

			// Check if the API key is defined with the PHP constant (it's ovveride the WordPress plugin option.
			if ( defined( 'IMAGIFY_API_KEY' ) && IMAGIFY_API_KEY ) {
				$this->api_key = IMAGIFY_API_KEY;
			}
		}else{
			$this->api_key = $api_key;
		}


	}

	protected function set_headers(){
		$this->args['headers'] = array(
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Authorization' => 'token ' . $this->api_key
		);
	}

	protected function clear(){
		$this->args = array();
	}

	private function run( $route, $args = array(), $method = 'GET' ){
		return $this->build_request( $route, $args, $method )->fetch();
	}

	/**
	 * Make an HTTP call using curl.
	 *
	 * @access private
	 * @since  1.6.5
	 * @since  1.6.7 Use `wp_remote_request()` when possible (when we don't need to send an image).
	 *
	 * @param  string $url  The URL to call.
	 * @param  array  $args The request args.
	 * @return object
	 */
	private function http_call( $url, $args = array() ) {
		$args = array_merge( array(
			'method'    => 'GET',
			'post_data' => null,
			'timeout'   => 45,
		), $args );

		$endpoint = trim( $url, '/' );
		/**
		 * Filter the timeout value for any request to the API.
		 *
		 * @since  1.6.7
		 * @author Grégory Viguier
		 *
		 * @param int    $timeout  Timeout value in seconds.
		 * @param string $endpoint The targetted endpoint. It's basically URI without heading nor trailing slash.
		 */
		$args['timeout'] = apply_filters( 'imagify_api_http_request_timeout', $args['timeout'], $endpoint );

		// We need to send an image: we must use cURL directly.
		if ( isset( $args['post_data']['image'] ) ) {
			return $this->curl_http_call( $url, $args );
		}

		$args = array_merge( array(
			'headers'   => array(),
			'body'      => $args['post_data'],
			'sslverify' => apply_filters( 'https_ssl_verify', false ),
		), $args );

		unset( $args['post_data'] );

		if ( $this->headers ) {
			foreach ( $this->headers as $name => $value ) {
				$value = explode( ':', $value, 2 );
				$value = end( $value );

				$args['headers'][ $name ] = trim( $value );
			}
		}

		// Final form of args is
		// array(
		//   method: $method
		//   body: $args['post_data']
		//   headers: array( key => val )
		//   sslverify: apply_filters( 'https_ssl_verify', false )
		//   timeout: apply_filters( 'imagify_api_http_request_timeout', 45, $endpoint );
		// )

		$response = wp_remote_request( $this->base_uri . $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$response  = wp_remote_retrieve_body( $response );

		return $this->handle_response( $response, $http_code );
	}

	/**
	 * Make an HTTP call using curl.
	 *
	 * @access private
	 * @since  1.6.7
	 * @author Grégory Viguier
	 *
	 * @param  string $url  The URL to call.
	 * @param  array  $args The request arguments.
	 * @return object
	 */
	private function curl_http_call( $url, $args = array() ) {
		// Check if php-curl is enabled.
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			return new WP_Error( 'curl', 'cURL isn\'t installed on the server.' );
		}

		$url = $this->base_uri . $url;

		try {
			$ch = curl_init();

			if ( isset( $args['post_data']['image'] ) && is_string( $args['post_data']['image'] ) && file_exists( $args['post_data']['image'] ) ) {
				$args['post_data']['image'] = curl_file_create( $args['post_data']['image'] );
			}

			if ( 'POST' === $args['method'] ) {
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['post_data'] );
			} elseif ( 'PUT' === $args['method'] ) {
				curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['post_data'] );
			}

			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $this->headers );
			curl_setopt( $ch, CURLOPT_TIMEOUT, $args['timeout'] );
			@curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );

			$response  = curl_exec( $ch );
			$error     = curl_error( $ch );
			$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

			curl_close( $ch );
		} catch ( Exception $e ) {
			$args['headers'] = $this->headers;
			/**
			 * Fires after a failed curl request.
			 *
			 * @since  1.6.9
			 * @author Grégory Viguier
			 *
			 * @param string $url  The requested URL.
			 * @param array  $args The request arguments.
			 * @param object $e    The raised Exception.
			 */
			do_action( 'imagify_curl_http_response', $url, $args, $e );

			return new WP_Error( 'curl', 'Unknown error occurred' );
		} // End try().

		$args['headers'] = $this->headers;

		/**
		 * Fires after a successful curl request.
		 *
		 * @since  1.6.9
		 * @author Grégory Viguier
		 *
		 * @param string $url       The requested URL.
		 * @param array  $args      The request arguments.
		 * @param string $response  The request response.
		 * @param int    $http_code The request HTTP code.
		 * @param string $error     An error message.
		 */
		do_action( 'imagify_curl_http_response', $url, $args, $response, $http_code, $error );

		return $this->handle_response( $response, $http_code, $error );
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
			// $this->headers = $this->all_headers;
      //
			// $user = $this->http_call( 'users/me/', array(
			// 	'timeout' => 10,
			// ) );
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
		$data          = array_merge( $data, array(
			'from_plugin' => true,
			'partner'     => imagify_get_partner(),
		) );

		if ( ! $data['partner'] ) {
			unset( $data['partner'] );
		}

		$response = $this->run( 'users', $data, 'POST' );

		// $response = $this->http_call( 'users/', array(
		// 	'method'    => 'POST',
		// 	'post_data' => $data,
		// ) );

		if ( ! is_wp_error( $response ) ) {
			imagify_delete_partner();
		}

		return $response;
	}

	/**
	 * Update an existing user on your Imagify account.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @param  string $data All user data.
	 * @return object
	 */
	public function update_user( $data ) {

		return $this->run( 'users/me', $data, 'PUT' );

		$this->headers = $this->all_headers;

		return $this->http_call( 'users/me/', array(
			'method'    => 'PUT',
			'post_data' => $data,
			'timeout'   => 10,
		) );
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

		if ( isset( $status[$test_api_key] ) ) {
			return $status[$test_api_key];
		}

		$partner = imagify_get_partner();

		$this->build_request( 'status', array( 'partner' => $partner ) );

		$this->args['headers']['Authorization'] = 'token ' . $test_api_key;

		$status[$test_api_key] = $this->fetch();

		return $status[$test_api_key];
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

	/**
	 * Optimize an image from its binary content.
	 *
	 * Untested.
	 *
	 * @param  string $image   Image path
	 * @param  array  $options (optional) Optimization options
	 *                         array(
	 *                             'level'     => string ('normal' | 'aggressive' (default) | 'ultra'),
	 *                             'resize'    => array(
	 *                                 'width'   => int,
	 *                                 'height'  => int,
	 *                                 'percent' => int
	 *                             ),
	 *                             'keep_exif' => bool (default: false)
	 *                         )
	 * @return array
	 */
	public function upload_image( $data ) {
		if ( !is_string($image) || !is_file($image) ) {
			return (object) array('success' => false, 'message' => 'Image incorrect!');
		} else if ( !is_readable($image) ) {
			return (object) array('success' => false, 'message' => 'Image not readable!');
		}
		$default = array(
			'level'     => 'aggressive',
			'resize'    => array(),
			'keep_exif' => false,
			'timeout'   => 45
		);
		$options = array_merge( $default, $options );

		$data = array(
			'image' => curl_file_create( $image ),
			'data'  => json_encode(
				array(
					'aggressive' => ( 'aggressive' === $options['level'] ) ? true : false,
					'ultra'      => ( 'ultra' === $options['level'] ) ? true : false,
					'resize'     => $options['resize'],
					'keep_exif'  => $options['keep_exif'],
				)
			)
		);
		return $this->curl_request( array(
			'post_data' => $data,
			'timeout'   => $options["timeout"]
		));
	}

	/**
	 * Make an HTTP call using curl.
	 *
	 * @param  string $url       The URL to call
	 * @param  array  $options   Optional request options
	 * @return object
	 */
	private function curl_request( $options = array() ) {
		$url = '/upload/';
		$default = array(
			'method'    => 'POST',
			'post_data' => null
		);
		$options = array_merge( $default, $options );

		$this->build_request(); // Set headers.
		try {
			$ch     = curl_init();
			$is_ssl = ( isset( $_SERVER['HTTPS'] ) && ( 'on' == strtolower( $_SERVER['HTTPS'] ) || '1' == $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) );
			if ( 'POST' === $options['method'] ) {
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $options['post_data'] );
			}
			curl_setopt( $ch, CURLOPT_URL, self::API_ENDPOINT . $url );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'Imagify PHP Class');
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $this->args['headers'] );
			curl_setopt( $ch, CURLOPT_TIMEOUT, $options['timeout'] );
			@curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $is_ssl );

			$response  = json_decode( curl_exec( $ch ) );
			$error     = curl_error( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
		} catch( \Exception $e ) {
			$this->clear();
			return (object) array('success' => false, 'message' => 'Unknown error occurred');
		}

		if ( 200 !== $http_code && isset( $response->code, $response->detail ) ) {
			$this->clear();
			return $response;
		} elseif ( 200 !== $http_code ) {
			$this->clear();
			return (object) array('success' => false, 'message' => 'Unknown error occurred');
		}

		$this->clear();
		return $response;
	}

	/**
	 * Optimize an image from its URL.
	 *
	 * @access public
	 * @since  1.6.5
	 *
	 * @param  string $data All options. Details here: --. 					<-- Thanks imagify.
	 * @return object
	 */
	public function fetch_image( $data ) {
		return $this->run( 'fetch', $data, 'POST' );

		$this->headers = $this->all_headers;

		return $this->http_call( 'fetch/', array(
			'method'    => 'POST',
			'post_data' => wp_json_encode( $data ),
		) );
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
		return $this->run( 'coupons/'.$coupon );
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
