<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Pardot_WordPress
 * @subpackage Pardot_WordPress/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Pardot_WordPress
 * @subpackage Pardot_WordPress/includes
 * @author     Your Name <email@example.com>
 */
if ( ! class_exists( 'Pardot_WordPress' ) ) :
class Pardot_WordPress {

	/**
	 * Plugin version, used for cache-busting of style and script file references.
	 *
	 * @since   1.5
	 *
	 * @const   string
	 */
	const VERSION = '1.5';

	/**
	 * Instance of this class.
	 *
	 * @since    1.5
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.5
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'pardot_register_shortcodes' ) );
		add_action( 'wp_footer', array( $this, 'pardot_enqueue_dc_script' ) );

	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     1.5
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Get the Pardot API key
	 *
	 * @since     1.5
	 *
	 * @params    boolean   $expired    Set expired to true if we know for sure we want to ping the API
	 *
	 * @return    string    API key
	 */
	public function pardot_get_api_key( $expired = false ) {

		// If proper things aren't defined, exit
		if ( !defined('PARDOT_API_EMAIL') || !defined('PARDOT_API_PASSWORD') || !defined('PARDOT_API_USER_KEY')  ) {

			return;

		}

		// See if API key is already stored
		$api_key = get_transient( 'pardot_api_key' );

		// If key is expired, get a new one
		if ( $expired || !$api_key ) {

			// Set default API version to 3 unless we receive a version response: http://developer.pardot.com/kb/api-version-4/
			$api_version = 3;

			// Set up API call with Version 3 as default (the response will tell us if we need version 4)
			$login_url = 'https://pi.pardot.com/api/login/version/' . $api_version;

			$args = array(
				'timeout' => 30,
				'body' => array(
					'email' => PARDOT_API_EMAIL,
					'password' => PARDOT_API_PASSWORD,
					'user_key' => PARDOT_API_USER_KEY,
					'format' => 'json'
				)
			);

			$api_response = wp_remote_post($login_url, $args);

			if ( is_wp_error( $api_response ) ) {

				if ( WP_DEBUG ) {
					$error_message = $api_response->get_error_message();
					echo 'Pardot API Error:' . $error_message;
				}

			} else {

				$api_response_body = wp_remote_retrieve_body( $api_response );

				if ( $api_response_body ) {

					$api_response_body = json_decode( $api_response_body );

					// Grab API key
					$api_key = $api_response_body->{'api_key'};

					if ( isset($api_response_body->{'version'}) ) {
						$api_version = $api_response_body->{'version'};
					}

				}

				// Set transient to store API key and version
				$array_to_store = array(
					'api_key' => $api_key,
					'version' => $api_version
				);

				set_transient( 'pardot_api_key', $array_to_store, HOUR_IN_SECONDS );

			}

		} else {

			$api_key = $api_key['api_key'];

		}

		return $api_key;

	}

	/**
	 * Get the Pardot API version
	 *
	 * @since     1.5
	 *
	 * @return    integer    API version
	 */
	public function pardot_get_api_version() {

		// Default to version 4
		$version = 4;

		// See if API key is already stored
		$api_key = get_transient( 'pardot_api_key' );

		// If not, grab it
		if ( !$api_key ) {

			$this->pardot_get_api_key();
			$api_key = get_transient( 'pardot_api_key' );

		}

		// Set it if the version is there
		if ( $api_key['version'] ) {
			$version = $api_key['version'];
		}

		return $version;

	}

	/**
	 * Get Pardot campaigns
	 *
	 * @since     1.5
	 *
	 * @return    array    Total campaigns and array of campaigns
	 */
	public function pardot_get_campaigns() {

		// Set up initial empty array
		$campaigns = array();

		// If key is expired, get a new one
		if ( false === ( $campaigns = get_transient( 'pardot_campaigns' ) ) ) {

			$api_version = $this->pardot_get_api_version();

			// Set up API call
			$campaigns_url = 'https://pi.pardot.com/api/campaign/version/' . $api_version . '/do/query';

			$args = array(
				'timeout' => 30,
				'body' => array(
					'user_key' => PARDOT_API_USER_KEY,
					'api_key' => $this->pardot_get_api_key(),
					'format' => 'json'
				)
			);

			$api_response = wp_remote_post($campaigns_url, $args);

			if ( is_wp_error( $api_response ) ) {

				if ( WP_DEBUG ) {
					$error_message = $api_response->get_error_message();
					echo 'Pardot API Error:' . $error_message;
				}

			} else {

				$api_response_body = wp_remote_retrieve_body( $api_response );

				if ( $api_response_body ) {

					$api_response_body = json_decode( $api_response_body );

					$campaigns = array(
						'total_campaigns' => $api_response_body->{'result'}->{'total_results'},
						'campaigns' => $api_response_body->{'result'}->{'campaign'}
					);

				}

				// Set transient to store API key
				set_transient( 'pardot_campaigns', $campaigns, MONTH_IN_SECONDS );

			}

		} else {

			$campaigns = get_transient( 'pardot_campaigns' );


		}

		return $campaigns;

	}

	/**
	 * Register shortcodes
	 *
	 * @since     1.5
	 *
	 */
	public function pardot_register_shortcodes() {

		if ( !shortcode_exists( 'pardot-form' ) ) {
			add_shortcode( 'pardot-form', array( $this, 'pardot_form_shortcode' ) );
		}

		if ( !shortcode_exists( 'pardot-dynamic-content' ) ) {
			add_shortcode( 'pardot-dynamic-content', array( $this, 'pardot_dynamic_content_shortcode' ) );
		}

	}

	/**
	 * Form shortcode output
	 *
	 * @since     1.5
	 *
	 * @param   array   $atts   Contains shortcode attributes provided by the user
	 * @return  string  Shortcode output
	 *
	 */
	public function pardot_form_shortcode( $atts ) {

		$atts = shortcode_atts( array(
			'id' => '',
			'title' => '',
			'class' => '',
			'width' => '',
			'height' => '',
			'querystring' => ''
		), $atts, 'pardot-form' );

		// Return if we don't have an ID
		if ( empty($atts['id']) ) {

			return;

		}

		// Get the form code or generate it
		$form_html = $this->pardot_generate_form_code($atts);

		// Apply additional parameters
		// Height
		if ( !empty($atts['height']) ) {

			$height = $atts['height'];

			if ( preg_match( '#height="[^"]+"#', $form_html, $matches ) ) {

				$form_html = str_replace( $matches[0], "height=\"{$height}\"", $form_html );

			} else {

				$form_html = str_replace( 'iframe', "iframe height=\"{$height}\"", $form_html );

			}

		}

		// Width
		if ( !empty($atts['width']) ) {

			$width = $atts['width'];

			if ( preg_match( '#width="[^"]+"#', $form_html, $matches ) ) {

				$form_html = str_replace( $matches[0], "width=\"{$width}\"", $form_html );

			} else {

				$form_html = str_replace( 'iframe', "iframe width=\"{$width}\"", $form_html );

			}

		}

		// Class
		if ( !empty($atts['class']) ) {

			$class = $atts['class'];

			$form_html = str_replace( '<iframe', "<iframe class=\"pardotform {$class}\"", $form_html );

		} else {

			$form_html = str_replace( '<iframe', "<iframe class=\"pardotform\"", $form_html );

		}

		// Query string
		if ( !empty($atts['querystring']) ) {

			$form_html = preg_replace( '/src="([^"]+)"/', 'src="$1?' . $atts['querystring'] . '"', $form_html );

		}

		// Apply filters
		$form_html = apply_filters( 'pardot_form_embed_code_' . $atts['id'], $form_html );

		return $form_html;

	}

	/**
	 * Dynamic content shortcode output
	 *
	 * @since     1.5
	 *
	 * @param   array   $atts   Contains shortcode attributes provided by the user
	 * @return  string  Shortcode output
	 *
	 */
	public function pardot_dynamic_content_shortcode( $atts ) {

		$atts = shortcode_atts( array(
			'id' => '',
			'height' => '',
			'width' => '',
			'class' => '',
			'default' => '',
		), $atts, 'pardot-dynamic-content' );

		// Return if we don't have an ID
		if ( empty($atts['id']) ) {

			return;

		}

		// Get the form code or generate it
		$dc_url = $this->pardot_dynamic_content_url($atts);

		$dc_html = '';

		if ( $dc_url ) {

			// Construct URL
			$dc_html = '<div data-dc-url="' . $dc_url . '"';

			// Apply additional parameters
			// Height
			if ( !empty($atts['height']) ) {

				$dc_html .= ' style="height:' . $atts['height'] . ';';

			} else {

				$dc_html .= ' style="height:auto;';

			}

			// Width
			if ( !empty($atts['width']) ) {

				$dc_html .= 'width:' . $atts['width'] . ';"';

			} else {

				$dc_html .= 'width:auto;"';

			}

			// Class
			if ( !empty($atts['class']) ) {

				$dc_html .= ' class="' . $atts['class'] . '"';

			} else {

				$dc_html .= ' class="pardotdc"';

			}

			$dc_html .= '>';

			// Default text (for JS-disabled contexts)
			if ( !empty($atts['default']) ) {

				$dc_html .= $atts['default'];

			}

			$dc_html .= '</div>';

		}

		// Apply filters
		$dc_html = apply_filters( 'pardot_dc_embed_code_' . $atts['id'], $dc_html );

		return $dc_html;

	}

	/**
	 * Generate form embed code
	 *
	 * @since     1.5
	 *
	 * @param   array   $atts   Contains shortcode attributes provided by the user
	 * @return  string  Shortcode output
	 *
	 */
	public function pardot_generate_form_code( $atts = array() ) {

		if ( empty($atts['id']) ) {

			return;

		}

		// Return cached form HTML if we have it (to prevent lookups on every page load)
		if ( false !== ( $form_html = get_transient( 'pardot_form_html_' . $atts['id'] ) ) ) {

			$form_html = get_transient( 'pardot_form_html_' . $atts['id'] );

		} else {

			$api_version = $this->pardot_get_api_version();

			// Set up API call
			$form_url = 'https://pi.pardot.com/api/form/version/' . $api_version . '/do/read/id/' . $atts['id'];

			$args = array(
				'timeout' => 30,
				'body' => array(
					'user_key' => PARDOT_API_USER_KEY,
					'api_key' => $this->pardot_get_api_key(),
					'format' => 'json'
				)
			);

			$api_response = wp_remote_post($form_url, $args);

			if ( is_wp_error( $api_response ) ) {

				if ( WP_DEBUG ) {
					$error_message = $api_response->get_error_message();
					echo 'Pardot API Error:' . $error_message;
				}

			} else {

				$api_response_body = wp_remote_retrieve_body( $api_response );

				if ( $api_response_body ) {

					$api_response_body = json_decode( $api_response_body );

					// If API key is invalid, force a new one and try again
					if ( isset($api_response_body->{'err'}) && $api_response_body->{'err'} === 'Invalid API key or user key' ) {

						$this->pardot_get_api_key(true);

						$api_response = wp_remote_post($form_url, $args);

						if ( is_wp_error( $api_response ) ) {

							if ( WP_DEBUG ) {
								$error_message = $api_response->get_error_message();
								echo 'Pardot API Error:' . $error_message;
							}

						} else {

							$api_response_body = wp_remote_retrieve_body( $api_response );

							if ( $api_response_body ) {

								$api_response_body = json_decode( $api_response_body );

							}

						}

					}

					$form_html = $api_response_body->{'form'}->{'embedCode'};

				}

				// Set transient to store API key
				if ( $form_html ) {
					set_transient( 'pardot_form_html_' . $atts['id'], $form_html, MONTH_IN_SECONDS );
				}

			}

		}

		return $form_html;

	}

	/**
	 * Generate dynamic content script code
	 *
	 * @since     1.5
	 *
	 * @param   array   $atts   Contains shortcode attributes provided by the user
	 * @return  string  Shortcode output
	 *
	 */
	public function pardot_dynamic_content_url( $atts = array() ) {

		if ( empty($atts['id']) ) {

			return;

		}

		// Return cached form HTML if we have it (to prevent lookups on every page load)
		if ( false !== ( $dc_url = get_transient( 'pardot_dynamicContent_html_' . $atts['id'] ) ) ) {

			$dc_url = get_transient( 'pardot_dynamicContent_html_' . $atts['id'] );

		} else {

			$api_version = $this->pardot_get_api_version();

			// Set up API call
			$dc_api_url = 'https://pi.pardot.com/api/dynamicContent/version/' . $api_version . '/do/read/id/' . $atts['id'];

			$args = array(
				'timeout' => 30,
				'body' => array(
					'user_key' => PARDOT_API_USER_KEY,
					'api_key' => $this->pardot_get_api_key(),
					'format' => 'json'
				)
			);

			$api_response = wp_remote_post($dc_api_url, $args);

			if ( is_wp_error( $api_response ) ) {

				if ( WP_DEBUG ) {
					$error_message = $api_response->get_error_message();
					echo 'Pardot API Error:' . $error_message;
				}

			} else {

				$api_response_body = wp_remote_retrieve_body( $api_response );

				if ( $api_response_body ) {

					$api_response_body = json_decode( $api_response_body );

					// If API key is invalid, force a new one and try again
					if ( isset($api_response_body->{'err'}) && $api_response_body->{'err'} === 'Invalid API key or user key' ) {

						$this->pardot_get_api_key(true);

						$api_response = wp_remote_post($dc_api_url, $args);

						if ( is_wp_error( $api_response ) ) {

							if ( WP_DEBUG ) {
								$error_message = $api_response->get_error_message();
								echo 'Pardot API Error:' . $error_message;
							}

						} else {

							$api_response_body = wp_remote_retrieve_body( $api_response );

							if ( $api_response_body ) {

								$api_response_body = json_decode( $api_response_body );

							}

						}

					}

					$dc_url = $api_response_body->{'dynamicContent'}->{'embedUrl'};

				}

				// Set transient to store API key
				if ( $dc_url ) {
					set_transient( 'pardot_dynamicContent_html_' . $atts['id'], $dc_url, MONTH_IN_SECONDS );
				}

			}

		}

		return $dc_url;

	}

	/**
	 * Add script for performant dynamic content when the shortcode is present
	 *
	 * @since     1.5
	 *
	 */
	public function pardot_enqueue_dc_script() {

		// Only enqueue if the shortcode is present
		if ( has_shortcode( get_the_content( get_the_ID() ), 'pardot-dynamic-content' ) ) {
			wp_register_script( 'pddc', plugins_url( 'js/asyncdc.min.js' , dirname(__FILE__) ), array('jquery'), false, true);
			wp_enqueue_script( 'pddc' );
		}

	}

}
endif;