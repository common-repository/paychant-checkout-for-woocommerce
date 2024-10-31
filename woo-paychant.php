<?php
/**
 * Plugin Name: Paychant Payment Gateway for WooCommerce
 * Plugin URI: https://paychant.com
 * Description: Accept cryptocurrency payments in your WooCommerce store using Paychant.
 * Version: 2.0.0
 * Author: Paychant
 * Author URI: sonexzach@gmail.com
 * License: GPLv2 or later
 * WC tested up to: 5.7.1
 */

if(!defined('ABSPATH'))exit;

define('PAYCHANT_PLUGIN_ID','paychant');

add_action('plugins_loaded', 'paychant_init', 99);
function paychant_init(){
    if (!class_exists( 'WC_Payment_Gateway')){ return;    }

	class WC_Gateway_Paychant extends WC_Payment_Gateway{
		public function __construct(){
			$this->id			= PAYCHANT_PLUGIN_ID;
			$this->method_title = __('Paychant', 'woothemes');
			$this->method_description = __('Paychant is a cryptocurrency payment gateway solution.', 'woocommerce');
			$this->order_button_text = __('Pay with Paychant', 'woocommerce');
            
            $this->assets_base  =   WP_PLUGIN_URL.'/'.plugin_basename(dirname(__FILE__)).'/assets/';
            $this->icon_alt     = $this->assets_base.'images/logo.png';
			$this->supported_currencies=array('NGN','USD','GBP','EUR','AUD','CAD','JPY','CNY');
			
	        $this->has_fields 	= true;
			$this->init_form_fields();
			$this->init_settings();
	
			// Define user set variables
			$this->enabled = $this->settings['enabled'];
			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			
			$this->redirect_method = ($this->get_option('checkout_method')!='popup');
			
			
			$this->test_api_key = $this->get_option('test_api_key');
			$this->live_api_key = $this->get_option('live_api_key');
			$this->testmode = ($this->get_option('environment') === 'sandbox') ? true : false;
			$this->api_key = $this->testmode?$this->test_api_key:$this->live_api_key;

			add_action(	'woocommerce_update_options_payment_gateways_' . $this->id, array($this,'process_admin_options'));
			add_action(	'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action('woocommerce_api_'.strtolower(get_class($this)), array($this,'ipn_callback'));
			if(!$this->is_valid_for_use()) $this->enabled = false;
		}
	
		private function is_valid_for_use(){
			if(!empty($this->supported_currencies)&&!in_array(get_option('woocommerce_currency'),$this->supported_currencies)) return false;
			return true;
		}
		
		public function get_icon(){
			$icon_html =
				'<img src="'.plugins_url('assets/images/logo.png', __FILE__).'" alt="Paychant" style="height:30px;margin-top:-10px;float:right;" />';
			return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
		}
		
		public function admin_options(){
	    	?>
			<h3><?php _e('Paychant', 'woothemes'); ?></h3>
			<p><?php _e('Paychant customers to make payments using cryptocurrency.', 'woothemes'); ?></p>
	    	<table class="form-table">
	    	<?php
	    		if($this->is_valid_for_use()){  	
	    			$this->generate_settings_html();
				}
	    		else {	?>
	            	<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woothemes' ); ?></strong>: <?php _e( 'Paychant does not support your store currency. It supports: '.implode(', ',$this->supported_currencies), 'woothemes' ); ?></p></div>
	        		<?php	        		
	    		}
	    	?>
			</table><!--/.form-table-->
	    	<p class="auto-style1">&nbsp;</p>
	    	<?php
	    }		
		
		function init_form_fields(){
			global $woocommerce;
			
			$cat_options=array();
			$product_categories = get_terms('product_cat',array('hide_empty'=>false));
			foreach($product_categories as $cat)$cat_options[$cat->term_id]=$cat->name;
			
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'woothemes' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Paychant Gateway', 'woothemes' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
					'default' => __( 'Paychant', 'woothemes' )
				),
				'description' => array(
					'title' => __( 'Description', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'This is the message box that will appear on the checkout page when they select Paychant.', 'woothemes' ),
					'default' => __('You will be redirected to the Paychant Payment Gateway and pay in cryptocurrency of your choice.', 'woothemes' )
				),
				'environment' => array(
					'title' => __('Environment', 'woo-paychant'),
					'type' => 'select',
					'description' => __('Choose your environment. Default ( Production). The Test (Sandbox) should be used for testing only. It should not be used when you are ready to accept real payment.', 'woo-paychant'),
					'default' => 'production',
					'desc_tip' => false,
					'options' => array(
						'production' => __('Live (Production)', 'woo-paychant'),
						'sandbox' => __('Test (Sandbox)', 'woo-paychant'),
					),
				),
				'live_api_key'           => array(
					'title'       => __( 'Live API token', 'woo-paychant' ),
					'type'        => 'text',
					'desc_tip'    => false,
					'description' => __( 'Get you Live API token from your Paychant Merchant Dashboard, Please Provide this. If the Environment is set to Production and this is not set,  Paychant payment will not work.', 'woocommerce' ),
				),
				
				'test_api_key'           => array(
					'title'       => __( 'Sandbox API token', 'woo-paychant' ),
					'type'        => 'text',
					'desc_tip'    => false,
					'description' => __( 'This is for testing only, it should not be used whe you are ready to accept real payment. Get you Sandbox API token key from your Paychant Merchant Dashboard, This will be used if the Environment is changed to Sandbox. ' ,'woocommerce' ),
				),
				'checkout_method' => array(
					'title' => __('Checkout Method', 'woo-paychant'),
					'type' => 'select',
					'description' => __('Determine if Paychant interface should pop-up inline, or redirect to complete transaction.', 'woo-paychant'),
					'default' => 'popup',
					'desc_tip' => false,
					'options' => array(
						'popup' => __('Pop-up', 'woo-paychant'),
						'redirect' => __('Redirect', 'woo-paychant'),
					),
				),
	
			);			
		}

		public function payment_fields(){
			if(!$this->description)$more='';
			else $more= wpautop(wptexturize($this->description));
			//$my_icon='<img src="'.$this->icon_alt.'" style="display:inline-block;" />';
			//add custom-inline form-data if applicable 
			
			if($this->redirect_method)echo "<div class=''>$more</div>";
			else{?>
			<script src='https://inline.paychant.com/v1'></script>
			<script>
			var paychant_external=document.getElementById('paychant_external');
			var paychant_processing = false;

			window.addEventListener('load',function(){
				jQuery( 'form.checkout' ).on( 'checkout_place_order', function() {
					var isSelectedMethod = jQuery('#payment_method_paychant').is(':checked');

					if(isSelectedMethod){
						if(paychant_processing){ 
							//console.log('Paychant already processing');
							return false;
						}
						paychant_processing=true;
						
						var resp_div = jQuery('#paychant_checkout_response');
						resp_div.html("Placing order, please wait..")
						
						var temp_url = '<?php echo home_url(); ?>/?wc-ajax=checkout';
						var temp_data=jQuery('form.woocommerce-checkout').serializeArray();
						var form_data = {}
						for (var i = 0; i < temp_data.length; i++){
							form_data[temp_data[i]['name']] = temp_data[i]['value'];
						}						
						
						jQuery.ajax({
							url: temp_url,
							type: 'POST',
							data: form_data,
							success: function(resp) {
								//console.log(resp);							
								if(resp.messages)resp_div.html(resp.messages);
								if(resp.result != 'success')paychant_processing=false; //failure
								else {
									//if(resp.paychantData){ //must be.
										resp_div.html('Order initiated, please proceed to payment.');
										PaychantPay(resp);
									//}
								}
							},
							error: function(xhr) {
								console.log("AJAX error initiating order.",xhr);
								var errorMessage = xhr.status + ': ' + xhr.statusText
								resp_div.html("Error "+errorMessage);
								paychant_processing=false;
							}
						});
					}
					
					return !isSelectedMethod;
				});
			});
			var PAYCHANT_REDIRECTING=true;
			function PaychantPay(params) {
				var paychant_configs=params.paychantData;
				paychant_configs.callback=function (response){
					paychant_processing = false;
					console.log('paychant callback:',response);
					if(response.status!='pending'&&response.order_id){
						var callback_url=params.callback_url;
						if(callback_url.indexOf('?')==-1)callback_url+='?paychant_order_id='+response.order_id;
						else callback_url+='&paychant_order_id='+response.order_id;
						PAYCHANT_REDIRECTING=true;
						window.location.href=callback_url; //not success_url
					}
				}
				paychant_configs.onClose=function () {
					paychant_processing = false;
					console.log('Payment process cancelled',params);
					if(!PAYCHANT_REDIRECTING)window.location.href=params.cancel_url;
				}
				
				var handler = PaychantWindow.setup(paychant_configs);
				
				handler.open();
			}
			</script>			
		<?php
			echo "<div>$more</div>
			<div id='paychant_checkout_response'></div>";
			
			}
		}
		
		public function process_payment($order_id){
			global $woocommerce; global $wpdb;
			$order = new WC_Order($order_id);	
			//Validate custom-inline form-data here, if posted. Returns redirect url. 
			
			$email=$order->get_billing_email();
			$firstname=$order->get_billing_first_name();
			$lastname=$order->get_billing_last_name();
			$customer_phone=$order->get_billing_phone();
			if(empty($customer_phone))$customer_phone='';
			
			$order_number=$order->get_order_number();
			$amountTotal=floatval(number_format($order->calculate_totals(),2,'.',''));
			$woocommerce_currency = get_woocommerce_currency();
			$payment_url=$order->get_checkout_payment_url(); 
			$order_title="Order: #$order_number - ".get_option('blogname');
				
			$order_items = $order->get_items();
			foreach($order_items as $item ){
				$products_item_line = implode(' x ',array($item->get_quantity(),$item->get_name()));
				$product_items[] = $products_item_line;
			}
			$order_description=implode(', ',$product_items);

			$cancel_url = $order->get_cancel_order_url();
			$success_url = $this->get_return_url($order);
			$callback_url=WC()->api_request_url(get_class($this));
			
			$cancel_url=html_entity_decode($cancel_url);
			$success_url=html_entity_decode($success_url);
			$callback_url=html_entity_decode($callback_url);

			//--------------- Paychant Transaction Initiation 	
			$url=$this->testmode?'https://api-sandbox.paychant.com/v1/order':'https://api-live.paychant.com/v1/order';
			$ddate=date('YmdHis');
			$transRef="$order_id.$ddate";
			$body = array(
				'amount' => $amountTotal,
				'currency' => $woocommerce_currency,
				'title' => substr($order_title,0,50),
				'description' => substr($order_description,0,100),
				'payer_info' => $email,
				'cancel_url' => $cancel_url,
				'success_url' => $success_url,
				'callback_url' => $callback_url,
				'token' => $transRef,
				'plugin' => 'WORDPRESS',
			);

			$arg = array(
				'body' => $body,'timeout'=>50,'redirection'=>5,
				'headers'=>array('Authorization'=>"Token {$this->api_key}")
			);
			$response = wp_remote_post($url, $arg);
			
			if(is_wp_error($response)) {
				$error_message = $response->get_error_message();			
				$this->_log_stuff("$error_message\n\n".json_encode($body,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
				return wc_add_notice("Error initiating payment at Paychant: $error_message",'error');
			}
			else {
				$json = @json_decode($response['body'],true);
				if(empty($json))return wc_add_notice("Error interpreting paychant initiation response: {$response['body']}",'error');
				elseif(empty($json['order']['payment_url'])){
					if(!empty($json['errors']))$error_message=json_encode($json['errors']);
					else $error_message=json_encode($json);
					
					return wc_add_notice("Paychant transaction initiation error: $error_message",'error');
				}
				else {
					$payment_url=$json['order']['payment_url'];
				}
			}		
			//-------------------
			
			
			$order->set_payment_method($this);
			$order->update_status('pending', __("Paychant payment pending.", 'woothemes'));		
		   //----------------------------------
		   
			if(stristr($callback_url,'?'))$alt_callback_url="$callback_url&trans_ref=$transRef";
			else $alt_callback_url="$callback_url?trans_ref=$transRef";
			
		   if(false){
				$mail_headers = "MIME-Version: 1.0"."\r\n";
				//$mail_headers .= "Content-Type: text/html; charset=\"iso-8859-1\""."\r\n";
				//$mail_message=str_replace(array("\r\n", "\n\r", "\r", "\n",'\r\n', '\n\r', '\r', '\n',),"<br/>",$mail_message);
				$mail_headers .= "X-Priority: 1 (Highest)"."\r\n";
				$mail_headers .= "X-MSMail-Priority: High"."\r\n";
				$mail_headers .= "Importance: High"."\r\n";
			
				$domain=$_SERVER['HTTP_HOST'];
				if(substr($domain,0,4)=='www.')$domain=substr($domain,4);
				$site_name=get_option('blogname');
				$mail_from="$site_name<no-reply@$domain>";
				$customer_fullname=ucwords(strtolower("$firstname $lastname"));
				
				$transaction_date=date('jS M. Y g:i a');
				$mail_message="Hello $customer_fullname\r\n\r\nHere are the details of your transaction:\r\n\r\nORDER: #$order_number\r\nAMOUNT: $amountTotal $woocommerce_currency \r\nDATE: $transaction_date\r\n\r\nYou can always confirm your transaction/payment status at $order_received_url\r\n\r\nRegards.";
				@mail($email,"Transaction Information",$mail_message,"From: $mail_from");
			}
			//-----------------------------
			
			if(!empty($notice))wc_add_notice($notice,'notice'); //only applicable when not redirecting externally
			
			$paychantData=array(
				'env'=>$this->testmode?'sandbox':'live',
				'title'=>substr($order_title,0,50),
				'amount'=>$amountTotal,
				'currency'=>$woocommerce_currency,
				'api_token'=>$this->api_key,
				'payer_info'=>$email,
				'description'=>substr($order_description,0,100),
				//'exclude_payment_currencies'=>['BTC', 'ETH'],
				
				'cancel_url' => $cancel_url,
				'success_url' => $success_url,
				'callback_url' => $alt_callback_url,
			);
			
			$resp=array('result'=>'success','redirect'=>$payment_url,
			'paychantData'=>$paychantData,'cancel_url'=>$cancel_url,'callback_url'=>$alt_callback_url);
			return $resp;
		}		
		
		public function ipn_callback(){
			//$order_received_url=$order->get_checkout_order_received_url();
			if(isset($_POST['token']))$temp_token=sanitize_text_field($_POST['token']); // 'amount', 'currency'
			elseif(isset($_GET['trans_ref']))$temp_token=sanitize_text_field($_GET['trans_ref']);
			
			if(isset($_POST['order_id']))$paychant_orderid=sanitize_text_field($_POST['order_id']);
			elseif(isset($_GET['paychant_order_id']))$paychant_orderid=sanitize_text_field($_GET['paychant_order_id']); //invalid
			
			if(!empty($temp_token)) {
				$temp_token=explode('.',$temp_token,2);
				$order_id=$temp_token[0];
				
				//---------------------------------------
				$order = wc_get_order($order_id);
				if(empty($order)){
					$error="IPN Received for non-existent order ID: $order_id.";
					$this->_log_stuff("$error\n\n".json_encode($_POST,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
				}
				elseif($order->has_status('completed')){ //||$order->has_status('processing')
					//$error="This order is currently being processed or completed.";
					$temp_url=$order->get_checkout_order_received_url();
					//$temp_url = this->get_return_url($order);
					wp_redirect($temp_url);	 exit;
				}
				elseif(!empty($paychant_orderid)) {
					$url=$this->testmode?"https://api-sandbox.paychant.com/v1/order/$paychant_orderid": "https://api-live.paychant.com/v1/order/$paychant_orderid";
					$arg = array(
						'timeout'=>50,'redirection'=>5,					
						'headers'=>array('Authorization'=>"Token {$this->api_key}")
					);
					$response = wp_remote_get($url, $arg);
					
					if(is_wp_error($response)) {
						$error_message = $response->get_error_message();
						$error="Error verifying payment at Paychant: $error_message";
					}
					else {
						$json = @json_decode($response['body'],true);
						if(empty($json))$error="Error interpreting Paychant verification response: {$response['body']}";
						elseif(@$json['status']!='success'){
							if(!empty($json['message']))$error="Unable to verify payment from Paychant: {$json['message']}";
							else $error="Error verifing payment from Paychant: {$response['body']}";
						}
						else {
							$porder=$json['order'];
							$amount_paid=floatval($porder['amount']);
							$paid_currency=$porder['currency'];
							$pstatus=$porder['status'];
						}
						
						if(!empty($error))$this->_log_stuff("$error\n\n$url\n".json_encode($_POST,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
					}		
					
					$order_total = floatval($order->get_total());
					$order_currency = method_exists($order,'get_currency')?$order->get_currency():$order->get_order_currency();
				}
				//-------------------------------------------
				

				if(!empty($error))wc_add_notice($error,'error');	
				elseif(!empty($porder)){
					$status_update_info=''; $failed=false;
					
					if($amount_paid<$order_total) {
						$failed=true;
						$status_update_info='Amount paid is less than the total order amount.';
						$notice = "Thank you for shopping with us.Your payment transaction was successful, but the amount paid is not the same as the total order amount.Your order is currently on hold.Kindly contact us for more information regarding your order and payment status.";
						$admin_order_note = "<strong>Look into this order</strong>This order is currently on hold. Amount paid is less than the total order amount.";
					}
					elseif($paid_currency!=$order_currency) {
						$failed=true;
						$status_update_info='Order currency is different from the payment currency.';
						$notice = "Thank you for shopping with us.Your payment was successful, but the payment currency is different from the order currency.";
						$admin_order_note = "<strong>Look into this order</strong>This order is currently on hold.Reason: Order currency is different from the payment currency.";
					}
					elseif($pstatus=='expired'){
						$failed=true;
						$notice = 'You have not paid within the required time (30 minutes)';
						$admin_order_note = 'Payment expired';
					}
					elseif($pstatus=='canceled'){
						$failed='cancelled';
						$notice = 'You canceled the order from Paychant please go back to finish your payment';
						$admin_order_note = 'Payment cancelled';
					}
					elseif($pstatus=='pending'){
						$notice = 'You have selected a payment method but you have not made payment by Paychant';
						$admin_order_note = 'Checkout is awaiting payment.';
					}
					elseif($pstatus=='new'){
						$notice = 'Invoice Created but you have not selected a payment method';
						$admin_order_note = 'Newly created invoice. The customer has not yet selected a payment currency';
					}
					elseif($pstatus=='paid'){
						$notice=$admin_order_note='Payment completed'; 
						$status_update_info='Payment has been complete by Paychant'; 
					}
					else {
						$this->_log_stuff("Un-handled order status $pstatus\n".json_encode($porder,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
					}
					
					if($failed)$order->update_status(is_string($failed)?$failed:'failed', $status_update_info);
					elseif($pstatus=='pending'){} //$order->update_status('pending', $status_update_info); 
					elseif($pstatus=='paid')$order->update_status( 'completed', $status_update_info);
					else $order->update_status('on-hold', $status_update_info);
					
					$order->add_order_note($notice,true); //for customer
					$order->add_order_note($admin_order_note);

					if($failed){
						wc_add_notice($notice, 'error');
						$this->_log_stuff("$notice\n".json_encode($porder,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
					}
					elseif($pstatus=='paid'){
						$order->payment_complete($paychant_orderid);
						function_exists('wc_reduce_stock_levels')?wc_reduce_stock_levels($order_id):$order->reduce_order_stock();
						wp_redirect($order->get_checkout_order_received_url());	 exit;
					}
					else wc_add_notice($notice, 'notice');
				}
			}
			
			wp_redirect(wc_get_page_permalink('cart'));	
		}
	
		private function _log_stuff($str){
			$ddate=date('jS M. Y g:ia');
			file_put_contents(__DIR__ .'/debug.log',"$ddate\n$str\n---------------\n",FILE_APPEND); 
		}		
	}

   add_filter( 'woocommerce_payment_gateways','paychant_add_to_woo');
}

function paychant_add_to_woo($methods){
	$methods[] = 'WC_Gateway_Paychant';
	return $methods;
}

add_filter((is_network_admin()?'network_admin_':'') .'plugin_action_links_'.plugin_basename(__FILE__),
	'paychant_action_links');
function paychant_action_links($links){
    $admin_settings_link = array(
    	'settings' => '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section='.PAYCHANT_PLUGIN_ID ).'" title="Paychant Settings">Settings</a>'
    );
    return array_merge($links,$admin_settings_link);
}
