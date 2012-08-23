<?php
/**
 * Stripe payment method model
 *
 * @category	Inchoo
 * @package		Inchoo_Stripe
 * @author		Ivan Weiler <ivan.weiler@gmail.com>
 * @copyright	Inchoo (http://inchoo.net)
 * @license		http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

require_once Mage::getBaseDir('lib').DS.'Stripe'.DS.'Stripe.php';

class Inchoo_Stripe_Model_Payment extends Mage_Payment_Model_Method_Cc
{
	protected $_code	=	'inchoo_stripe';
	
    protected $_isGateway                   = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = true;
	protected $_canRefund                   = true;

    protected $_supportedCurrencyCodes = array('USD');
    protected $_minOrderTotal = 0.5;
    
    public function __construct()
    {
		Stripe::setApiKey($this->getConfigData('api_key'));
    }    
        
    public function capture(Varien_Object $payment, $amount)
    {
    	$order = $payment->getOrder();
    	$billing = $order->getBillingAddress();
    	
    	try {
			$charge = Stripe_Charge::create(array(
				'amount'	=> $amount*100,
				'currency'	=> strtolower($order->getBaseCurrencyCode()),
				'card' 		=> array(
					'number'			=>	$payment->getCcNumber(),
					'exp_month'			=>	sprintf('%02d',$payment->getCcExpMonth()),
					'exp_year'			=>	$payment->getCcExpYear(),
					'cvc'				=>	$payment->getCcCid(),
					'name'				=>	$billing->getName(),
					'address_line1'		=>	$billing->getStreet(1),
					'address_line2'		=>	$billing->getStreet(2),
					'address_zip'		=>	$billing->getPostcode(),
					'address_state'		=>	$billing->getRegion(),
					'address_country'	=>	$billing->getCountry(),
				),
				'description'	=>	sprintf('#%s, %s', $order->getIncrementId(), $order->getCustomerEmail())
			));
		} catch (Exception $e) {
			$this->debugData($e->getMessage());
			Mage::throwException(Mage::helper('paygate')->__('Payment capturing error.'));
		}
		
		//Mage::log($charge,null,'stripe.log',true);
		
        $payment
        	->setTransactionId($charge->id)
        	->setIsTransactionClosed(0);
		
        return $this;
    }
    
    public function refund(Varien_Object $payment, $amount)
    {
    	$transactionId = $payment->getParentTransactionId();

		try {
			Stripe_Charge::retrieve($transactionId)->refund();
		} catch (Exception $e) {
			$this->debugData($e->getMessage());
			Mage::throwException(Mage::helper('paygate')->__('Payment refunding error.'));
		}

		$payment
			->setTransactionId($transactionId . '-' . Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND)
			->setParentTransactionId($transactionId)
			->setIsTransactionClosed(1)
			->setShouldCloseParentTransaction(1);	
		
        return $this;
    }    
    
	public function isAvailable($quote = null)
    {
    	if($quote && $quote->getBaseGrandTotal()<$this->_minOrderTotal) {
    		return false;
    	}
    	
        return $this->getConfigData('api_key', ($quote ? $quote->getStoreId() : null))
            && parent::isAvailable($quote);
    }
    
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }
        return true;
    }
	
}