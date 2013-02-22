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

		// Display the shipping address fields
		add_action( 'edd_purchase_form_after_cc_form', array( $this, 'address_fields' ), 100 );

		// Check for errors on checkout submission
		add_action( 'edd_checkout_error_checks', array( $this, 'error_checks' ), 10, 2 );

		// Store shipping info
		add_action( 'edd_purchase_data_before_gateway', array( $this, 'set_shipping_info' ), 10, 2 );

		// Display the user's shipping info in the View Details popup
		add_action( 'edd_payment_personal_details_list', array( $this, 'show_shipping_details' ), 10, 2 );

		// Set payment as not shipped
		add_action( 'edd_insert_payment', array( $this, 'set_as_not_shipped' ), 10, 2 );

		// Add the shipped status column
		add_filter( 'edd_payments_table_columns', array( $this, 'add_shipped_column' ) );

		// Display our Shipped? column value
		add_filter( 'edd_payments_table_column', array( $this, 'display_shipped_column_value' ), 10, 3 );

		// Add our Shipped? checkbox to the edit payment screen
		add_action( 'edd_edit_payment_bottom', array( $this, 'edit_payment_option' ) );

		// Update shipped status when a purchase is edited
		add_action( 'edd_update_edited_purchase', array( $this, 'update_edited_purchase' ) );

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


	private function needs_shipping_fields() {

		return $this->cart_needs_shipping();

	}

	private function has_billing_fields() {

		// Have to assume all gateways are using the default CC fields (they should be)
		return did_action( 'edd_after_cc_fields', 'edd_default_cc_address_fields' );

	}


	private function hide_shipping_fields() {

		return true;
	}


	public function address_fields() {

		if( ! $this->needs_shipping_fields() )
			return;

		$display = $this->hide_shipping_fields() && $this->has_billing_fields() ? ' style="display:none;"' : '';

		ob_start();
?>
		<script type="text/javascript">jQuery(document).ready(function($){
			$('body').change('select[name=shipping_country]',function(){
				if($('select[name=shipping_country]').val()=='US'){$('#shipping_state_other').css('display','none');$('#shipping_state_us').css('display','');$('#shipping_state_ca').css('display','none'); }else if( $('select[name=shipping_country]').val()=='CA'){$('#shipping_state_other').css('display','none');$('#shipping_state_us').css('display','none');$('#shipping_state_ca').css('display','');}else{$('#shipping_state_other').css('display','');$('#shipping_state_us').css('display','none');$('#shipping_state_ca').css('display','none');}
			});
			$('#edd_simple_shipping_show').change(function(){
				$('#edd_simple_shipping_fields_wrap').toggle();
			});
		});</script>


		<fieldset id="edd_simple_shipping">
			<?php if( $this->has_billing_fields() ) : ?>
			<label for="edd_simple_shipping_show">
				<input type="checkbox" id="edd_simple_shipping_show" name="edd_use_different_shipping" value="1"/>
				<?php _e( 'Ship to Different Address?', 'edd-simple-shipping' ); ?>
			</label>
			<?php endif; ?>
			<div id="edd_simple_shipping_fields_wrap"<?php echo $display; ?>>
				<?php do_action( 'edd_shipping_address_top' ); ?>
				<legend><?php _e( 'Shipping Details', 'edd-simple-shipping' ); ?></legend>
				<p id="edd-shipping-address-wrap">
					<input type="text" name="shipping_address" class="shipping-address edd-input" placeholder="<?php _e( 'Address line 1', 'edd' ); ?>"/>
					<label class="edd-label"><?php _e( 'Shipping Address', 'edd' ); ?></label>
				</p>
				<p id="edd-shipping-address-2-wrap">
					<input type="text" name="shipping_address_2" class="shipping-address-2 edd-input" placeholder="<?php _e( 'Address line 2', 'edd' ); ?>"/>
					<label class="edd-label"><?php _e( 'Shipping Address Line 2', 'edd' ); ?></label>
				</p>
				<p id="edd-shipping-city-wrap">
					<input type="text" name="shipping_city" class="shipping-city edd-input" placeholder="<?php _e( 'City', 'edd' ); ?>"/>
					<label class="edd-label"><?php _e( 'Shipping City', 'edd' ); ?></label>
				</p>
				<p id="edd-shipping-country-wrap">
					<select name="shipping_country" class="shipping-country edd-select">
						<?php
						$countries = edd_get_country_list();
						foreach( $countries as $country_code => $country ) {
						  echo '<option value="' . $country_code . '">' . $country . '</option>';
						}
						?>
					</select>
					<label class="edd-label"><?php _e( 'Shipping Country', 'edd' ); ?></label>
				</p>
				<p id="edd-shipping-state-wrap">
					<input type="text" size="6" name="shipping_state_other" id="shipping_state_other" class="shipping-state edd-input" placeholder="<?php _e( 'State / Province', 'edd' ); ?>" style="display:none;"/>
		            <select name="shipping_state_us" id="shipping_state_us" class="shipping-state edd-select">
		                <?php
		                    $states = edd_get_states_list();
		                    foreach( $states as $state_code => $state ) {
		                        echo '<option value="' . $state_code . '">' . $state . '</option>';
		                    }
		                ?>
		            </select>
		            <select name="shipping_state_ca" id="shipping_state_ca" class="shipping-state edd-select" style="display: none;">
		                <?php
		                    $provinces = edd_get_provinces_list();
		                    foreach( $provinces as $province_code => $province ) {
		                        echo '<option value="' . $province_code . '">' . $province . '</option>';
		                    }
		                ?>
		            </select>
					<label class="edd-label"><?php _e( 'Shipping State / Province', 'edd' ); ?></label>
				</p>
				<p id="edd-shipping-zip-wrap">
					<input type="text" size="4" name="shipping_zip" class="shipping-zip edd-input" placeholder="<?php _e( 'Zip / Postal code', 'edd' ); ?>"/>
					<label class="edd-label"><?php _e( 'Shipping Zip / Postal Code', 'edd' ); ?></label>
				</p>
				<?php do_action( 'edd_shipping_address_bottom' ); ?>
			</div>
		</fieldset>
<?php 	echo ob_get_clean();
	}


	public function error_checks( $valid_data, $post_data ) {

		// Only perform error checks if we have a product that needs shipping
		if( ! $this->cart_needs_shipping() )
			return;

		// Check to see if shipping is different than billing
		if( isset( $post_data['edd_use_different_shipping'] ) || ! $this->has_billing_fields() ) {

			// Shipping address is different

			if( empty( $post_data['shipping_address'] ) )
				edd_set_error( 'missing_address', __( 'Please enter a shipping address', 'edd-simple-shipping' ) );

			if( empty( $post_data['shipping_city'] ) )
				edd_set_error( 'missing_city', __( 'Please enter a city for shipping', 'edd-simple-shipping' ) );

			if( empty( $post_data['shipping_zip'] ) )
				edd_set_error( 'missing_zip', __( 'Please enter a zip/postal code for shipping', 'edd-simple-shipping' ) );

		} else {

			// Shipping address is the same as billing
			if( empty( $post_data['billing_address'] ) )
				edd_set_error( 'missing_address', __( 'Please enter a shipping address', 'edd-simple-shipping' ) );

			if( empty( $post_data['billing_city'] ) )
				edd_set_error( 'missing_city', __( 'Please enter a city for shipping', 'edd-simple-shipping' ) );

			if( empty( $post_data['billing_zip'] ) )
				edd_set_error( 'missing_zip', __( 'Please enter a zip/postal code for shipping', 'edd-simple-shipping' ) );

		}

	}


	// Add shipping info to the user_info
	public function set_shipping_info( $purchase_data, $valid_data ) {

		if( ! $this->cart_needs_shipping() )
			return $purchase_data;

		$shipping_info = array();

		// Check to see if shipping is different than billing
		if( isset( $_POST['edd_use_different_shipping'] ) || ! $this->has_billing_fields() ) {

			$shipping_info['address']  = sanitize_text_field( $_POST['shipping_address'] );
			$shipping_info['address2'] = sanitize_text_field( $_POST['shipping_address_2'] );
			$shipping_info['city']     = sanitize_text_field( $_POST['shipping_city'] );
			$shipping_info['zip']      = sanitize_text_field( $_POST['shipping_zip'] );
			$shipping_info['country']  = sanitize_text_field( $_POST['shipping_country'] );

			// Shipping address is different
			switch ( $_POST['shipping_country'] ) :
				case 'US' :
					$shipping_info['state'] = isset( $_POST['shipping_state_us'] )	 ? sanitize_text_field( $_POST['shipping_state_us'] ) 	 : '';
					break;
				case 'CA' :
					$shipping_info['state'] = isset( $_POST['shipping_state_ca'] )	 ? sanitize_text_field( $_POST['shipping_state_ca'] ) 	 : '';
					break;
				default :
					$shipping_info['state'] = isset( $_POST['shipping_state_other'] ) ? sanitize_text_field( $_POST['shipping_state_other'] ) : '';
					break;
			endswitch;

		} else {

			$shipping_info['address']  = sanitize_text_field( $_POST['billing_address'] );
			$shipping_info['address2'] = sanitize_text_field( $_POST['billing_address_2'] );
			$shipping_info['city']     = sanitize_text_field( $_POST['billing_city'] );
			$shipping_info['zip']      = sanitize_text_field( $_POST['billing_zip'] );
			$shipping_info['country']  = sanitize_text_field( $_POST['billing_country'] );

			// Shipping address is different
			switch ( $_POST['billing_country'] ) :
				case 'US' :
					$shipping_info['state'] = isset( $_POST['billing_state_us'] )	 ? sanitize_text_field( $_POST['billing_state_us'] ) 	: '';
					break;
				case 'CA' :
					$shipping_info['state'] = isset( $_POST['billing_state_ca'] )	 ? sanitize_text_field( $_POST['billing_state_ca'] ) 	: '';
					break;
				default :
					$shipping_info['state'] = isset( $_POST['billing_state_other'] ) ? sanitize_text_field( $_POST['billing_state_other'] )  : '';
					break;
			endswitch;

		}

		$purchase_data['user_info']['shipping_info'] = $shipping_info;

		return $purchase_data;

	}


	public function set_as_not_shipped( $payment_id = 0, $payment_data = array() ) {

		$shipping_info = ! empty( $payment_data['user_info']['shipping_info'] ) ? $payment_data['user_info']['shipping_info'] : false;

		if( ! $shipping_info )
			return;

		// Indicate that this purchase needs shipped
		update_post_meta( $payment_id, '_edd_payment_shipping_status', '1' );

	}


	public function show_shipping_details( $payment_meta = array(), $user_info = array() ) {

		$shipping_info = ! empty( $user_info['shipping_info'] ) ? $user_info['shipping_info'] : false;

		if( ! $shipping_info )
			return;

		$countries = edd_get_country_list();

		echo '<li><strong>' . __( 'Shipping Info', 'edd-simple-shipping' ) . '</strong></li>';
		echo '<li>' . $shipping_info['address'] . '</li>';
		if( ! empty( $shipping_info['address2'] ) )
			echo '<li>' . $shipping_info['address2'] . '</li>';
		echo '<li>' . $shipping_info['city'] . ', ' . $shipping_info['state'] . ' ' . $shipping_info['zip'] . '</li>';
		echo '<li>' . $countries[ $shipping_info['country'] ] . '</li>';

	}


	public function add_shipped_column( $columns ) {
		// Force the Shipped column to be placed just before Status
		unset( $columns['status'] );
		$columns['shipped'] = __( 'Shipped?', 'edd-simple-shipping' );
		$columns['status']  = __( 'Status', 'edd' );
		return $columns;
	}

	public function display_shipped_column_value( $value = '', $payment_id = 0, $column_name = '' ) {

		if( $column_name == 'shipped' ) {
			$shipping_status = get_post_meta( $payment_id, '_edd_payment_shipping_status', true );
			if( $shipping_status == '1' ) {
				$value = __( 'No', 'edd-simple-shipping' );
			} elseif( $shipping_status == '2' ) {
				$value = __( 'Yes', 'edd-simple-shipping' );
			} else {
				$value = __( 'N/A', 'edd-simple-shipping' );
			}
		}
		return $value;
	}

	public function edit_payment_option( $payment_id = 0 ) {

		$status  = get_post_meta( $payment_id, '_edd_payment_shipping_status', true );
		if( ! $status )
			return;

		$shipped = $status == '2' ? true : false;
?>
	<tr>
		<th scope="row" valign="top">
			<span><?php _e( 'Shipped?', 'edd' ); ?></span>
		</th>
		<td>
			<input type="checkbox" name="edd-payment-shipped" value="1"<?php checked( $shipped, true ); ?>/>
			<span class="description"><?php _e( 'Check if this purchase has been shipped.', 'edd-simple-shipping' ); ?></span>
		</td>
	</tr>
<?php
	}


	/**
	 * Update Edited Purchase
	 *
	 * Updates the shipping status of a purchase to indicate it has been shipped
	 *
	 * @access      private
	 * @since       1.0
	 * @return      void
	 */
	public function update_edited_purchase( $payment_id = 0 ) {

		$status  = get_post_meta( $payment_id, '_edd_payment_shipping_status', true );
		if( ! $status || $status == '2' )
			return;

		if( isset( $_POST['edd-payment-shipped'] ) )
			update_post_meta( $payment_id, '_edd_payment_shipping_status', '2' );

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