/**
* WP Imagify API
*
* @package WP-Imagify-API
*/
/*
* Plugin Name: WP Imagify API
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

/* Check if class exists. */
if ( ! class_exists( 'ImagifyAPI' ) {

	/**
 	* Imagify API Class.
 	*/
	class ImagifyAPI {
  
  
   /**
     * The Imagify API endpoint
     */
    const API_ENDPOINT = 'https://app.imagify.io/api/';

    /**
     * The Imagify API key
     */
    private $apiKey = '';

	/**
     * HTTP headers
     */
    private $headers = array();

    /**
	 * @var The single instance of the class
	 */
	protected static $_instance = null;

    /**
     * The constructor
     *
     * @return void
     **/
    public function __construct()
    {
		// check if the WordPress plugin is activated and the API key is stored in the options
		if ( defined( 'IMAGIFY_VERSION' ) && function_exists( 'get_imagify_option' ) ) {
	        $apiKey 	  = get_imagify_option( 'api_key', false );
	        $this->apiKey = ( $apiKey ) ? $apiKey : $this->apiKey;
        }

		// check if the API key is defined with the PHP constant (it's ovveride the WordPress plugin option
        if ( defined( 'IMAGIFY_API_KEY' ) && IMAGIFY_API_KEY ) {
	        $this->apiKey = IMAGIFY_API_KEY;
        }

        $this->headers['Accept']        = 'Accept: application/json';
        $this->headers['Content-Type']  = 'Content-Type: application/json';
        $this->headers['Authorization'] = 'Authorization: token ' . $this->apiKey;
    }

    /**
	 * Main Imagify Instance
	 *
	 * Ensures only one instance of class is loaded or can be loaded.
	 *
	 * @static
	 * @return Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

    /**
     * Create a user on your Imagify account.
     *
     * @param  array $data All user data. Details here: --
     * @return object
     **/
    public function createUser( $data ) {
	    unset( $this->headers['Authorization'], $this->headers['Accept'], $this->headers['Content-Type'] );

		$data['from_plugin'] = true;
		$args = array(
			'method'    => 'POST',
			'post_data' => $data
		);
		
        return $this->httpCall( 'users/', $args );
    }

	/**
     * Get your Imagify account infos.
     *
     * @return object
     **/
    public function getUser() {
		static $user;

        if ( ! isset( $user ) ) {
            $user = $this->httpCall( 'users/me/', array( 'timeout' => 10 ) );
        }

        return $user;
    }

    /**
     * Check your Imagify API key status.
     *
     * @return object
     **/
    public function getStatus( $data ) {
	    static $status;

	    if ( ! isset( $status ) ) {
			unset( $this->headers['Accept'], $this->headers['Content-Type'] );
	        $this->headers['Authorization'] = 'Authorization: token ' . $data;

	        $status = $this->httpCall( 'status/', array( 'timeout' => 10 ) );
	    }

	    return $status;
    }

    /**
     * Get the Imagify API version.
     *
     * @return object
     **/
    public function getApiVersion() {
	    static $api_version;

	    if ( ! isset( $api_version ) ) {
            unset( $this->headers['Accept'], $this->headers['Content-Type'] );

            $api_version = $this->httpCall( 'version/', array( 'timeout' => 5 ) );
        }

	    return $api_version;
    }

    /**
     * Update an existing user on your Imagify account.
     *
     * @param  string $data All user data. Details here: --
     * @return object
     **/
    public function updateUser( $data ) {
        $args = array(
	    	'method'    => 'PUT',
	    	'post_data' => $data,
	    	'timeout'   => 10  
        );
        
        return $this->httpCall( 'users/me/', $args );
    }

    /**
     * Optimize an image from its binary content.
     *
     * @param  string $data All options. Details here: --
     * @return object
     **/
    public function uploadImage( $data ) {
		if ( isset( $this->headers['Accept'], $this->headers['Content-Type'] ) ) {
	        unset( $this->headers['Accept'], $this->headers['Content-Type'] );
        }
		
		$args = array(
			'method'    => 'POST',
			'post_data' => $data
		);
		
		return $this->httpCall( 'upload/', $args );
    }

    /**
     * Optimize an image from its URL.
     *
     * @param  string $data All options. Details here: --
     * @return object
     **/
    public function fetchImage( $data ) {
		$args = array(
			'method'    => 'POST',
			'post_data' => json_encode( $data )
		);
		return $this->httpCall( 'fetch/', $args );
    }

    /**
     * Get prices for plans
     *
     * @return object
     */
    public function getPlansPrices() {
        return $this->httpCall( 'pricing/plan/' );
    }

    /**
     * Get prices for packs (one time)
     *
     * @return object
     */
    public function getPacksPrices() {
        return $this->httpCall( 'pricing/pack/' );
    }

    /**
     * Get Public Info
     *
     * @return object
     */
    public function getPublicInfo() {
        return $this->httpCall( 'public-info' );
    }

	/**
     * Make an HTTP call using curl.
     *
     * @param  string $url  The URL to call
     * @param  array $args  The request args
     * @return object
     **/
    private function httpCall( $url, $args = array() ) {
        $default = array( 
        	'method'    => 'GET', 
        	'post_data' => null, 
        	'timeout'   => 45 
        );
		$args = array_merge( $default, $args );

        // Check if php-curl is enabled
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			return new WP_Error( 'curl', 'cURL isn\'t installed on the server.' );
		}

        try {
	    	$ch = curl_init();

	        if ( 'POST' == $args['method'] ) {
		        curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['post_data'] );
	        }

			curl_setopt( $ch, CURLOPT_URL, self::API_ENDPOINT . $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $this->headers );
			curl_setopt( $ch, CURLOPT_TIMEOUT, $args['timeout'] );
			@curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );

			$response  = json_decode( curl_exec( $ch ) );
	        $error     = curl_error( $ch );
	        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

			curl_close( $ch );
        } catch( Exception $e ) {
	        return new WP_Error( 'curl', 'Unknown error occurred' );
        }

		if ( 200 != $http_code && isset( $response->code, $response->detail ) ) {
			return new WP_Error( $http_code, $response->detail );
		} elseif ( 200 != $http_code ) {
            $http_code = (int) $http_code;
            $error     = '' != $error ? ' - ' . htmlentities( $error ) : '';
			return new WP_Error( $http_code, "Unknown error occurred ({$http_code}{$error}) " );
		} else {
			return $response;
        }

		return $response;
    }

  
  }
