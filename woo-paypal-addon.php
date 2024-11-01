<?php

/**
 * Plugin Name:         Addon for Paypal and WooCommerce
 * Plugin URL:          Addon for Paypal and WooCommerce
 * Description:         Addon for Paypal and WooCommerce allows you to accept payments on your Woocommerce store. It accpets credit card payments and processes them securely with your merchant account.
 * Version:             2.0.2
 * WC requires at least:2.3
 * WC tested up to:     3.8.1
 * Requires at least:   4.0+
 * Tested up to:        5.3.2
 * Contributors:        wp_estatic
 * Author:              Estatic Infotech Pvt Ltd
 * Author URI:          http://estatic-infotech.com/
 * License:             GPLv3
 * @package WooCommerce
 * @category Woocommerce Payment Gateway
 */

define('WPPA_WOO_PAYMENT_DIR', plugin_dir_path(__FILE__));
add_action('plugins_loaded', 'wppa_init_paypal_payment_gateway');

use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Api\Refund;
use PayPal\Api\Sale;

function wppa_init_paypal_payment_gateway()
{
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wppa_add_paypal_addon_action_links');

	function wppa_add_paypal_addon_action_links($links)
	{
		$action_links = array(
			'settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=woo_paypal') . '" title="' . esc_attr(__('View WooCommerce Settings', 'woocommerce')) . '">' . __('Settings', 'woocommerce') . '</a>',
		);
		return array_merge($links, $action_links);
	}

	class WPPA_WC_Paypal_Gateway_EI extends WC_Payment_Gateway
	{
		/**
		 * API Context used for PayPal Authorization
		 * @var null
		 */
		public $apiContext = null;

		/**
		 * Constructor for your shipping class
		 *
		 * @access public
		 * @return void
		 */
		public function __construct()
		{
			$this->id                 	= 'woo_paypal';
			$this->title              	= __('WooCommerce Custom Payment Gateway', 'woo_paypal');
			$this->has_fields = false;
			$this->supports = array('products', 'refunds');

			$title = $this->get_option('paypal_title');
			if (!empty($title)) {
				$this->title = $this->get_option('paypal_title');
			} else {
				$this->title = 'Woo Paypal';
			}

			$method_description = $this->get_option('paypal_description');
			if (!empty($method_description)) {
				$this->method_description = $method_description;
			} else {
				$this->method_description = sprintf(__('Paypal allows you to accept payments on your Woocommerce store. It accpets credit card payments and processes them securely with your merchant account.Please dont forget to test with sandbox account first.', 'woocommerce'));
			}

			$this->get_paypal_sdk();

			$this->init_form_fields();
			$this->init_settings();
			$this->enabled 	= $this->get_option('enabled');

			$this->get_api_context();
			$this->apiContext->setConfig(
				array(
					'log.LogEnabled' => true,
					'log.FileName' => 'PayPal.log',
					'log.LogLevel' => 'DEBUG'
				)
			);

			add_action('check_woopaypal', array($this, 'check_response'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		}

		private function get_paypal_sdk()
		{
			require_once WPPA_WOO_PAYMENT_DIR . 'includes/paypal-sdk/autoload.php';
		}

		public function init_form_fields()
		{
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable', 'woo_paypal'),
					'type' => 'checkbox',
					'label' => __('Enable WooPayPal', 'woo_paypal'),
					'default' => 'yes'
				),
				'client_id' => array(
					'title' => __('Client ID', 'woo_paypal'),
					'type' => 'text',
					'default' => ''
				),
				'client_secret' => array(
					'title' => __('Client Secret', 'woo_paypal'),
					'type' => 'password',
					'default' => ''
				),
			);
		}

		private function get_api_context()
		{
			$client_id =  $this->get_option('client_id');
			$client_secret =  $this->get_option('client_secret');
			$this->apiContext = new ApiContext(new OAuthTokenCredential(
				$client_id,
				$client_secret
			));
		}

		public function process_payment($order_id)
		{
			global $woocommerce;
			$order = new WC_Order($order_id);
			$this->get_api_context();

			$payer = new Payer();
			$payer->setPaymentMethod("paypal");

			$all_items = array();
			$subtotal = 0;
			// Products
			foreach ($order->get_items(array('line_item', 'fee')) as $item) {
				$itemObject = new Item();
				$itemObject->setCurrency(get_woocommerce_currency());
				if ('fee' === $item['type']) {
					$itemObject->setName(__('Fee', 'woo_paypal'));
					$itemObject->setQuantity(1);
					$itemObject->setPrice($item['line_total']);
					$subtotal += $item['line_total'];
				} else {
					$product = $order->get_product_from_item($item);
					$sku = $product ? $product->get_sku() : '';
					$itemObject->setName($item['name']);
					$itemObject->setQuantity($item['qty']);
					$itemObject->setPrice($order->get_item_subtotal($item, false));
					$subtotal += $order->get_item_subtotal($item, false) * $item['qty'];
					if ($sku) {
						$itemObject->setSku($sku);
					}
				}
				$all_items[] = $itemObject;
			}

			$itemList = new ItemList();
			$itemList->setItems($all_items);
		
			$details = new Details();
			$details->setShipping($order->get_total_shipping())
				->setTax($order->get_total_tax())
				->setSubtotal($subtotal);

			$amount = new Amount();
			$amount->setCurrency(get_woocommerce_currency())
				->setTotal($order->get_total())
				->setDetails($details);

			$transaction = new Transaction();
			$transaction->setAmount($amount)
				->setItemList($itemList)
				->setInvoiceNumber(uniqid());

			$baseUrl = $this->get_return_url($order);
			if (strpos($baseUrl, '?') !== false) {
				$baseUrl .= '&';
			} else {
				$baseUrl .= '?';
			}
			$redirectUrls = new RedirectUrls();
			$redirectUrls->setReturnUrl($baseUrl . 'woopaypal=true&order_id=' . $order_id)
				->setCancelUrl($baseUrl . 'woopaypal=cancel&order_id=' . $order_id);

			$payment = new Payment();

			$payment->setIntent("sale")
				->setPayer($payer)
				->setRedirectUrls($redirectUrls)
				->setTransactions(array($transaction));
			try {
				$payment->create($this->apiContext);
				$approvalUrl = $payment->getApprovalLink();
				return array(
					'result' => 'success',
					'redirect' => $approvalUrl
				);
			} catch (Exception $ex) {
				wc_add_notice($ex->getMessage(), 'error');
			}
			return array(
				'result' => 'failure',
				'redirect' => ''
			);
		}

		public function check_response()
		{
			global $woocommerce;
			if (isset($_GET['woopaypal'])) {

				$woopaypal = sanitize_text_field( $_GET['woopaypal'] );
				$order_id = sanitize_text_field( $_GET['order_id'] );
				if ($order_id == 0 || $order_id == '') {
					return;
				}
				
				$order = new WC_Order($order_id);
				if ($order->has_status('completed') || $order->has_status('processing')) {
					return;
				}

				if ($woopaypal == 'true') {
					$this->get_api_context();
					$paymentId = sanitize_text_field( $_GET['paymentId'] );
					$payment = Payment::get($paymentId, $this->apiContext);

					$transaction = new Transaction();
					$amount = new Amount();
					$details = new Details();
					$subtotal = 0;
					// Products
					foreach ($order->get_items(array('line_item', 'fee')) as $item) {
						if ('fee' === $item['type']) {
							$subtotal += $item['line_total'];
						} else {
							$subtotal += $order->get_item_subtotal($item, false) * $item['qty'];
						}
					}

					$details->setShipping($order->get_total_shipping())
						->setTax($order->get_total_tax())
						->setSubtotal($subtotal);

					$amount = new Amount();

					$amount->setCurrency(get_woocommerce_currency())
						->setTotal($order->get_total())
						->setDetails($details);

					$transaction->setAmount($amount);

					$execution = new PaymentExecution();
					$payer_id = sanitize_text_field($_GET['PayerID']);
					$execution->setPayerId($payer_id);

					$execution->addTransaction($transaction);

					try {
						$result = $payment->execute($execution, $this->apiContext);
						$paypal_transaction_id = $result->transactions[0]->related_resources[0]->sale->id;
						$order->update_meta_data('_paypal_transaction_id', $paypal_transaction_id);
					} catch (Exception $ex) {

						wc_add_notice($ex->getMessage(), 'error');
						$order->update_status('failed', sprintf(__('%s payment failed! Transaction ID: %d', 'woocommerce'), $this->title, $paymentId) . ' ' . $ex->getMessage());
						return;
					}
					$order->payment_complete($paymentId);
					$order->add_order_note(sprintf(__('%s payment approved! Trnsaction ID: %s', 'woocommerce'), $this->title, $paymentId));
					$woocommerce->cart->empty_cart();
				}
				if ($woopaypal == 'cancel') {
					$order = new WC_Order($order_id);
					$order->update_status('cancelled', sprintf(__('%s payment cancelled! Transaction ID: %d', 'woocommerce'), $this->title, $paymentId));
				}
			}
			return;
		}

		/**
		 *
		 * @param type $order_id
		 * @param type $amount
		 * @param type $reason
		 * @return boolean
		 */
		public function process_refund($order_id, $amount = NULL, $reason = '')
		{
			$this->get_api_context();
			$apiContext = $this->apiContext;

			$currency = get_post_meta($order_id, '_order_currency', true);
			$transction_id = get_post_meta($order_id, '_paypal_transaction_id', true);

			$amt = new Amount();
			$amt->setTotal($amount)
				->setCurrency($currency);

			$refund = new Refund();
			$refund->setAmount($amt);

			$sale = new Sale();
			$sale->setId($transction_id);

			try {
				$refundedSale = $sale->refund($refund, $apiContext);
				$rtimestamp = date('Y-m-d H:i:s');
				$wc_order = new WC_Order($order_id);
				$wc_order->add_order_note(__('Paypal Refund completed at. ' . $rtimestamp . ' with Refund ID = ' . $refundedSale->getId(), 'woocommerce'));
				return true;
			} catch (PayPal\Exception\PayPalConnectionException $ex) {
				echo "<pre>";
				print_r($ex->getCode());
				echo "<pre>";
				print_r($ex->getData());
				die($ex);
			} catch (Exception $ex) {
				die($ex);
			}
		}
	}
}
/**
 * Add Gateway class to all payment gateway methods
 */
function wppa_add_paypal_gateway_class_ei($methods)
{
	$methods[] = 'WPPA_WC_Paypal_Gateway_EI';
	return $methods;
}
add_filter('woocommerce_payment_gateways', 'wppa_add_paypal_gateway_class_ei');

add_action('init', 'wppa_check_for_woopaypal');
function wppa_check_for_woopaypal()
{
	if (isset($_GET['woopaypal'])) {
		// Start the gateways
		WC()->payment_gateways();
		do_action('check_woopaypal');
	}
}
