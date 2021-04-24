<?php
/*
   Plugin Name: iPayOS Payment Gateway For WooCommerce
   Description: Extends WooCommerce to Process Payments with iPayOS gateway.
   Version: 3.2.1
   Plugin URI: https://github.com/iPayOS/ipayos-woocommerce-plugin
   Author: Varatharaja Kajamugan 
   Author URI: https://github.com/yazhii-admin
   License: Under GPL2

*/

add_action('plugins_loaded', 'woocommerce_ipayos_init', 0);

function woocommerce_ipayos_init() {

   if ( !class_exists( 'WC_Payment_Gateway' ) ) 
      return;

   /**
   * Localisation
   */
   load_plugin_textdomain('wc-ipayos', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
   
   /**
   * iPayOS Payment Gateway class
   */
   class WC_Ipayos extends WC_Payment_Gateway 
   {
      protected $msg = array();
 
      public function __construct(){
         $this->id               = 'ipayos';
         $this->method_title     = __('iPayOS', 'tech');
         $this->icon             = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/logo.png';
         $this->has_fields       = false;
         $this->init_form_fields();
         $this->init_settings();
         $this->title            = $this->settings['title'];
         $this->description      = $this->settings['description'];
         $this->client_id            = $this->settings['client_id'];
         $this->token  = $this->settings['token'];
         $this->secret         = $this->settings['secret'];
         $this->success_message  = $this->settings['success_message'];
         $this->failed_message   = $this->settings['failed_message'];
         $this->liveurl          = 'https://www.ipayos.com/ncc_controller.php';
         $this->msg['message']   = "";
         $this->msg['class']     = "";
         
         add_action('init', array(&$this, 'check_ipayos_response'));

         add_action( 'woocommerce_api_ipayos_callback', array( $this, 'check_ipayos_response' ) );

         if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
               add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
            } else {
               add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
         }

         add_action('woocommerce_receipt_ipayos', array(&$this, 'receipt_page'));
         add_action('woocommerce_thankyou_ipayos',array(&$this, 'thankyou_page'));
         
         // Check if the gateway can be used
         if ( ! $this->is_valid_for_use() ) {
            $this->enabled = false;
         }  
      }
      
      function init_form_fields()
      {
         $this->form_fields = array(
            'enabled'      => array(
                  'title'        => __('Enable/Disable', 'tech'),
                  'type'         => 'checkbox',
                  'label'        => __('Enable iPayOS Payment Module.', 'tech'),
                  'default'      => 'no'),
            'title'        => array(
                  'title'        => __('Title:', 'tech'),
                  'type'         => 'text',
                  'description'  => __('This controls the title which the user sees during checkout.', 'tech'),
                  'default'      => __('iPayOS ( VISA/MASTER )', 'tech')),
            'description'  => array(
                  'title'        => __('Description:', 'tech'),
                  'type'         => 'textarea',
                  'description'  => __('This controls the description which the user sees during checkout.', 'tech'),
                  'default'      => __('SMART EXPERIENCE IN DIGITAL PAYMENTS.', 'tech')),
            'client_id'     => array(
                  'title'        => __('Client ID', 'tech'),
                  'type'         => 'password',
                  'description'  => __('This is iPayOS Client ID')),
            'token' => array(
                  'title'        => __('Client Token', 'tech'),
                  'type'         => 'password',
                  'description'  =>  __('Client Token', 'tech')),
            'secret' => array(
                  'title'        => __('Client Secret', 'tech'),
                  'type'         => 'password',
                  'description'  =>  __('Client Secret', 'tech')),
            'success_message' => array(
                  'title'        => __('Transaction Success Message', 'tech'),
                  'type'         => 'textarea',
                  'description'=>  __('Message to be displayed on successful transaction.', 'tech'),
                  'default'      => __('Your payment has been procssed successfully.', 'tech')),
            'failed_message'  => array(
                  'title'        => __('Transaction Failed Message', 'tech'),
                  'type'         => 'textarea',
                  'description'  =>  __('Message to be displayed on failed transaction.', 'tech'),
                  'default'      => __('Your transaction has been declined.', 'tech')),
            'working_mode'    => array(
                  'title'        => __('API Mode'),
                  'type'         => 'select',
                  'options'      => array('false'=>'Live Mode', 'false_test' => 'Live/Production API in Test Mode', 'true'=>'Sandbox/Developer API Mode'),
                  'description'  => "Live or Production / Sandbox Mode" )
         );
      }
      
      /**
	 	* Check if the store curreny is set to LKR
	 	**/
		public function is_valid_for_use() {
			if( ! in_array( get_woocommerce_currency(), array( 'LKR' ) ) ) {
				$this->msg = 'iPayOS doesn\'t support your store currency, set it to Sri Lankan Rupees;';
				return false;
			}
			return true;
		}
     
      /**
       * Admin Panel Options
       * - Options for bits like 'title' and availability on a country-by-country basis
      **/
      public function admin_options()
      {
         echo '<h3>'.__('iPayOS Payment Gateway', 'tech').'</h3>';
         echo '<p>'.__('iPayOS is most popular payment gateway for online payment processing').'</p>';
         echo '<table class="form-table">';
         $this->generate_settings_html();
         echo '</table>';

      }
      
      /**
      *  There are no payment fields for iPayOS, but want to show the description if set.
      **/
      function payment_fields()
      {
         if ( $this->description ) 
            echo wpautop(wptexturize($this->description));
      }
      
      public function thankyou_page($order_id) 
      {
       
      }
      /**
      * Receipt Page
      **/
      function receipt_page($order)
      {
         echo '<p>'.__('Thank you for your order, Redirecting to process the payment.', 'tech').'</p>';
         $this->generate_ipayos_form($order);
      }

      /**
       * Process the payment and return the result
      **/
      function process_payment($order_id)
      {
         $order = new WC_Order($order_id);
         return array(
         				'result' 	=> 'success',
         				'redirect'	=> $order->get_checkout_payment_url( true )
         			);
      }
      
      /**
       * Check for valid iPayOS server callback to validate the transaction response.
      **/
      function check_ipayos_response()
      {
         if( isset( $_REQUEST['requestId'], $_REQUEST['clientReference'] ) ) {
            $order_id 		= $_REQUEST['clientReference'];
            $request_id = $_REQUEST['requestId'];
            $order 			= wc_get_order( $order_id );

            $jsonRequest['clientId']=$this->client_id;
            $jsonRequest['token']=$this->token;
            $jsonRequest['secret']=$this->secret;

            $jsonRequest['requestType']="NCC_COMPLETE";
            $jsonRequest['requestId']=$request_id;
            $processURI = $this->liveurl;
            $jsonResponse = $this->sendRequest($processURI, json_encode($jsonRequest));
            $responseObject = json_decode($jsonResponse);

            if($responseObject->status == 0){
               $order->payment_complete( $responseObject->data->nccReference );
               $order->add_order_note( sprintf( 'Payment via iPayOS successful (Transaction ID: %s)', $responseObject->data->nccReference ) );
               wc_empty_cart();
               wp_redirect( $this->get_return_url( $order ) );
               exit;
            }else{
               wp_redirect( wc_get_page_permalink( 'checkout' ) );
			      exit;
            }
         }else{
            wp_redirect( wc_get_page_permalink( 'checkout' ) );
			   exit;
         }

         
      }
      
      public function web_redirect($url){
         echo "<html><head><script language=\"javascript\">
                <!--
                window.location=\"{$url}\";
                //-->
                </script>
                </head><body><noscript><meta http-equiv=\"refresh\" content=\"0;url={$url}\"></noscript></body></html>";
      
      }
      
      /**
      * Generate ipayos.com button link
      **/
      public function generate_ipayos_form($order_id)
      {
         global $woocommerce;
         
         $order      = new WC_Order($order_id);
         $timeStamp  = time();
         
         $relay_url = get_site_url().'/wc-api/ipayos_callback/';
         
         $jsonRequest['clientId']=$this->client_id;
    	   $jsonRequest['token']=$this->token;
    	   $jsonRequest['secret']=$this->secret;
         $jsonRequest['requestType']="NCC_INIT";
         $jsonRequest['transactionAmount']=$order->order_total;
         $jsonRequest['msisdn']=$order->billing_phone;
         $jsonRequest['email']=$order->billing_email;
         $jsonRequest['clientReference']=$order_id;
         $jsonRequest['redirectUrl']=$relay_url;
         $processURI = $this->liveurl;
         $jsonResponse = $this->sendRequest($processURI, json_encode($jsonRequest));
         $responseObject = json_decode($jsonResponse);
         if($responseObject->status == 0){
            $this->web_redirect($responseObject->data->paymentPageUrl);
            exit;
         }else{
            wp_redirect( wc_get_page_permalink( 'checkout' ) );
            exit;
         }
      }

      public function sendRequest($url, $jsonRequest) {
         $ch = curl_init();
         curl_setopt($ch, CURLOPT_URL, $url);
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
         curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 180);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
         curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)");
         curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
         $headers = array();
         $headers[] = 'Content-Type: application/json';
         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
         $result = curl_exec($ch);
         curl_close($ch);
         return $result;
      }
   }

   /**
    * Add this Gateway to WooCommerce
   **/
   function woocommerce_add_ipayos_gateway($methods) 
   {
      $methods[] = 'WC_Ipayos';
      return $methods;
   }

   add_filter('woocommerce_payment_gateways', 'woocommerce_add_ipayos_gateway' );
}

