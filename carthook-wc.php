<?php
/*
 * Plugin Name: CartHook for WooCommerce
 * Plugin URI: https://carthook.com/
 * Description: CartHook helps you increase revenue by automatically recovering abandoned carts.
 * Version: 1.0.2
 * Author: CartHook
 * Author URI: https://carthook.com/
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

class CartHook_WC {


	/**
	 * Constructor for the plugin.
	 *
	 * @access        public
	 */
	public function __construct() {

		if ( !class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_init', array( $this, 'carthook_nag_ignore' ) );
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Add the plugin page Settings and Docs links
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'carthook_plugin_links' ));

		// Hooks
		add_action( 'plugin', '');
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_menu', array( $this, 'admin_page' ) );
		add_action( 'wp_footer', array( $this, 'add_footer_scripts' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_cart_id' ), 30 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'payment_complete' ), 60 );

		// Handle order state changes for gateways that don't use Thank You page
		add_action( 'woocommerce_order_status_pending_to_processing', array( $this, 'payment_complete' ) );
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'payment_complete' ) );
		add_action( 'woocommerce_order_status_processing_to_completed', array( $this, 'payment_complete' ) );

	}


	/**
	 * Set up admin notices
	 *
	 * @access        public
	 * @return        void
	 */
	public function admin_notices() {

		// If the Merchant ID field is empty
		if ( ! get_option( 'carthook_merchant_id' ) ) :
		?>
		<div class="updated">
			<p><?php echo __( sprintf( 'CartHook requires a Merchant ID, please fill one out <a href="%s">here.</a>', admin_url( 'admin.php?page=carthook' ) ), 'carthook_wc' ); ?></p>
		</div>
		<?php
		endif;
	}


	/**
	 * Initialize the CartHook menu
	 *
	 * @access        public
	 * @return        void
	 */
	public function admin_page() {
		add_menu_page( 'CartHook', 'CartHook', 'manage_options', 'carthook', array( &$this, 'admin_options' ), plugins_url( 'images/carthook.png', __FILE__ ), 58 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}


	/**
	 * Add options to the CartHook menu
	 *
	 * @access        public
	 * @return        void
	 */
	public function admin_options() {
		?>
		<div class="wrap">
		<h2>CartHook for WooCommerce</h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'carthook-settings-group' ); ?>
				<?php do_settings_sections( 'carthook-settings-group' ); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">CartHook Merchant ID</th>
						<td><input type="text" name="carthook_merchant_id" value="<?php echo get_option( 'carthook_merchant_id' ); ?>" /></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
			<p>Need your Merchant ID?  Follow these steps:</p>
			<ol>
				<li><a href="https://carthook.com/sign-up">Create your CartHook account</a></li>
				<li>Set up your abandoned cart email campaign</li>
				<li>You'll find your Merchant ID in Step 3 of the Setup page. Simply click on the "WooCommerce" tab and copy and paste your Merchant ID above.</li>
				<li>Click on the Save Changes button on this page</li>
				<li>Go back to the Setup page of your CartHook account and make sure to click the "I've installed the tracking code" button in Step 3.</li>
			</ol>
			<p>Have any questions? Contact us at 1-800-816-9316 or email <a href="mailto:jordan@carthook.com">jordan@carthook.com</a></p>
		</div>
		<?php
	}


	/**
	 * Register settings for CartHook
	 *
	 * @access        public
	 * @return        void
	 */
	public function register_settings() {
		register_setting( 'carthook-settings-group', 'carthook_merchant_id' );
	}


	/**
	 * Add scripts to the footer of th checkout or thank you page
	 *
	 * @access        public
	 * @return        void
	 */
	public function add_footer_scripts() {
		if ( is_checkout() && ! is_order_received_page() ) {
			$this->add_checkout_script();
		} elseif ( is_order_received_page() ) {
			$this->add_thankyou_script();
		}
	}


	/**
	 * Checkout page script
	 *
	 * @access        public
	 * @return        void
	 */
	public function add_checkout_script() {
		?>
		<script type='text/javascript'>
			var crthk_setup = '<?php echo get_option( 'carthook_merchant_id' ); ?>';
			var crthk_cart = <?php echo $this->format_carthook_cart(); ?>;

			(function() {
				var ch = document.createElement('script'); ch.type = 'text/javascript'; ch.async = true;
				ch.src = 'https://carthook.com/api/js/';
				var x = document.getElementsByTagName('script')[0]; x.parentNode.insertBefore(ch, x);
			})();
		</script>
		<?php
	}


	/**
	 * Thank you page script
	 *
	 * @access        public
	 * @return        void
	 */
	public function add_thankyou_script() {
		?>
		<script type='text/javascript'>
			var crthk_setup = '<?php echo get_option( 'carthook_merchant_id' ); ?>'; 
			var crthk_complete = true;

			(function() {
				var ch = document.createElement('script'); ch.type = 'text/javascript'; ch.async = true;
				ch.src = 'https://carthook.com/api/js/';
				var x = document.getElementsByTagName('script')[0]; x.parentNode.insertBefore(ch, x);
			})();
		</script>
		<?php
	}


	/**
	 * When the order is submitted, retrieve the cart id and save it to the order
	 *
	 * @access        public
	 * @param         int $order_id
	 * @return        void
	 */
	public function save_cart_id( $order_id ) {

		if ( ! empty( $_COOKIE['crthk_cid'] ) ) {
			add_post_meta( $order_id, '_crthk_cid', $_COOKIE['crthk_cid'] );
		}
	}


	/**
	 * When the order is triggered as complete or processing,
	 * the payment is complete and CartHook should stop tracking
	 *
	 * @access        public
	 * @param         int $order_id
	 * @return        void
	 */
	public function payment_complete( $order_id ) {

		$merchant_id = get_option( 'carthook_merchant_id' );
		$cart_id = get_post_meta( $order_id, '_crthk_cid', true );

		wp_remote_get( 'https://carthook.com/api/track/complete/?_mid=' . $merchant_id . '&_cid=' . $cart_id, array(
			'method'		=> 'GET',
			'timeout'       => 70,
			'sslverify'     => false,
			'user-agent'    => 'CartHook-WC',
		) );
	}


	/**
	 * Format a JSON object that CartHook can work with
	 *
	 * @access        public
	 * @return        string
	 */
	public function format_carthook_cart() {
		$carthook_cart = array(
			'price' => WC()->cart->cart_contents_total,
			'carturl' => WC()->cart->get_cart_url()
		);

		// Format the cart items in the carthook format
		foreach ( WC()->cart->get_cart() as $item_key => $item ) {
			$_product = $item['data'];

			// Force price formatting to include 2 decimal places
			$eachItemCost = number_format( $item['line_total'] / $item['quantity'], 2, '.', '' );
			$totalItemCost = number_format( $item['line_total'], 2, '.', '' );

			$carthook_cart['items'][] = array(
				'imgUrl' => wp_get_attachment_url( $_product->get_image_id() ),
				'url' => get_permalink( $_product->id ),
				'name' => $_product->post->post_title,
				'eachItemCost' => strval( $eachItemCost ),
				'totalItemCost' => strval( $totalItemCost )
			);
		}

		return json_encode( $carthook_cart );
	}


	/**
	 * Plugin page links
	 *
	 * @param array $links
	 * @return array
	 */
	function carthook_plugin_links( $links ) {

		$links['settings']      = '<a href="' . admin_url( 'admin.php?page=carthook&settings-updated=true' ) . '">' . __( 'Settings', 'carthook_wc' ) . '</a>';
		$links['feedback']      = '<a href="mailto:jordan@carthook.com">' . __( 'Feedback', 'carthook_wc' ) . '</a>';
		$links['documentation'] = '<a href="https://carthook.com/dashboard/help">' . __( 'Documentation', 'carthook_wc' ) . '</a>';

		return $links;

	}


	/**
	 * WooCommerce missing notice.
	 *
	 * @return string
	 */
	public function woocommerce_missing_notice() {
		global $current_user ;
		$user_id = $current_user->ID;
		if ( ! get_user_meta( $user_id, 'carthook_wc_missing_nag' ) ) {
			echo '<div class="error"><p>' . sprintf( __( 'CartHook for WooCommerce requires %s to be installed and active. | <a href="%s">Hide Notice</a>', 'carthook_wc' ), '<a href="http://woocommerce.com/" target="_blank">' . __( 'WooCommerce', 'carthook_wc' ) . '</a>', '?carthook_wc_missing_nag=0' ) . '</p></div>';
		}
	}


	/**
	 *  Remove the nag if user chooses
	 */
	function carthook_nag_ignore() {

		global $current_user;
		$user_id = $current_user->ID;
		if ( isset( $_GET['carthook_wc_missing_nag'] ) && '0' == $_GET['carthook_wc_missing_nag'] ) {
			add_user_meta( $user_id, 'carthook_wc_missing_nag', 'true', true );
		}

	}


}

/**
 * Load CartHook
 */
function carthook_plugins_loaded() {
	new CartHook_WC();
}
add_action( 'plugins_loaded', 'carthook_plugins_loaded' );