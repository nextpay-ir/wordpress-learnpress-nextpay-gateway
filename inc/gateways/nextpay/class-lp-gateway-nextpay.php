<?php
/**
 * Class LP_Gateway_Nextpay
 *
 * @author  ThimPress
 * @package LearnPress/Classes
 * @version 1.0
 */

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class LP_Gateway_Nextpay extends LP_Gateway_Abstract {

	protected $token_url = '';

	protected $verify_url = '';

	protected $payment_url = '';


	protected $settings = null;


	protected $line_items = array();

	/**
	 *
	 */
	public function __construct() {
		$this->id = 'nextpay';

		$this->method_title       = 'nextpay';
		$this->method_description = 'Make payment via Nextpay';

		$this->title       = 'Nextpay';
		$this->description = __( 'Pay with Nextpay', 'learnpress' );

		// constanst
		$this->token_url = 'https://api.nextpay.org/gateway/token.wsdl';
		$this->verify_url = 'https://api.nextpay.org/gateway/verify.wsdl';
		$this->payment_url = 'https://api.nextpay.org/gateway/payment/';

		$this->settings = LP()->settings;

		$this->init();
		parent::__construct();
	}

	public function init() {
			if ( did_action( 'init' ) ) {
				$this->register_web_hook();
			} else {
				add_action( 'init', array( $this, 'register_web_hook' ) );
			}

			add_action( 'learn_press_web_hook_learn_press_nextpay', array( $this, 'web_hook_process_nextpay' ) );




		if ( is_admin() ) {
			ob_start();
			?>
			<script>
				$('#learn_press_nextpay_enable').change(function () {
					var $rows = $(this).closest('tr').siblings('tr');
					if (this.checked) {
						$rows.css("display", "");
					} else {
						$rows.css("display", "none");
					}
				}).trigger('change');
			</script>
			<?php
			$script = ob_get_clean();
			$script = preg_replace( '!</?script>!', '', $script );
			learn_press_enqueue_script( $script );
		}
		add_filter( 'learn_press_payment_gateway_available_nextpay', array( $this, 'nextpay_available' ), 10, 2 );
	}

	public function register_web_hook() {
		learn_press_register_web_hook( 'nextpay', 'learn_press_nextpay' );
	}


	public function web_hook_process_nextpay( $request ) {

		$order = LP_Order::instance( $request['order_id'] );

		$currency_code = learn_press_get_currency()  ;

		if ($currency_code == 'IRR') {
			$amount = LP()->get_checkout_cart()->total / 10 ;
		}else{
			$amount = LP()->get_checkout_cart()->total ;
		}

		$client = new SoapClient($this->verify_url, array('encoding' => 'UTF-8'));
		$result = $client->PaymentVerification(
				array(
					'api_key' 	=> $this->settings->get( 'nextpay_key' ),
					'amount' 	=> $amount,
					'order_id' 	=> $request['order_id'],
					'trans_id' 	=> $request['trans_id']
				)
		);

		$result = $result->PaymentVerificationResult;


		if ($result->code == 0) {
			// make order status success
			$this->payment_status_completed($order , $request);
			// redirect to success page
			wp_redirect(esc_url( $this->get_return_url( $order ) ));
			exit();
		}else{
			wp_redirect(esc_url( learn_press_get_page_link( 'checkout' )  ));
			exit();
		}
	}

	public function payment_method_name( $slug ) {
		return $slug == 'nextpay-standard' ? 'Nextpay' : $slug;
	}

	public function nextpay_available( $a, $b ) {
		return LP()->settings->get( 'nextpay_enable' ) == 'yes';
	}


	public function process_payment( $order_id ) {

		$currency_code = learn_press_get_currency()  ;

		if ($currency_code == 'IRR') {
			$amount = LP()->get_checkout_cart()->total / 10 ;
		}else{
			$amount = LP()->get_checkout_cart()->total ;
		}

		$client = new SoapClient($this->token_url, array('encoding' => 'UTF-8'));

		$result = $client->TokenGenerator(
				array(
						'api_key' 	=> $this->settings->get( 'nextpay_key' ),
						'amount' 	=> $amount,
						'order_id' 	=> $order_id,
						'callback_uri' 	=> get_site_url() . '/?' . learn_press_get_web_hook( 'nextpay' ) . '=1'
				)
		);

		$result = $result->TokenGeneratorResult;

		if ($result->code == -1){
				$redirect = $this->payment_url . $result->trans_id;
		}else{
			 	$redirect = '' ;
		}


		$json = array(
			'result'   => $redirect ? 'success' : 'fail',
			'redirect' => $redirect
		);

		return $json;
	}


	/**
	 * Handle a completed payment
	 *
	 * @param LP_Order
	 * @param nextpay IPN params
	 */
	protected function payment_status_completed( $order, $request ) {

		// order status is already completed
		if ( $order->has_status( 'completed' ) ) {
			exit;
		}

		$this->payment_complete( $order, ( !empty( $request['trans_id'] ) ? $request['trans_id'] : '' ), __( 'Nextpay payment completed', 'learnpress' ) );

	}

	/**
	 * Handle a pending payment
	 *
	 * @param  LP_Order
	 * @param  Nextpay IPN params
	 */
	protected function payment_status_pending( $order, $request ) {
		$this->payment_status_completed( $order, $request );
	}

	/**
	 * @param        LP_Order
	 * @param string $txn_id
	 * @param string $note - not use
	 */
	public function payment_complete( $order, $trans_id = '', $note = '' ) {
		$order->payment_complete( $trans_id );
	}

	public function __toString() {
		return 'Nextpay';
	}
}
