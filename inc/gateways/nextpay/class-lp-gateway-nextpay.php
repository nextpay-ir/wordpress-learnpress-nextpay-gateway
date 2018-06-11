<?php
/**
 * Class Nextpay Payment gateway.
 *
 * @author  Nextpay Co [https:://nextpay.ir]
 * @package LearnPress/Classes
 * @since   3.0.0
 */

/**
 * Prevent loading this file directly
 */
defined( 'ABSPATH' ) || exit();

if ( ! class_exists( 'LP_Gateway_Nextpay' ) ) {
	/**
	 * Class LP_Gateway_Nextpay.
	 */
	class LP_Gateway_Nextpay extends LP_Gateway_Abstract {
		/**
		 * @var string
		 */
		protected $method = '';

		/**
		 * @var null
		 */
		protected $nextpay_url = null;

		/**
		 * @var null
		 */
		protected $nextpay_payment_url = null;

		/**
		 * @var null
		 */
		protected $settings = null;
		
		/**
		 * @var null
		 */
		protected $token = null;

		/**
		 * @var null
		 */
		protected $request_status = null;
		
		/**
		 * @var array
		 */
		protected $line_items = array();

		/**
		 * LP_Gateway_Nextpay constructor.
		 */
		public function __construct() {
			$this->id = 'nextpay';

			$this->method_title       = __( 'نکست پی', 'learnpress' );
			$this->method_description = __( '<p><a href="https://nextpay.ir" target="_blank"> پرداخت توسط نکست پی. </a></p>', 'learnpress' );
			$this->icon               = '';

			$this->title       = __( 'نکست پی', 'learnpress' );
			$this->description = __( 'پرداخت با نکست پی', 'learnpress' );

			// live
			$this->nextpay_url         = 'https://nextpay.ir/';
			$this->nextpay_payment_url = 'https://api.nextpay.org/payment/';

			// get settings
			$this->settings = LP()->settings()->get_group( 'nextpay', '' );

			$this->enabled = $this->settings->get( 'enable' );

			$this->init();
			parent::__construct();
		}

		/**
		 * Init.
		 */
		public function init() {
			if ( $this->is_enabled() ) {

				if ( did_action( 'init' ) ) {
					$this->register_web_hook();
					$this->parse_ipn();
				} else {
					add_action( 'init', array( $this, 'register_web_hook' ) );
					add_action( 'init', array( $this, 'parse_ipn' ) );
				}
				add_action( 'learn_press_web_hook_learn_press_nextpay', array( $this, 'web_hook_process_nextpay' ) );
			}

			add_filter( 'learn-press/payment-gateway/' . $this->id . '/available', array(
				$this,
				'nextpay_available'
			), 10, 2 );
		}

		public function register_web_hook() {
			learn_press_register_web_hook( 'nextpay', 'learn_press_nextpay' );
		}

		public function web_hook_process_nextpay( $request ) {
		
			include( 'nextpay_payment.php' );
			$listener = new Nextpay_Payment();

			
			$order = LP_Order::instance( $request['order_id'] );
			$currency_code = learn_press_get_currency()  ;
			if ($currency_code == 'IRR') {
				$amount = learn_press_get_cart_total() / 10 ;
			}else{
				$amount = learn_press_get_cart_total() ;
			}
			
			$parameters = array
			(
			    'api_key'	=> $this->settings->get( 'nextpay_api_key' ),
			    'order_id'	=> $request['order_id'],
			    'trans_id' 	=> $request['trans_id'],
			    'amount'	=> $amount,
			);
			$nextpay = new Nextpay_Payment();
			$result = $nextpay->verify_request($parameters);
			if ($result == 0) {
				$this->request_status['payment_status'] = 'completed';
				// make order status success
				$this->payment_status_completed($order , $request);
				// redirect to success page
				wp_redirect(esc_url( $this->get_return_url( $order ) ));
				exit();
			}else{
				$this->request_status['payment_status'] = 'pending';
				wp_redirect(esc_url( learn_press_get_page_link( 'checkout' )  ));
				exit();
			}

			$method   = 'payment_status_' . $request['payment_status'];
			$callback = array( $this, $method );
			if ( is_callable( $callback ) ) {
				call_user_func( $callback, $order, $request );
			}
		}

		public function payment_method_name( $slug ) {
			return $slug == 'nextpay-standard' ? 'Nextpay' : $slug;
		}

		/**
		 * Check payment gateway available.
		 *
		 * @param $default
		 * @param $payment
		 *
		 * @return bool
		 */
		public function nextpay_available( $default, $payment ) {

			if ( ! $this->is_enabled() ) {
				return false;
			}

			return $default;

		}

		public function get_order( $raw_custom ) {
			$raw_custom = stripslashes( $raw_custom );
			if ( ( $custom = json_decode( $raw_custom ) ) && is_object( $custom ) ) {
				$order_id  = $custom->order_id;
				$order_key = $custom->order_key;

				// Fallback to serialized data if safe. This is @deprecated in 2.3.11
			} elseif ( preg_match( '/^a:2:{/', $raw_custom ) && ! preg_match( '/[CO]:\+?[0-9]+:"/', $raw_custom ) && ( $custom = maybe_unserialize( $raw_custom ) ) ) {
				$order_id  = $custom[0];
				$order_key = $custom[1];

				// Nothing was found
			} else {
				_e( 'Error: order ID and key were not found in "custom".' );

				return false;
			}

			$order = new LP_Order( $order_id );

			if ( ! $order || $order->order_key !== $order_key ) {
				printf( __( 'Error: Order Keys do not match %s and %s.' ), $order->order_key, $order_key );

				return false;
			}

			return $order;
		}

		/**
		 * Retrieve order by nextpay txn_id
		 *
		 * @param $txn_id
		 *
		 * @return int
		 */
		public function get_order_id( $txn_id ) {

			$args = array(
				'meta_key'    => '_learn_press_transaction_method_id',
				'meta_value'  => $txn_id,
				'numberposts' => 1, //we should only have one, so limit to 1
			);

			$orders = learn_press_get_orders( $args );
			if ( $orders ) {
				foreach ( $orders as $order ) {
					return $order->ID;
				}
			}

			return 0;
		}

		public function parse_ipn() {
			if ( ! isset( $_REQUEST['ipn'] ) ) {
				return;
			}
			require_once( 'nextpay-ipn/ipn.php' );
		}

		public function process_order_nextpay_standard() {

			if ( ! empty( $_REQUEST['learn-press-transaction-method'] ) && ( 'nextpay-standard' == $_REQUEST['learn-press-transaction-method'] ) ) {
				// if we have a nextpay-nonce in $_REQUEST that meaning user has clicked go back to our site after finished the transaction
				// so, create a new order
				if ( ! empty( $_REQUEST['nextpay-nonce'] ) && wp_verify_nonce( $_REQUEST['nextpay-nonce'], 'learn-press-nextpay-nonce' ) ) {
					if ( ! empty( $_REQUEST['tx'] ) ) //if PDT is enabled
					{
						$transaction_id = $_REQUEST['tx'];
					} else if ( ! empty( $_REQUEST['txn_id'] ) ) //if PDT is not enabled
					{
						$transaction_id = $_REQUEST['txn_id'];
					} else {
						$transaction_id = null;
					}

					if ( ! empty( $_REQUEST['cm'] ) ) {
						$transient_transaction_id = $_REQUEST['cm'];
					} else if ( ! empty( $_REQUEST['custom'] ) ) {
						$transient_transaction_id = $_REQUEST['custom'];
					} else {
						$transient_transaction_id = null;
					}

					if ( ! empty( $_REQUEST['st'] ) ) //if PDT is enabled
					{
						$transaction_status = $_REQUEST['st'];
					} else if ( ! empty( $_REQUEST['payment_status'] ) ) //if PDT is not enabled
					{
						$transaction_status = $_REQUEST['payment_status'];
					} else {
						$transaction_status = null;
					}


					if ( ! empty( $transaction_id ) && ! empty( $transient_transaction_id ) && ! empty( $transaction_status ) ) {
						$user = learn_press_get_current_user();


						try {
							//If the transient still exists, delete it and add the official transaction
							if ( $transaction_object = learn_press_get_transient_transaction( 'lpps', $transient_transaction_id ) ) {

								learn_press_delete_transient_transaction( 'lpps', $transient_transaction_id );
								$order_id = $this->get_order_id( $transaction_id );
								$order_id = learn_press_add_transaction(
									array(
										'order_id'           => $order_id,
										'method'             => 'nextpay-standard',
										'method_id'          => $transaction_id,
										'status'             => $transaction_status,
										'user_id'            => $user->get_id(),
										'transaction_object' => $transaction_object['transaction_object']
									)
								);

								wp_redirect( ( $confirm_page_id = learn_press_get_page_id( 'taken_course_confirm' ) ) && get_post( $confirm_page_id ) ? learn_press_get_order_confirm_url( $order_id ) : get_home_url() /* SITE_URL */ );
								die();
							}

						}
						catch ( Exception $e ) {
							return false;

						}

					} else if ( is_null( $transaction_id ) && is_null( $transient_transaction_id ) && is_null( $transaction_status ) ) {
					}
				}
			}

			wp_redirect( get_home_url() /* SITE_URL */ );
			die();
		}

		/**
		 * Handle a completed payment
		 *
		 * @param LP_Order $order
		 * @param array    $request
		 */
		protected function payment_status_completed( $order, $request ) {

			// order status is already completed
			if ( $order->has_status( 'completed' ) ) {
				exit;
			}

			if ( 'completed' === $this->request_status['payment_status'] ) {
				$this->payment_complete( $order, ( ! empty( $request['txn_id'] ) ? $request['txn_id'] : '' ), __( 'IPN payment completed', 'learnpress' ) );
				
			} else {
			}
		}

		/**
		 * Handle a pending payment
		 *
		 * @param  LP_Order
		 * @param  Paypal IPN params
		 */
		protected function payment_status_pending( $order, $request ) {
			$this->payment_status_completed( $order, $request );
		}

		/**
		 * @param        LP_Order
		 * @param string $txn_id
		 * @param string $note - not use
		 */
		public function payment_complete( $order, $txn_id = '', $note = '' ) {
			$order->payment_complete( $txn_id );
		}

		public function process_payment( $order ) {
		
			include( 'nextpay_payment.php' );
		
			$currency_code = learn_press_get_currency() ;
			if ($currency_code == 'IRR') {
				$amount = learn_press_get_cart_total() / 10 ;
			}else{
				$amount = learn_press_get_cart_total() ;
			}
			$parameters = array(
				"api_key"=>$this->settings->get( 'nextpay_api_key' ),
				"order_id"=> $order,
				"amount"=>$amount,
				"callback_uri"=>get_site_url() . '/?' . learn_press_get_web_hook( 'nextpay' ) . '=1'
			);
			$nextpay = new Nextpay_Payment($parameters);
			$result = $nextpay->token();
			if ($result->code == -1){
					$redirect = $nextpay->request_http . '/' . $result->trans_id;
			}else{
					$redirect = false ;
			}
			$json = array(
				'result'   => $redirect ? 'success' : 'fail',
				'redirect' => $redirect
			);

			return $json;
		}

		protected function prepare_line_items() {
			$this->line_items = array();
			if ( $items = LP()->get_cart()->get_items() ) {
				foreach ( $items as $item ) {
					$this->add_line_item( get_the_title( $item['item_id'] ), $item['quantity'], $item['total'] );
				}
			}
		}

		protected function add_line_item( $item_name, $quantity = 1, $amount = 0, $item_number = '' ) {
			$index = ( sizeof( $this->line_items ) / 4 ) + 1;

			if ( $amount < 0 || $index > 9 ) {
				return false;
			}

			$this->line_items[ 'item_name_' . $index ]   = html_entity_decode( $item_name ? $item_name : __( 'Item', 'learnpress' ), ENT_NOQUOTES, 'UTF-8' );
			$this->line_items[ 'quantity_' . $index ]    = $quantity;
			$this->line_items[ 'amount_' . $index ]      = $amount;
			$this->line_items[ 'item_number_' . $index ] = $item_number;

			return true;
		}

		public function get_item_lines() {
			return $this->line_items;
		}

		public function get_request_url( $order_id ) {

			return $this->nextpay_payment_url;
		}

		public function get_settings() {
			return apply_filters(
				'learn-press/gateway-payment/nextpay/settings',
				array(
					array(
						'title'   => __( 'Enable', 'learnpress' ),
						'id'      => '[enable]',
						'default' => 'yes',
						'type'    => 'yes-no'
					),
					array(
						'title'      => __( 'Api Key (کلید مجوزدهی)', 'learnpress' ),
						'id'         => '[nextpay_api_key]',
						'type'       => 'text',
						'visibility' => array(
							'state'       => 'show',
							'conditional' => array(
								array(
									'field'   => '[enable]',
									'compare' => '=',
									'value'   => 'yes'
								)
							)
						)
					)
				)
			);
		}

		public function get_icon() {
			if ( empty( $this->icon ) ) {
				$this->icon = LP()->plugin_url( 'assets/images/nextpay.png' );
			}

			return parent::get_icon();
		}
	}
}