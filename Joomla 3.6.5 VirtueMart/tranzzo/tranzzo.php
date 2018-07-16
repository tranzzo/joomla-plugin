<?php
defined ('_JEXEC') or die('Restricted access');

/**
 * @author TRANZZO
 * @version v 1.0
 * @link: https://tranzzo.com
 */
if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentTranzzo extends vmPSPlugin
{
	function __construct (& $subject, $config)
	{
		parent::__construct ($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 */
	public function getVmPluginCreateTableSQL ()
	{
		return $this->createTableSQL ('Payment TRANZZO Table');
	}

	/**
	 * Fields to create the payment table
	 *
	 * @return string SQL Fileds
	 */
	function getTableSQLFields ()
	{
		$SQLfields = array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'virtuemart_order_number' 	  => 'char(64) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => 'int(1) UNSIGNED',
			'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
            'tranzzo_payment_id' 		  => 'char(64) DEFAULT NULL',
            'tranzzo_transaction_id' 	  => 'char(64) DEFAULT NULL',
		);

		return $SQLfields;
	}

	function plgVmConfirmedOrder ($cart, $order)
	{
		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return false;
		}

		if (!class_exists ('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}

		$this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);

		$currency_id = $method->currency_id ? $method->currency_id : $method->payment_currency;
		$currency_code_3 = shopFunctions::getCurrencyByID($currency_id, 'currency_code_3');

		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$currency_id);

		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['payment_currency'] = $currency_code_3;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];

		require_once(__DIR__ . '/TranzzoApi.php');

		$tranzzo = new TranzzoApi($method->POS_ID, $method->API_KEY, $method->API_SECRET, $method->ENDPOINTS_KEY);

		$params = array();
		$tranzzo->setServerUrl(
				JROUTE::_(JURI::root() . "index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&on={$this->_getOrderNumber($order)}&pm={$this->_getPaymentMethodId($order)}")
		);
		$tranzzo->setResultUrl(
				JRoute::_(JURI::root() . "index.php?option=com_virtuemart&view=orders&layout=details&order_number={$this->_getOrderNumber($order)}&order_pass={$this->_getOrderPass($order)}", false)
		);
		$tranzzo->setOrderId($this->_getOrderId($order));
		$tranzzo->setAmount($totalInPaymentCurrency['value']);
		$tranzzo->setCurrency($currency_code_3);
		$tranzzo->setDescription("Order #{$this->_getOrderId($order)}");

		if(!empty($order['details']['BT']->virtuemart_user_id))
			$customer_id = $order['details']['BT']->virtuemart_user_id;
		else
			$customer_id = !empty($order['details']['BT']->email)? $order['details']['BT']->email : 'unregistered';

		$tranzzo->setCustomerId($customer_id);

		$tranzzo->setCustomerEmail($order['details']['BT']->email);

		$tranzzo->setCustomerFirstName($order['details']['BT']->first_name);

		$tranzzo->setCustomerLastName($order['details']['BT']->last_name);

		$tranzzo->setCustomerPhone($order['details']['BT']->phone_1);

		$tranzzo->setProducts();

		if(count($order['items']) > 0) {
			$products = array();
			foreach ($order['items'] as $index => $item) {
				$product_currency = shopFunctions::getCurrencyByID($item->allPrices[0]['product_currency'], 'currency_code_3');
				$product = array(
					'id' => strval($item->virtuemart_product_id),
					'name' => $item->order_item_name,
					'currency' => $product_currency,
					'amount' => TranzzoApi::amountToDouble($item->product_final_price * $item->product_quantity),
					'qty' => intval($item->product_quantity),
				);
				if($cart->products[$index]->virtuemart_product_id == $item->virtuemart_product_id){
					$url = !empty($cart->products[$index]->url)? JROUTE::_(JURI::root() . ltrim($cart->products[$index]->url, '/')) : '';

					if(!empty($url)) $product['url'] = $url;
				}

				$products[] = $product;
			}

			$tranzzo->setProducts($products);
		}

		$response = $tranzzo->createPaymentHosted();

		$html = $this->renderByLayout('post_payment', array(
				'order_number' => $this->_getOrderNumber($order),
				'order_pass' => $this->_getOrderPass($order),
				'payment_name' => $method->payment_name,
				'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display']
		));

		if(!empty($response['redirect_url'])) {
			$this->storePSPluginInternalData($dbValues);
			$cart->emptyCart();
			$html .= '<p>' . vmText::_('VMPAYMENT_TRANZZO_CHECKOUT_TEXT_PAY') . '</p><br>';
			$html .= '<a class="tranzzo button" href="' . $response['redirect_url'] . '">' . vmText::_('VMPAYMENT_TRANZZO_CHECKOUT_TEXT_BUTTON') . '</a>';
		}
		$html.= !empty($response['message'])? "<p>{$response['message']}</p>" : '';
		$html.= '<p>' . ((!empty($response['args']) && is_array($response['args']))? ', args: ' . implode(', ', $response['args']) : '') . '</p>';

		vRequest::setVar ('html', $html);
		return true;
	}

	/**
	 * This event is fired when the  method notifies you when an event occurs that affects the order.
	 * Typically,  the events  represents for payment authorizations, Fraud Management Filter actions and other actions,
	 * such as refunds, disputes, and chargebacks.
	 */
	public function plgVmOnPaymentNotification()
	{
		$data = $_POST['data'];
		$signature = $_POST['signature'];
		if(empty($data) && empty($signature)) die('LOL! Bad Request!!!');

		require_once(__DIR__ . '/TranzzoApi.php');

		$data_response = TranzzoApi::parseDataResponse($data);
		$order_id = (int)$data_response[TranzzoApi::P_RES_PROV_ORDER_ID];

		$q = 'SELECT `virtuemart_paymentmethod_id` FROM `' . $this->_tablename . '` WHERE `virtuemart_order_id`="' . $order_id . '"';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$virtuemart_paymentmethod_id = $db->loadResult();

		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}

		$tranzzo = new TranzzoApi($method->POS_ID, $method->API_KEY, $method->API_SECRET, $method->ENDPOINTS_KEY);

		if($tranzzo -> validateSignature($data, $signature) && $order_id) {
			$amount_payment = TranzzoApi::amountToDouble($data_response[TranzzoApi::P_REQ_AMOUNT]);

			$q = 'SELECT `payment_order_total` FROM `' . $this->_tablename . '` WHERE `virtuemart_order_id`="' . $order_id . '"';
			$db2 = JFactory::getDBO();
			$db2->setQuery($q);
			$amount_order = TranzzoApi::amountToDouble($db2->loadResult());

			if ($data_response[TranzzoApi::P_RES_RESP_CODE] == 1000 && ($amount_payment >= $amount_order)) {

				$modelOrder = VmModel::getModel('orders');
				$order['order_status'] = $method->status_success;
				$order['customer_notified'] = 1;
				$order['comments'] = vmText::_('VMPAYMENT_TRANZZO_PAYMENT_SUCCESS')
						. vmText::_("VMPAYMENT_TRANZZO_ID_PAYMENT") . $data_response[TranzzoApi::P_RES_PAYMENT_ID]
						. vmText::_("VMPAYMENT_TRANZZO_ID_TRANSACTION") . $data_response[TranzzoApi::P_RES_TRSACT_ID];
				$modelOrder->updateStatusForOneOrder($order_id, $order, TRUE);

				$q = 'UPDATE `' . $this->_tablename . '` SET
				tranzzo_payment_id = "' . $data_response[TranzzoApi::P_RES_PAYMENT_ID] . '",
				tranzzo_transaction_id = "' . $data_response[TranzzoApi::P_RES_TRSACT_ID] . '"
				WHERE `virtuemart_order_id`="' . $order_id . '"';
				$db3 = JFactory::getDBO();
				$db3->setQuery($q);
				$db3->execute();

				exit;
			}

			$modelOrder = VmModel::getModel('orders');
			$order['order_status'] = $method->status_failed;
			$order['customer_notified'] = 1;
			$order['comments'] = vmText::_('VMPAYMENT_TRANZZO_PAYMENT_FAILED');
			$modelOrder->updateStatusForOneOrder($order_id, $order, TRUE);

			exit;
		}

		return null;
	}

	private function _getOrderNumber($order)
	{
		return $order['details']['BT']->order_number;
	}

	private function _getOrderPass($order)
	{
		return $order['details']['BT']->order_pass;
	}

	private function _getOrderId($order)
	{
		return $order['details']['BT']->virtuemart_order_id;
	}

	private function _getPaymentMethodId($order)
	{
		return $order['details']['BT']->virtuemart_paymentmethod_id;
	}

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 *
	 * @author: Valerie Isaksen
	 *
	 * @param $cart_prices: cart prices
	 * @param $payment
	 * @return true: if the conditions are fulfilled, false otherwise
	 *
	 */
	protected function checkConditions($cart, $method, $cart_prices)
	{
		$currency_id = $method->currency_id ? $method->currency_id : $method->payment_currency;
		$currency_code_3 = shopFunctions::getCurrencyByID($currency_id, 'currency_code_3');
		if (!in_array($currency_code_3, array('USD', 'EUR', 'UAH', 'RUB'))) {
			return false;
		}

		$this->convert_condition_amount($method);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		if($this->_toConvert){
			$this->convertToVendorCurrency($method);
		}

		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		// probably did not gave his BT:ST address
		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
			return TRUE;
		}

		return FALSE;
	}

	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 */
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg)
	{
		return $this->OnSelectCheck($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 */
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}

		$this->getPaymentCurrency ($method);

		$paymentCurrencyId = $method->payment_currency;
		return;
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
	{
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 */
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
	{
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	function plgVmOnUserInvoice($orderDetails, &$data)
	{
		if (!($method = $this->getVmPluginMethod ($orderDetails['virtuemart_paymentmethod_id']))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return NULL;
		}

		if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null==1 or $orderDetails['order_total'] > 0.00){
			return NULL;
		}

		if ($orderDetails['order_salesPrice']==0.00) {
			$data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Nerver send the invoice via email
		}

	}

	function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data)
	{
		return $this->declarePluginParams('payment', $data);
	}

	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	static function writeLog($data, $flag = '', $filename = 'info')
	{
		file_put_contents(__DIR__ . "/{$filename}.log", "\n\n" . date('H:i:s') . " - $flag \n" .
				(is_array($data)? json_encode($data, JSON_PRETTY_PRINT):$data)
				, FILE_APPEND);
	}
}