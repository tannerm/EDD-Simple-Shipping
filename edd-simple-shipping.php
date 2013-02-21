<?php
/*
Plugin Name: Easy Digital Downloads - Simple Shipping
Plugin URI: http://easydigitaldownloads.com/extension/simple-shipping
Description: Provides the ability to charge simple shipping fees for physical products in EDD
Version: 1.0
Author: Pippin Williamson
Author URI:  http://pippinsplugins.com
Contributors: mordauk
*/

class EDD_Simple_Shipping {

	private static $instance;

	/**
	 * Get active object instance
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @static
	 * @return object
	 */
	public static function get_instance() {

		if ( ! self::$instance )
			self::$instance = new EDD_Simple_Shipping();

		return self::$instance;
	}

	/**
	 * Class constructor.  Includes constants, includes and init method.
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		define( 'EDD_SIMPLE_SHIPPING_STORE_API_URL', 'https://easydigitaldownloads.com' );
		define( 'EDD_SIMPLE_SHIPPING_PRODUCT_NAME', 'Simple Shipping' );
		define( 'EDD_SIMPLE_SHIPPING_VERSION', '1.0' );

		if( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
			// load our custom updater
			include( dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php' );
		}

		$this->init();

	}


	/**
	 * Run action and filter hooks.
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function init() {

		if( ! class_exists( 'Easy_Digital_Downloads' ) )
			return; // EDD not present

		global $edd_options;

		// internationalization
		add_action( 'init', array( $this, 'textdomain' ) );

		// register our license key settings
		add_filter( 'edd_settings_misc', array( $this, 'license_settings' ), 1 );

		// activate license key on settings save
		add_action( 'admin_init', array( $this, 'activate_license' ) );

		// Add the meta box fields to Download Configuration
		add_action( 'edd_meta_box_fields', array( $this, 'metabox' ), 300 );

		// Add our meta fields to the EDD save routine
		add_filter( 'edd_metabox_fields_save', array( $this, 'meta_fields_save' ) );

		// Apply shipping costs to the checkout
		add_action( 'init', array( $this, 'apply_shipping_fees' ) );

		// auto updater

		// retrieve our license key from the DB
		$edd_simple_shipping_license_key = isset( $edd_options['edd_simple_shipping_license_key'] ) ? trim( $edd_options['edd_simple_shipping_license_key'] ) : '';

		// setup the updater
		$edd_updater = new EDD_SL_Plugin_Updater( EDD_SIMPLE_SHIPPING_STORE_API_URL, __FILE__, array(
				'version' 	=> EDD_SIMPLE_SHIPPING_VERSION, 		// current version number
				'license' 	=> $edd_simple_shipping_license_key, // license key (used get_option above to retrieve from DB)
				'item_name' => EDD_SIMPLE_SHIPPING_PRODUCT_NAME, // name of this plugin
				'author' 	=> 'Pippin Williamson'  // author of this plugin
			)
		);

	}

	public static function textdomain() {

		// Set filter for plugin's languages directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'edd_manual_purchases_lang_directory', $lang_dir );

		// Load the translations
		load_plugin_textdomain( 'edd-simple-shipping', false, $lang_dir );

	}


	public function metabox( $post_id = 0 ) {

		global $edd_options;

		$enabled       = get_post_meta( $post_id, '_edd_enable_shipping', true );
		$display       = $enabled ? '' : 'style="display:none;"';
		$domestic      = get_post_meta( $post_id, '_edd_shipping_domestic', true );
		$international = get_post_meta( $post_id, '_edd_shipping_international', true );
?>
		<div id="edd_simple_shipping">
			<script type="text/javascript">jQuery(document).ready(function($){$('#edd_enable_shipping').on('click',function(){$('#edd_simple_shipping_fields').toggle();});});</script>
			<p><strong><?php _e( 'Shipping Options', 'edd-simple-shipping' ); ?></strong></p>
			<p>
				<label for="edd_enable_shipping">
					<input type="checkbox" name="_edd_enable_shipping" id="edd_enable_shipping" value="1"<?php checked( 1, $enabled ); ?>/>
					<?php printf( __( 'Enable shipping for this %s', 'edd-simple-shipping' ), edd_get_label_singular() ); ?>
				</label>
			</p>
			<table id="edd_simple_shipping_fields" <?php echo $display; ?>>
				<tr>
					<td>
						<label for="edd_shipping_domestic"><?php _e( 'Domestic Rate:', 'edd-simple-shipping' ); ?>&nbsp;</label>
					</td>
					<td>
						<?php if( ! isset( $edd_options['currency_position'] ) || $edd_options['currency_position'] == 'before' ) : ?>
							<span><?php echo edd_currency_filter( '' ); ?></span><input type="number" min="0" step="0.01" class="small-text" value="<?php esc_attr_e( $domestic ); ?>" id="edd_shipping_domestic" name="_edd_shipping_domestic"/>
						<?php else : ?>
							<input type="number" min="0" step="0.01" class="small-text" value="<?php esc_attr_e( $domestic ); ?>" id="edd_shipping_domestic" name="_edd_shipping_domestic"/><?php echo edd_currency_filter( '' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>
						<label for="edd_shipping_international"><?php _e( 'International Rate:', 'edd-simple-shipping' ); ?>&nbsp;</label>
					</td>
					<td>
						<?php if( ! isset( $edd_options['currency_position'] ) || $edd_options['currency_position'] == 'before' ) : ?>
							<span><?php echo edd_currency_filter( '' ); ?></span><input type="number" min="0" step="0.01" class="small-text" value="<?php esc_attr_e( $international ); ?>" id="edd_shipping_international" name="_edd_shipping_international"/>
						<?php else : ?>
							<input type="number" min="0" step="0.01" class="small-text" value="<?php esc_attr_e( $international ); ?>" id="edd_shipping_international" name="_edd_shipping_international"/><?php echo edd_currency_filter( '' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
<?php
	}

	public function meta_fields_save( $fields ) {

		// Tell EDD to save our extra meta fields
		$fields[] = '_edd_enable_shipping';
		$fields[] = '_edd_shipping_domestic';
		$fields[] = '_edd_shipping_international';
		return $fields;

	}


	private function item_has_shipping( $item_id = 0 ) {
		$enabled = get_post_meta( $item_id, '_edd_enable_shipping', true );
		return (bool) apply_filters( 'edd_simple_shipping_item_has_shipping', $enabled, $item_id );
	}


	private function cart_needs_shipping() {
		$cart_contents = edd_get_cart_contents();
		$ret = false;
		if( is_array( $cart_contents ) ) {
			foreach( $cart_contents as $item ) {
				if( $this->item_has_shipping( $item['id'] ) ) {
					$ret = true;
					break;
				}
			}
		}
		return (bool) apply_filters( 'edd_simple_shipping_cart_needs_shipping', $ret );
	}


	private function is_domestic() {
		return true;
	}


	public function calc_total_shipping() {

		if( ! $this->cart_needs_shipping() )
			return 0.00;

		$cart_contents = edd_get_cart_contents();

		if( ! is_array( $cart_contents ) )
			return 0.00;

		$amount = 0.00;

		foreach( $cart_contents as $item ) {
			if( $this->item_has_shipping( $item['id'] ) ) {

				if( $this->is_domestic() ) {

					$amount += (float) get_post_meta( $item['id'], '_edd_shipping_domestic', true );

				} else {

					$amount += (float) get_post_meta( $item['id'], '_edd_shipping_international', true );

				}

			}
		}

		return apply_filters( 'edd_simple_shipping_total', $amount );

	}


	public function apply_shipping_fees() {

		if( ! $this->cart_needs_shipping() )
			return;

		$amount = $this->calc_total_shipping();

		EDD()->fees->add_fee( $amount, __( 'Shipping Costs', 'edd-simple-shipping' ) );

	}


	public static function license_settings( $settings ) {
		$license_settings = array(
			array(
				'id' => 'edd_simple_shipping_license_header',
				'name' => '<strong>' . __( 'Simple Shipping', 'edd-simple-shipping' ) . '</strong>',
				'desc' => '',
				'type' => 'header',
				'size' => 'regular'
			),
			array(
				'id' => 'edd_simple_shipping_license_key',
				'name' => __('License Key', 'edd-simple-shipping'),
				'desc' => __('Enter your license for Simple Shipping to receive automatic upgrades', 'edd-simple-shipping'),
				'type'  => 'license_key',
				'size'  => 'regular',
				'options' => array( 'is_valid_license_option' => 'edd_simple_shipping_license_active' )
			)
		);

		return array_merge( $settings, $license_settings );
	}

	public static function activate_license() {
		global $edd_options;
		if( ! isset( $_POST['edd_settings_misc'] ) )
			return;
		if( ! isset( $_POST['edd_settings_misc']['edd_simple_shipping_license_key'] ) )
			return;

		if( get_option( 'edd_simple_shipping_license_active' ) == 'valid' )
			return;

		$license = sanitize_text_field( $_POST['edd_settings_misc']['edd_simple_shipping_license_key'] );

		// data to send in our API request
		$api_params = array(
			'edd_action'=> 'activate_license',
			'license' 	=> $license,
			'item_name' => urlencode( EDD_SIMPLE_SHIPPING_PRODUCT_NAME ) // the name of our product in EDD
		);

		// Call the custom API.
		$response = wp_remote_get( add_query_arg( $api_params, EDD_SIMPLE_SHIPPING_STORE_API_URL ), array( 'timeout' => 15, 'body' => $api_params, 'sslverify' => false ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		update_option( 'edd_simple_shipping_license_active', $license_data->license );

	}

}


function edd_simple_shipping_load() {
	$edd_simple_shipping = new EDD_Simple_Shipping();
}
add_action( 'plugins_loaded', 'edd_simple_shipping_load' );