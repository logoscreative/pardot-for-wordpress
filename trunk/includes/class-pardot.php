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
	 * Hold value for not running wp_footer more than once
	 *
	 * @since    1.5
	 *
	 * @var      object
	 */
	public static $alreadyEnqueued = false;

	/**
	 * Hold value for not running admin_menu more than once
	 *
	 * @since    1.5
	 *
	 * @var      object
	 */
	public static $alreadyAdmined = false;

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
		add_action( 'wp_footer', array( $this, 'pardot_enqueue_campaign_tracking_script' ) );
		add_action( 'admin_menu', array( $this, 'pardot_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'pardot_admin_init' ) );
		add_action( 'acf/load_field/name=pardot_campaign_id', array( $this, 'pardot_acf_load_campaign_values' ) );

		// Preparing for ACF 5.8
		add_action( 'acf/init', array( $this, 'pardot_acf_init' ) );

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

	/**
	 * Add campaign tracking code
	 *
	 * @since     1.5
	 *
	 */
	public function pardot_enqueue_campaign_tracking_script() {

		// Make sure the action hasn't run yet
		if ( self::$alreadyEnqueued ) {
			return;
		}

		// Check for an overriding campaign
		$campaign_tracking_id = get_field( 'pardot_campaign_id', get_the_ID() );

		if ( get_field( 'pardot_campaign_id', get_the_ID() ) ) {

			$campaign_tracking_id = get_field( 'pardot_campaign_id', get_the_ID() );
			$campaign_tracking_id = intval($campaign_tracking_id) + 1000;

		} else {

			$selected_options = get_option( 'pardot_settings' );

			// Only run if we have a selected campaign and the action hasn't run yet
			if ( $selected_options && $selected_options['campaign'] ) {

				// Convert campaign ID into trackable ID
				$campaign_tracking_id = intval( $selected_options['campaign'] ) + 1000;

			}

		}

		if ( $campaign_tracking_id ) {

			// Return cached form HTML if we have it (to prevent lookups on every page load)
			if ( false !== ( $campaign_template = get_transient( 'pardot_tracking_code_template' ) ) ) {

				$campaign_template = get_transient( 'pardot_tracking_code_template' );

			} else {

				$api_version = $this->pardot_get_api_version();

				// Set up API call
				$campaign_template_url = 'https://pi.pardot.com/api/account/version/' . $api_version . '/do/read/';

				$args = array(
					'timeout' => 30,
					'body'    => array(
						'user_key' => PARDOT_API_USER_KEY,
						'api_key'  => $this->pardot_get_api_key(),
						'format'   => 'json'
					)
				);

				$api_response = wp_remote_post( $campaign_template_url, $args );

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
						if ( isset( $api_response_body->{'err'} ) && $api_response_body->{'err'} === 'Invalid API key or user key' ) {

							$this->pardot_get_api_key( true );

							$api_response = wp_remote_post( $campaign_template_url, $args );

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

						$campaign_template = $api_response_body->{'account'}->{'tracking_code_template'};

					}

					// Set transient to store API key
					if ( $campaign_template ) {
						set_transient( 'pardot_tracking_code_template', $campaign_template, YEAR_IN_SECONDS );
					}

				}

			}

			$campaign_tracking_code = '<script type="text/javascript">
				<!--
				piCId = \'' . $campaign_tracking_id . '\';
				' . $campaign_template . '
				-->
			</script>';

			echo apply_filters( 'pardot_campaign_tracking_code', $campaign_tracking_code );
			self::$alreadyEnqueued = true;

		}

	}

	/**
	 * Add settings page
	 *
	 * @since     1.5
	 *
	 */
	public function pardot_admin_menu() {

		if ( !self::$alreadyAdmined ) {

			add_options_page(
				'Pardot',
				'Pardot',
				'manage_options',
				'pardot',
				array(
					$this,
					'pardot_settings_page'
				)
			);

			self::$alreadyAdmined = true;

		}

	}

	/**
	 * Admin menu functionality
	 *
	 * @since     1.5
	 *
	 */
	public function pardot_settings_page() {

		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1><form method="post" action="' . admin_url( 'options.php' ) . '">';

		// If proper things aren't defined, exit
		if ( !defined('PARDOT_API_EMAIL') || !defined('PARDOT_API_PASSWORD') || !defined('PARDOT_API_USER_KEY')  ) {

			echo '<p><label for="pardot-constants">Your Pardot username, password, and user key are required to use the plugin. Add these lines to your <code>wp-config.php</code> file.</label></p><textarea rows="3" id="pardot-constants" aria-label="Constants for Setting Username, Password, and User Key" class="large-text code">define( \'PARDOT_API_EMAIL\', \'Your Pardot Email Address\' );
define( \'PARDOT_API_PASSWORD\', \'Your Pardot Password\' );
define( \'PARDOT_API_USER_KEY\', \'Your Pardot User Key\' );</textarea>';

			echo '<p>You can find your User Key in the <a href="https://pi.pardot.com/account/user" target="_blank">"My Profile" section</a> of your Pardot Account Settings.</p>';

			echo '<p>This message will disappear when these have been set.</p>';

		} else {

			settings_fields( 'pardot_settings' );
			do_settings_sections( 'pardot' );
			submit_button( __( 'Save', 'pardot' ) );

		}

		echo '</form></div>';

	}

	/**
	 * Configure the settings page for the Pardot Plugin if we are currently on the settings page.
	 *
	 * @since     1.5
	 *
	 */
	public function pardot_admin_init() {

		global $pagenow;

		// If we are not on the admin page for this plugin, bail.
		if ( (is_admin() && $pagenow === 'options-general.php' && isset( $_GET['page'] ) && $_GET['page'] === 'pardot' ) ) {

			// Add Chosen to Campaign Selector
			wp_enqueue_script(  'chosen', '//cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js', array( 'jquery' ), '1.0' );
			wp_enqueue_style( 'chosen', '//cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css' );

			// Enqueue Chosen JS init
			add_action( 'admin_head', array( $this, 'pardot_admin_head' ), 0 );

		}

		$fields = array(
			'campaign'  => __( 'Default Campaign (for Tracking Code)', 'pardot' ),
			'https'     => __( 'Force HTTPS', 'pardot' )
		);


		register_setting( 'pardot_settings', 'pardot_settings' );

		// Add the settings sections required by WordPress Settings API.
		add_settings_section( 'pardot_settings', __( 'Global Settings', 'pardot' ), '__return_false' , 'pardot' );

		// Add the setting fields required by WordPress Settings API.
		foreach( $fields as $name => $label ) {
			add_settings_field( $name, $label, array( $this, 'pardot_' . $name . '_field' ), 'pardot', 'pardot_settings' );
		}

	}

	/**
	 * Insert CSS for Pardot Setting page into the seeting page's HTML <head>.
	 *
	 * Called with a priority of zero (0) this can be overridden with other CSS.
	 * We chose to incldue this in the head rather than in a CSS file to mininize
	 * the performance impact of the plugin; loading extra files via HTTP is one
	 * of the biggest performance drains there is.
	 *
	 * @since 1.5
	 */
	function pardot_admin_head() {

		$html = '<script>jQuery(document).ready(function(){jQuery("#campaign").chosen();});</script>';

		echo $html;

	}

	/**
	 * Return an individual Pardot plugin settings
	 *
	 * @static
	 * @param string $key Identifies a setting
	 * @return mixed|null Value of the setting
	 *
	 * @since 1.5
	 */
	public function pardot_get_setting( $key ) {

		$settings = get_option( 'pardot_settings' );

		if ( empty( $settings ) ) {

			/**
			 * If it's empty, make sure it's an empty array.
			 */
			$settings = array();

		}

		$value = null;

		if ( isset( $settings[ $key ] ) ) {
			$value = $settings[ $key ];
		}

		return apply_filters( 'pardot_get_setting', $value, $key );

	}

	/**
	 * Displays the Campaign drop-down field for the Settings API
	 *
	 * @since 1.5
	 */
	public function pardot_campaign_field() {

		$campaigns = $this->pardot_get_campaigns();

		if ( $campaigns ) {

			$html_name = 'pardot_settings[campaign]';
			$html = array();
			$html[] = '<div id="campaign-wrap"><select id="campaign" name="' . $html_name . '">';

			$selected_value = $this->pardot_get_setting( 'campaign' );

			foreach ( $campaigns as $campaign => $data ) {

				$campaign_id = esc_attr( $campaign );
				$selected = selected( $selected_value, $campaign_id, false );

				// A fallback in the rare case of a malformed/empty stdClass of campaign data.
				$campaign_name = __( 'Campaign ID: ' . $campaign_id, 'pardot' );

				if ( isset( $data->name ) && is_string( $data->name ) ) {
					$campaign_name = esc_html( $data->name );
				}

				$html[] = '<option ' . $selected . ' value="' . $campaign_id . '">' . $campaign_name . '</option>';
			}

			$html[] = '</select></div>';

			echo implode( '', $html );

		}
	}

	/**
	 * Displays the Campaign drop-down field for the Settings API
	 *
	 * @since 1.5
	 */
	public function pardot_https_field() {

		$https = $this->pardot_get_setting( 'https' );

		if ( $https ) {

			$https = 'checked';

		}

		$html_name = 'pardot_settings[https]';

		$html = '<input type="checkbox" id="https" name="' . $html_name . '" ' . $https . ' />';

		echo $html;

	}

	public function pardot_acf_load_campaign_values( $field ) {

		$field['choices'] = array();

		$campaigns = $this->pardot_get_campaigns();

		if ( $campaigns ) {

			foreach ( $campaigns as $campaign => $data ) {

				$campaign_id = esc_attr( $campaign );

				// A fallback in the rare case of a malformed/empty stdClass of campaign data.
				$campaign_name = __( 'Campaign ID: ' . $campaign_id, 'pardot' );

				if ( isset( $data->name ) && is_string( $data->name ) ) {
					$campaign_name = esc_html( $data->name );
				}

				$field['choices'][ $campaign_id ] = $campaign_name;

			}

		}

		return $field;

	}

	public function pardot_acf_init() {

		// check function exists
		if ( function_exists('acf_register_block') ) {

			// register a testimonial block
			acf_register_block(array(
				'name'			=> 'pardot-form',
				'title'			=> 'Pardot Form',
				'render_callback'	=> array( $this, 'pardot_form_render_callback'),
				'category'		=> 'embed',
				'icon'			=> 'book-alt',
				'keywords'		=> array( 'pardot', 'form' )
			));

		}

	}

	public function pardot_form_render_callback( $block, $content = '', $is_preview = false ) {

		echo '<h1>Sup fam</h1>';

	}

}
endif;