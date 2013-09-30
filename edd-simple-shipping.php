<?php
/*
Plugin Name: Easy Digital Downloads - Simple Shipping
Plugin URI: http://easydigitaldownloads.com/extension/simple-shipping
Description: Provides the ability to charge simple shipping fees for physical products in EDD
Version: 1.4
Author: Pippin Williamson
Author URI:  http://pippinsplugins.com
Contributors: mordauk
*/

class EDD_Simple_Shipping {

	private static $instance;


	/**
	 * Flag for domestic / international shipping
	 *
	 * @since 1.0
	 *
	 * @access private
	 */
	private $is_domestic = true;

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

		define( 'EDD_SIMPLE_SHIPPING_VERSION', '1.4' );

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
		add_filter( 'edd_settings_general', array( $this, 'settings' ), 1 );

		// Add the meta box fields to Download Configuration
		add_action( 'edd_meta_box_fields', array( $this, 'metabox' ), 10 );

		// Add our variable pricing shipping header
		add_action( 'edd_download_price_table_head', array( $this, 'price_header' ), 10 );

		// Add our variable pricing shipping options
		add_action( 'edd_download_price_table_row', array( $this, 'price_row' ), 20, 3 );

		// Add our meta fields to the EDD save routine
		add_filter( 'edd_metabox_fields_save', array( $this, 'meta_fields_save' ) );

		// Check shipping rate when billing/shipping fields are changed
		add_action( 'wp_ajax_edd_get_shipping_rate', array( $this, 'ajax_shipping_rate' ) );
		add_action( 'wp_ajax_nopriv_edd_get_shipping_rate', array( $this, 'ajax_shipping_rate' ) );

		// Apply shipping costs to the checkout
		add_action( 'init', array( $this, 'apply_shipping_fees' ) );

		// Display the shipping address fields
		add_action( 'edd_purchase_form_after_cc_form', array( $this, 'address_fields' ), 999 );

		// Check for errors on checkout submission
		add_action( 'edd_checkout_error_checks', array( $this, 'error_checks' ), 10, 2 );

		// Store shipping info
		add_filter( 'edd_purchase_data_before_gateway', array( $this, 'set_shipping_info' ), 10, 2 );

		// Send shipping info to PayPal
		add_filter( 'edd_paypal_redirect_args', array( $this, 'send_shipping_to_paypal' ), 10, 2 );

		// Display the user's shipping info in the View Details popup
		add_action( 'edd_payment_personal_details_list', array( $this, 'show_shipping_details' ), 10, 2 );

		// Set payment as not shipped
		add_action( 'edd_insert_payment', array( $this, 'set_as_not_shipped' ), 10, 2 );

		// Add the shipped status column
		add_filter( 'edd_payments_table_columns', array( $this, 'add_shipped_column' ) );

		// Make our Shipped? column sortable
		add_filter( 'edd_payments_table_sortable_columns', array( $this, 'add_sortable_column' ) );

		// Sort the payment history by orders that have been shipped or not
		add_filter( 'edd_get_payments_args', array( $this, 'sort_payments' ) );

		// Display our Shipped? column value
		add_filter( 'edd_payments_table_column', array( $this, 'display_shipped_column_value' ), 10, 3 );

		// Add our Shipped? checkbox to the edit payment screen
		add_action( 'edd_edit_payment_bottom', array( $this, 'edit_payment_option' ) );

		// Update shipped status when a purchase is edited
		add_action( 'edd_update_edited_purchase', array( $this, 'update_edited_purchase' ) );

		// Modify the admin sales notice
		add_filter( 'edd_admin_purchase_notification', array( $this, 'admin_sales_notice' ), 10, 3 );

		// Add a new box to the export screen
		add_action( 'edd_reports_tab_export_content_bottom', array( $this, 'show_export_options' ) );

		add_action( 'edd_unshipped_orders_export', array( $this, 'do_export' ) );

		// auto updater
		if( is_admin() ) {

			if( ! class_exists( 'EDD_License' ) ) {
				include( dirname( __FILE__ ) . '/EDD_License_Handler.php' );
			}
			$license = new EDD_License( __FILE__, 'Simple Shipping', EDD_SIMPLE_SHIPPING_VERSION, 'Pippin Williamson', 'edd_simple_shipping_license_key' );
		}
	}


	/**
	 * Load plugin text domain
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	public static function textdomain() {

		// Set filter for plugin's languages directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'edd_manual_purchases_lang_directory', $lang_dir );

		// Load the translations
		load_plugin_textdomain( 'edd-simple-shipping', false, $lang_dir );

	}


	/**
	 * Render the extra meta box fields
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	public function metabox( $post_id = 0 ) {

		global $edd_options;

		$enabled       = get_post_meta( $post_id, '_edd_enable_shipping', true );
		$display       = $enabled ? '' : 'style="display:none;"';
		$domestic      = get_post_meta( $post_id, '_edd_shipping_domestic', true );
		$international = get_post_meta( $post_id, '_edd_shipping_international', true );
?>
		<div id="edd_simple_shipping">
			<script type="text/javascript">jQuery(document).ready(function($){$('#edd_enable_shipping').on('click',function(){$('#edd_simple_shipping_fields,.edd_prices_shipping').toggle();});});</script>
			<p><strong><?php _e( 'Shipping Options', 'edd-simple-shipping' ); ?></strong></p>
			<p>
				<label for="edd_enable_shipping">
					<input type="checkbox" name="_edd_enable_shipping" id="edd_enable_shipping" value="1"<?php checked( 1, $enabled ); ?>/>
					<?php printf( __( 'Enable shipping for this %s', 'edd-simple-shipping' ), edd_get_label_singular() ); ?>
				</label>
			</p>
			<div id="edd_simple_shipping_fields" <?php echo $display; ?>>
				<table>
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
		</div>
<?php
	}


	/**
	 *Add the table header cell for price shipping
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	function price_header( $post_id = 0 ) {
		$enabled       = get_post_meta( $post_id, '_edd_enable_shipping', true );
		$display       = $enabled ? '' : 'style="display:none;"';
?>
		<th class="edd_prices_shipping"<?php echo $display; ?>><?php _e( 'Shipping', 'edd-simple-shipping' ); ?></th>
<?php
	}


	/**
	 *Add the table cell for price shipping
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	function price_row( $post_id = 0, $price_key = 0, $args = array() ) {
		$enabled       = get_post_meta( $post_id, '_edd_enable_shipping', true );
		$display       = $enabled ? '' : 'style="display:none;"';
		$prices        = edd_get_variable_prices( $post_id );
		$shipping      = isset( $prices[ $price_key ]['shipping'] ) ? $prices[ $price_key ]['shipping'] : '';
?>
		<td class="edd_prices_shipping"<?php echo $display; ?>>
			<label for="edd_variable_prices[<?php echo $price_key; ?>][shipping]">
				<input type="checkbox" value="1"<?php checked( true, $shipping ); ?> id="edd_variable_prices[<?php echo $price_key; ?>][shipping]" name="edd_variable_prices[<?php echo $price_key; ?>][shipping]" style="float:left;width:auto;margin:2px 5px 0 0;"/>
				<span><?php _e( 'Check to enable shipping costs for this price.', 'edd-simple-shipping' ); ?></span>
		</label>
		</td>
<?php
	}


	/**
	 * Save our extra meta box fields
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return array
	 */
	public function meta_fields_save( $fields ) {

		// Tell EDD to save our extra meta fields
		$fields[] = '_edd_enable_shipping';
		$fields[] = '_edd_shipping_domestic';
		$fields[] = '_edd_shipping_international';
		return $fields;

	}


	/**
	 * Determine if a product has snipping enabled
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return bool
	 */
	private function item_has_shipping( $item_id = 0, $price_id = 0 ) {
		$enabled          = get_post_meta( $item_id, '_edd_enable_shipping', true );
		$variable_pricing = edd_has_variable_prices( $item_id );

		if( $variable_pricing && ! $this->price_has_shipping( $item_id, $price_id ) )
			$enabled = false;

		return (bool) apply_filters( 'edd_simple_shipping_item_has_shipping', $enabled, $item_id );
	}


	/**
	 * Determine if a price option has snipping enabled
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return bool
	 */
	private function price_has_shipping( $item_id = 0, $price_id = 0 ) {
		$prices = edd_get_variable_prices( $item_id );
		$ret    = isset( $prices[ $price_id ]['shipping'] );
		return (bool) apply_filters( 'edd_simple_shipping_price_hasa_shipping', $ret, $item_id, $price_id );
	}


	/**
	 * Determine if shipping costs need to be calculated for the cart
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return bool
	 */
	private function cart_needs_shipping() {
		$cart_contents = edd_get_cart_contents();
		$ret = false;
		if( is_array( $cart_contents ) ) {
			foreach( $cart_contents as $item ) {
				$price_id = isset( $item['options']['price_id'] ) ? (int) $item['options']['price_id'] : null;
				if( $this->item_has_shipping( $item['id'], $price_id ) ) {
					$ret = true;
					break;
				}
			}
		}
		return (bool) apply_filters( 'edd_simple_shipping_cart_needs_shipping', $ret );
	}


	/**
	 * Get the base country (where the store is located)
	 *
	 * This is used for determining if customer should be charged domestic or international shipping
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return string
	 */
	private function get_base_region() {

		global $edd_options;

		return isset( $edd_options['edd_simple_shipping_base_country'] ) ? $edd_options['edd_simple_shipping_base_country'] : 'US';

	}


	/**
	 * Calculate the total shipping costs on the cart
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return float
	 */
	public function calc_total_shipping() {

		if( ! $this->cart_needs_shipping() )
			return false;

		$cart_contents = edd_get_cart_contents();

		if( ! is_array( $cart_contents ) )
			return false;

		if( is_user_logged_in() && empty( $_POST['country'] ) ) {
			$address = get_user_meta( get_current_user_id(), '_edd_user_address', true );
			if( isset( $address['country'] ) && $address['country'] != $this->get_base_region() ) {
				$this->is_domestic = false;
			}
		}

		$amount = 0.00;

		foreach( $cart_contents as $item ) {

			$price_id = isset( $item['options']['price_id'] ) ? (int) $item['options']['price_id'] : null;

			if( $this->item_has_shipping( $item['id'], $price_id ) ) {

				if( $this->is_domestic ) {

					$amount += (float) get_post_meta( $item['id'], '_edd_shipping_domestic', true );

				} else {

					$amount += (float) get_post_meta( $item['id'], '_edd_shipping_international', true );

				}

			}
		}

		return apply_filters( 'edd_simple_shipping_total', $amount );

	}


	/**
	 * Update the shipping costs via ajax
	 *
	 * This fires when the customer changes the country they are shipping to
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	public function ajax_shipping_rate() {

		$country = $_POST['country'];
		$total   = edd_get_cart_total();
		$current = EDD()->fees->get_fee( 'simple_shipping' );

		// Get rid of our current shipping
		$total -= $current['amount'];
		EDD()->fees->remove_fee( 'simple_shipping' );

		if( $country != $this->get_base_region() )
			$this->is_domestic = false;

		// Calculate new shipping
		$shipping = $this->calc_total_shipping();

		EDD()->fees->add_fee( $shipping, __( 'Shipping Costs', 'edd-simple-shipping' ), 'simple_shipping' );

		// Add our shipping to the total
		$total += $shipping;

		// Setup out response
		$response = array(
			'shipping_amount' => html_entity_decode( edd_currency_filter( edd_format_amount( $shipping ) ), ENT_COMPAT, 'UTF-8' ),
			'total' => html_entity_decode( edd_currency_filter( edd_format_amount( $total ) ), ENT_COMPAT, 'UTF-8' ),
		);

		echo json_encode( $response );

		die();
	}


	/**
	 * Apply the shipping fees to the cart
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	public function apply_shipping_fees() {

		if( ! $this->cart_needs_shipping() ) {
			EDD()->fees->remove_fee( 'simple_shipping' );
			return;
		}

		$amount = $this->calc_total_shipping();

		if( $amount )
			EDD()->fees->add_fee( $amount, __( 'Shipping Costs', 'edd-simple-shipping' ), 'simple_shipping' );
		else
			EDD()->fees->remove_fee( 'simple_shipping' );

	}


	/**
	 * Determine if the shipping fields should be displayed
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return bool
	 */
	private function needs_shipping_fields() {

		return $this->cart_needs_shipping();

	}


	/**
	 * Determine if the current payment method has billing fields
	 *
	 * If no billing fields are present, the shipping fields are always displayed
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return bool
	 */
	private function has_billing_fields() {

		$did_action = did_action( 'edd_after_cc_fields', 'edd_default_cc_address_fields' );
		if( ! $did_action && edd_use_taxes() )
			$did_action = did_action( 'edd_purchase_form_after_cc_form', 'edd_checkout_tax_fields' );

		// Have to assume all gateways are using the default CC fields (they should be)
		return ( $did_action || isset( $_POST['card_address'] ) );

	}


	/**
	 * Shipping info fields
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function address_fields() {

		if( ! $this->needs_shipping_fields() )
			return;

		$display = $this->has_billing_fields() ? ' style="display:none;"' : '';

		ob_start();
?>
		<script type="text/javascript">var edd_global_vars; jQuery(document).ready(function($){
			$('body').on('change', 'select[name=shipping_country],select[name=billing_country]',function(){

				var billing = true;

				if( $('select[name=billing_country]').length && ! $('#edd_simple_shipping_show').is(':checked') ) {
					var val = $('select[name=billing_country]').val();
				} else {
					var val = $('select[name=shipping_country]').val();
					billing = false;
				}

				if( billing && edd_global_vars.taxes_enabled == 1 )
					return; // EDD core will recalculate on billing address change if taxes are enabled

				if( val =='US') {
					$('#shipping_state_other').hide();$('#shipping_state_us').show();$('#shipping_state_ca').hide();
				} else if(  val =='CA'){
					$('#shipping_state_other').hide();$('#shipping_state_us').hide();$('#shipping_state_ca').show();
				} else {
					$('#shipping_state_other').show();$('#shipping_state_us').hide();$('#shipping_state_ca').hide();
				}
				var postData = {
		            action: 'edd_get_shipping_rate',
		            country:  val
		        };
		        $.ajax({
		            type: "POST",
		            data: postData,
		            dataType: "json",
		            url: edd_global_vars.ajaxurl,
		            success: function (response) {
		                if( response ) {
		                	$('.edd_cart_amount').text( response.total );
		                	$('#edd_cart_fee_simple_shipping .edd_cart_fee_amount').text( response.shipping_amount );
		                } else {
		                    console.log( response );
		                }
		            }
		        }).fail(function (data) {
		            console.log(data);
		        });
			});

			$('body').on('edd_taxes_recalculated', function( event, data ) {

				if( $('#edd_simple_shipping_show').is(':checked') )
					return;

				var postData = {
		            action: 'edd_get_shipping_rate',
		            country: data.postdata.country
		        };
		        $.ajax({
		            type: "POST",
		            data: postData,
		            dataType: "json",
		            url: edd_global_vars.ajaxurl,
		            success: function (response) {
		                if( response ) {
		                	$('.edd_cart_amount').text( response.total );
		                	$('#edd_cart_fee_simple_shipping .edd_cart_fee_amount').text( response.shipping_amount );
		                } else {
		                    console.log( response );
		                }
		            }
		        }).fail(function (data) {
		            console.log(data);
		        });

			});

			$('select#edd-gateway, input.edd-gateway').change( function (e) {
				var postData = {
		            action: 'edd_get_shipping_rate',
		            country: 'US' // default
		        };
		        $.ajax({
		            type: "POST",
		            data: postData,
		            dataType: "json",
		            url: edd_global_vars.ajaxurl,
		            success: function (response) {
		                if( response ) {
		                	$('.edd_cart_amount').text( response.total );
		                	$('#edd_cart_fee_simple_shipping .edd_cart_fee_amount').text( response.shipping_amount );
		                } else {
		                    console.log( response );
		                }
		            }
		        }).fail(function (data) {
		            console.log(data);
		        });
			});
			$('#edd_simple_shipping_show').change(function(){
				$('#edd_simple_shipping_fields_wrap').toggle();
			});
		});</script>

		<div id="edd_simple_shipping">
			<?php if( $this->has_billing_fields() ) : ?>
				<fieldset id="edd_simple_shipping_diff_address">
					<label for="edd_simple_shipping_show">
						<input type="checkbox" id="edd_simple_shipping_show" name="edd_use_different_shipping" value="1"/>
						<?php _e( 'Ship to Different Address?', 'edd-simple-shipping' ); ?>
					</label>
				</fieldset>
			<?php endif; ?>
			<div id="edd_simple_shipping_fields_wrap"<?php echo $display; ?>>
				<fieldset id="edd_simple_shipping_fields">
					<?php do_action( 'edd_shipping_address_top' ); ?>
					<legend><?php _e( 'Shipping Details', 'edd-simple-shipping' ); ?></legend>
					<p id="edd-shipping-address-wrap">
						<label class="edd-label"><?php _e( 'Shipping Address', 'edd-simple-shipping' ); ?></label>
						<span class="edd-description"><?php _e( 'The address to ship your purchase to.', 'edd-simple-shipping' ); ?></span>
						<input type="text" name="shipping_address" class="shipping-address edd-input" placeholder="<?php _e( 'Address line 1', 'edd-simple-shipping' ); ?>"/>
					</p>
					<p id="edd-shipping-address-2-wrap">
						<label class="edd-label"><?php _e( 'Shipping Address Line 2', 'edd-simple-shipping' ); ?></label>
						<span class="edd-description"><?php _e( 'The suite, apt no, PO box, etc, associated with your shipping address.', 'edd-simple-shipping' ); ?></span>
						<input type="text" name="shipping_address_2" class="shipping-address-2 edd-input" placeholder="<?php _e( 'Address line 2', 'edd-simple-shipping' ); ?>"/>
					</p>
					<p id="edd-shipping-city-wrap">
						<label class="edd-label"><?php _e( 'Shipping City', 'edd-simple-shipping' ); ?></label>
						<span class="edd-description"><?php _e( 'The city for your shipping address.', 'edd-simple-shipping' ); ?></span>
						<input type="text" name="shipping_city" class="shipping-city edd-input" placeholder="<?php _e( 'City', 'edd-simple-shipping' ); ?>"/>
					</p>
					<p id="edd-shipping-country-wrap">
						<label class="edd-label"><?php _e( 'Shipping Country', 'edd-simple-shipping' ); ?></label>
						<span class="edd-description"><?php _e( 'The country for your shipping address.', 'edd-simple-shipping' ); ?></span>
						<select name="shipping_country" class="shipping-country edd-select">
							<?php
							$countries = edd_get_country_list();
							foreach( $countries as $country_code => $country ) {
							  echo '<option value="' . $country_code . '">' . $country . '</option>';
							}
							?>
						</select>
					</p>
					<p id="edd-shipping-state-wrap">
						<label class="edd-label"><?php _e( 'Shipping State / Province', 'edd-simple-shipping' ); ?></label>
						<span class="edd-description"><?php _e( 'The state / province for your shipping address.', 'edd-simple-shipping' ); ?></span>
						<input type="text" size="6" name="shipping_state_other" id="shipping_state_other" class="shipping-state edd-input" placeholder="<?php _e( 'State / Province', 'edd-simple-shipping' ); ?>" style="display:none;"/>
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
					</p>
					<p id="edd-shipping-zip-wrap">
						<label class="edd-label"><?php _e( 'Shipping Zip / Postal Code', 'edd-simple-shipping' ); ?></label>
						<span class="edd-description"><?php _e( 'The zip / postal code for your shipping address.', 'edd-simple-shipping' ); ?></span>
						<input type="text" size="4" name="shipping_zip" class="shipping-zip edd-input" placeholder="<?php _e( 'Zip / Postal code', 'edd-simple-shipping' ); ?>"/>
					</p>
					<?php do_action( 'edd_shipping_address_bottom' ); ?>
				</fieldset>
			</div>
		</div>
<?php 	echo ob_get_clean();
	}


	/**
	 * Perform error checks during checkout
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
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
			if( empty( $post_data['card_address'] ) )
				edd_set_error( 'missing_address', __( 'Please enter a shipping address', 'edd-simple-shipping' ) );

			if( empty( $post_data['card_city'] ) )
				edd_set_error( 'missing_city', __( 'Please enter a city for shipping', 'edd-simple-shipping' ) );

			if( empty( $post_data['card_zip'] ) )
				edd_set_error( 'missing_zip', __( 'Please enter a zip/postal code for shipping', 'edd-simple-shipping' ) );

		}

	}


	/**
	 * Attach our shipping info to the payment gateway daya
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return array
	 */
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

			$shipping_info['address']  = sanitize_text_field( $_POST['card_address'] );
			$shipping_info['address2'] = sanitize_text_field( $_POST['card_address_2'] );
			$shipping_info['city']     = sanitize_text_field( $_POST['card_city'] );
			$shipping_info['zip']      = sanitize_text_field( $_POST['card_zip'] );
			$shipping_info['state']    = sanitize_text_field( $_POST['card_state'] );
			$shipping_info['country']  = sanitize_text_field( $_POST['billing_country'] );

		}

		$purchase_data['user_info']['shipping_info'] = $shipping_info;

		return $purchase_data;

	}


	/**
	 * Sets up the shipping details for PayPal
	 *
	 * This makes it possible to use the Print Shipping Label feature in PayPal
	 *
	 * @since 1.1
	 *
	 * @access public
	 * @return array
	 */
	public function send_shipping_to_paypal( $paypal_args = array(), $purchase_data = array() ) {

		if( ! $this->cart_needs_shipping() )
			return $paypal_args;

		$shipping_info = $purchase_data['user_info']['shipping_info'];

		$paypal_args['no_shipping'] = '0';
		$paypal_args['address1']    = $shipping_info['address'];
		$paypal_args['address2']    = $shipping_info['address2'];
		$paypal_args['city']        = $shipping_info['city'];
		$paypal_args['state']       = $shipping_info['country'] == 'US' ? $shipping_info['state'] : null;
		$paypal_args['country']     = $shipping_info['country'];
		$paypal_args['zip']         = $shipping_info['zip'];


		return $paypal_args;

	}


	/**
	 * Set a purchase as not shipped
	 *
	 * This is so that we can grab all purchases in need of being shipped
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function set_as_not_shipped( $payment_id = 0, $payment_data = array() ) {

		$shipping_info = ! empty( $payment_data['user_info']['shipping_info'] ) ? $payment_data['user_info']['shipping_info'] : false;

		if( ! $shipping_info )
			return;

		// Indicate that this purchase needs shipped
		update_post_meta( $payment_id, '_edd_payment_shipping_status', '1' );

	}


	/**
	 * Display shipping details in the View Details popup
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function show_shipping_details( $payment_meta = array(), $user_info = array() ) {

		$shipping_info = ! empty( $user_info['shipping_info'] ) ? $user_info['shipping_info'] : false;

		if( ! $shipping_info )
			return;

		$countries = edd_get_country_list();

		echo '<li><strong>' . __( 'Shipping Info', 'edd-simple-shipping' ) . '</strong></li>';
		echo '<li><strong>&nbsp;&nbsp;' . __( 'Address: ', 'edd-simple-shipping' ) . '</strong>' . $shipping_info['address'] . '</li>';
		if( ! empty( $shipping_info['address2'] ) )
			echo '<li><strong>&nbsp;&nbsp;' . __( 'Address Line 2: ', 'edd-simple-shipping' ) . '</strong>' .  $shipping_info['address2'] . '</li>';
		echo '<li><strong>&nbsp;&nbsp;' . __( 'City: ', 'edd-simple-shipping' ) . '</strong>' .  $shipping_info['city'] . '</li>';
		echo '<li><strong>&nbsp;&nbsp;' . __( 'State/Province: ', 'edd-simple-shipping' ) . '</strong>' .   $shipping_info['state'] . '</li>';
		echo '<li><strong>&nbsp;&nbsp;' . __( 'Zip/Postal Code: ', 'edd-simple-shipping' ) . '</strong>' .  $shipping_info['zip'] . '</li>';
		echo '<li><strong>&nbsp;&nbsp;' . __( 'Country: ', 'edd-simple-shipping' ) . '</strong>' .  $countries[ $shipping_info['country'] ] . '</li>';

	}


	/**
	 * Add a shipped status column to Payment History
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return array
	 */
	public function add_shipped_column( $columns ) {
		// Force the Shipped column to be placed just before Status
		unset( $columns['status'] );
		$columns['shipped'] = __( 'Shipped?', 'edd-simple-shipping' );
		$columns['status']  = __( 'Status', 'edd-simple-shipping' );
		return $columns;
	}


	/**
	 * Make the Shipped? column sortable
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return array
	 */
	public function add_sortable_column( $columns ) {
		$columns['shipped'] = array( 'shipped', false );
		return $columns;
	}


	/**
	 * Sort payment history by shipped status
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return array
	 */
	public function sort_payments( $args ) {

		if( isset( $_GET['orderby'] ) && $_GET['orderby'] == 'shipped' ) {

			$args['orderby'] = 'meta_value';
			$args['meta_key'] = '_edd_payment_shipping_status';

		}

		return $args;

	}


	/**
	 * Display the shipped status
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return string
	 */
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


	/**
	 * Add a Shipped? checkbox to the edit payment screen
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function edit_payment_option( $payment_id = 0 ) {

		$status  = get_post_meta( $payment_id, '_edd_payment_shipping_status', true );
		if( ! $status )
			return;

		$shipped = $status == '2' ? true : false;
?>
	<tr>
		<th scope="row" valign="top">
			<span><?php _e( 'Shipped?', 'edd-simple-shipping' ); ?></span>
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
	 * @access      public
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


	/**
	 * Add the shipping info to the admin sales notice
	 *
	 * @access      public
	 * @since       1.1
	 * @return      string
	 */
	public function admin_sales_notice( $email = '', $payment_id = 0, $payment_data = array() ) {

		$shipped = get_post_meta( $payment_id, '_edd_payment_shipping_status', true );

		// Only modify the email if shipping info needs to be added
		if( '1' == $shipped ) {

			$user_info     = maybe_unserialize( $payment_data['user_info'] );
			$shipping_info = $user_info['shipping_info'];

			$email .= "\n\n" . __( 'Shipping Details:', 'edd-simple-shipping' ) . "\n";
			$email .= __( 'Address:', 'edd-simple-shipping' ) . " " . $shipping_info['address'] . "\n";
			$email .= __( 'Address Line 2:', 'edd-simple-shipping' ) . " " . $shipping_info['address2'] . "\n";
			$email .= __( 'City:', 'edd-simple-shipping' ) . " " . $shipping_info['city'] . "\n";
			$email .= __( 'Zip/Postal Code:', 'edd-simple-shipping' ) . " " . $shipping_info['zip'] . "\n";
			$email .= __( 'Country:', 'edd-simple-shipping' ) . " " . $shipping_info['country'] . "\n";

		}

		return $email;

	}


	/**
	 * Add the export unshipped orders box to the export screen
	 *
	 * @access      public
	 * @since       1.2
	 * @return      void
	 */
	public function show_export_options() {
?>
		<div class="postbox">
			<h3><span><?php _e( 'Export Unshipped Orders to CSV', 'edd-simple-shipping' ); ?></span></h3>
			<div class="inside">
				<p><?php _e( 'Download a CSV of all unshipped orders.', 'edd-simple-shipping' ); ?></p>
				<p><a class="button" href="<?php echo wp_nonce_url( add_query_arg( array( 'edd-action' => 'unshipped_orders_export' ) ), 'edd_export_unshipped_orders' ); ?>"><?php _e( 'Generate CSV', 'edd-simple-shipping' ) ; ?></a></p>
			</div><!-- .inside -->
		</div><!-- .postbox -->
<?php
	}


	/**
	 * Trigger the CSV export
	 *
	 * @access      public
	 * @since       1.2
	 * @return      void
	 */
	public function do_export() {
		require_once dirname( __FILE__ ) . '/class-shipping-export.php';

		$export = new EDD_Simple_Shipping_Export();

		$export->export();
	}

	/**
	 * Add our extension settings
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return array
	 */
	public function settings( $settings ) {
		$license_settings = array(
			array(
				'id' => 'edd_simple_shipping_license_header',
				'name' => '<strong>' . __( 'Simple Shipping', 'edd-simple-shipping' ) . '</strong>',
				'desc' => '',
				'type' => 'header',
				'size' => 'regular'
			),
			array(
				'id' => 'edd_simple_shipping_base_country',
				'name' => __( 'Base Region', 'edd-simple-shipping'),
				'desc' => __( 'Choose the country your store is based in', 'edd-simple-shipping '),
				'type'  => 'select',
				'options' => edd_get_country_list()
			)
		);

		return array_merge( $settings, $license_settings );
	}

}


/**
 * Get everything running
 *
 * @since 1.0
 *
 * @access private
 * @return void
 */

function edd_simple_shipping_load() {
	$edd_simple_shipping = new EDD_Simple_Shipping();
}
add_action( 'plugins_loaded', 'edd_simple_shipping_load' );
