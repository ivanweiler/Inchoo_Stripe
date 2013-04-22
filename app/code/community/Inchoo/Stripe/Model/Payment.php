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

	//protected $_formBlockType = 'stripe/form';
    //protected $_infoBlockType = 'payment/info';
	
    protected $_isGateway                   = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = true;
	protected $_canRefund                   = true;

    protected $_supportedCurrencyCodes = array('GBP');
    protected $_minOrderTotal = 1;
    protected $_canRefundInvoicePartial = true;
    
    public function __construct()
    {
		Stripe::setApiKey($this->getConfigData('api_key'));
    }    
        
    public function capture(Varien_Object $payment, $amount)
    {
    	$order = $payment->getOrder();
    	$billing = $order->getBillingAddress();



    	$stripeCustomer  = Mage::getModel('customer/customer')->load($order->getCustomerId());
    	$stripeCustomerId = $stripeCustomer->getStripe();

    	

    	try {

    		if(strlen($stripeCustomerId) == 0){
	    		$customer = Stripe_Customer::create(array(
					'email'	=> $order->getCustomerEmail(),
					'description'	=> "New Customer " . $order->getCustomerEmail() . ' - ' . $order->getCustomerId(),
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
	    			Mage::helper('advancedcompare')->saveStripe($order->getCustomerId(),$customer->id);
	    			$stripeCustomerId = $customer->id;
    		}

    		// Check if we have new card details if so then update customer object.
    		$Stripe_Customer = Stripe_Customer::retrieve($stripeCustomerId);
    		$Stripe_Card = $Stripe_Customer->__get('active_card');
    		if($Stripe_Card->last4 !== substr($payment->getCcNumber(), -4) && strlen($payment->getCcCid()) > 2){
               

    			$Stripe_Customer->card = array(
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
					);
                Mage::log($Stripe_Customer->card );
    			$Stripe_Customer->save();
    		}

    		if($amount > $this->_minOrderTotal )
    		{

				$charge = Stripe_Charge::create(array(
				  "amount" => $amount*100, # amount in cents, again
				  "currency" => "gbp",
				  "customer" => $stripeCustomerId ,
				  "description"	=>	sprintf('#%s, %s', $order->getIncrementId(), $order->getCustomerEmail())
				  )
				);

				Mage::log($charge,null,'stripe.log',true);
		
		        $payment
		        	->setTransactionId($charge->id)
		        	->setIsTransactionClosed(0);

			}        	
    	

		} catch (Exception $e) {
			$this->debugData("Payment Error - Stripe - " . $e->getMessage());
			Mage::log($e->getMessage());
			Mage::throwException($e->getMessage());
			//Mage::throwException(Mage::helper('paygate')->__('Payment capturing error.'));
		}
		
		
		
        return $this;
    }
    
    public function refund(Varien_Object $payment, $amount)
    {
    	$transactionId = $payment->getParentTransactionId();

		try {
			
			Stripe_Charge::retrieve($transactionId)->refund(array(
              'amount'  => $amount*100
            ));
            
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

    public function hasStripeId($customerId){

    
    	$stripeCustomer  = Mage::getModel('customer/customer')->load($customerId);
    	$stripeCustomerId = $stripeCustomer->getStripe();

  		if(strlen($stripeCustomerId) == 0){
  			return false;
  		}else{
  			return $stripeCustomerId;
  		}

    }

    public function getStripeId(){
    	$info = $this->getInfoInstance();
    	$quote = $info->getQuote();
    	
    	if ( $stripeCustomerId = $this->hasStripeId($quote->getCustomerId()) ){
    		return  $stripeCustomerId;
    	}else {
    		return '';
    	}
    }

    public function hasValidCardOnStipe($stripeCustomerId){

    	$Stripe_Customer = Stripe_Customer::retrieve($stripeCustomerId );
    	if($Stripe_Customer->__get('active_card')){
    			return true;
    		}else{
    			return false;
    	}
    } 

    public function hasStripeSavedCard(){
    	
    	$info = $this->getInfoInstance();
    	$quote = $info->getQuote();
    	
    	if ( $stripeCustomerId = $this->hasStripeId($quote->getCustomerId()) ){
    		
    		//$nocardCustomer = 'cus_1bCJS8173boTGj';
   
    		if($this->hasValidCardOnStipe($stripeCustomerId)){
    			return true;
    		}else{
    			echo " This Customer has no active Card on their Stripe Account.";
    			return false;
    		}
    	}else{
    		return false;
    	}
    }

    public function getStripeCardDetails(){
    	$info = $this->getInfoInstance();
    	$quote = $info->getQuote();
    	$card = array();
    	
    	if ( $stripeCustomerId = $this->hasStripeId($quote->getCustomerId()) ){
    		
    		if($this->hasValidCardOnStipe($stripeCustomerId)){
    			$Stripe_Customer = Stripe_Customer::retrieve($stripeCustomerId );
			    	if($Stripe_Customer->__get('active_card')){
			    			return $Stripe_Customer->__get('active_card')->__toArray();
			    		}else{
			    			return $card;
			    	}
    		}else{
    			return $card;
    		}
    	}else{
    		return $card;
    	}
    }

    private function _isPlaceOrder()
    {
        $info = $this->getInfoInstance();
        if ($info instanceof Mage_Sales_Model_Quote_Payment) {
            return false;
        } elseif ($info instanceof Mage_Sales_Model_Order_Payment) {
            return true;
        }
    }


    
	public function isAvailable($quote = null)
    {


    	//if($quote && $quote->getBaseGrandTotal()<$this->_minOrderTotal) {
    		//return false;
    	//}
    	return true;
    	
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

    public function isAdmin(){
    	Mage::getSingleton('core/session', array('name'=>'adminhtml'));
    	if(Mage::getSingleton('admin/session')->isLoggedIn()){
  			return true;
		}
		else
		{
		    return false;
		}
    }

    public function validate()
    {
       

    	if($this->isAdmin() == false ){
   	 	

        /*
        * calling parent validate function
        */
        parent::validate();

        $info = $this->getInfoInstance();
        $errorMsg = false;
        $availableTypes = explode(',',$this->getConfigData('cctypes'));

        $ccNumber = $info->getCcNumber();
    	
       
    	

        // remove credit card number delimiters such as "-" and space
        $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);
        $info->setCcNumber($ccNumber);

        $ccType = '';

        if (in_array($info->getCcType(), $availableTypes)){
            if ($this->validateCcNum($ccNumber)
                // Other credit card type number validation
                || ($this->OtherCcType($info->getCcType()) && $this->validateCcNumOther($ccNumber))) {

                $ccType = 'OT';
                $ccTypeRegExpList = array(
                    //Solo, Switch or Maestro. International safe
                    /*
                    // Maestro / Solo
                    'SS'  => '/^((6759[0-9]{12})|(6334|6767[0-9]{12})|(6334|6767[0-9]{14,15})'
                               . '|(5018|5020|5038|6304|6759|6761|6763[0-9]{12,19})|(49[013][1356][0-9]{12})'
                               . '|(633[34][0-9]{12})|(633110[0-9]{10})|(564182[0-9]{10}))([0-9]{2,3})?$/',
                    */
                    // Solo only
                    'SO' => '/(^(6334)[5-9](\d{11}$|\d{13,14}$))|(^(6767)(\d{12}$|\d{14,15}$))/',
                    'SM' => '/(^(5[0678])\d{11,18}$)|(^(6[^05])\d{11,18}$)|(^(601)[^1]\d{9,16}$)|(^(6011)\d{9,11}$)'
                            . '|(^(6011)\d{13,16}$)|(^(65)\d{11,13}$)|(^(65)\d{15,18}$)'
                            . '|(^(49030)[2-9](\d{10}$|\d{12,13}$))|(^(49033)[5-9](\d{10}$|\d{12,13}$))'
                            . '|(^(49110)[1-2](\d{10}$|\d{12,13}$))|(^(49117)[4-9](\d{10}$|\d{12,13}$))'
                            . '|(^(49118)[0-2](\d{10}$|\d{12,13}$))|(^(4936)(\d{12}$|\d{14,15}$))/',
                    // Visa
                    'VI'  => '/^4[0-9]{12}([0-9]{3})?$/',
                    // Master Card
                    'MC'  => '/^5[1-5][0-9]{14}$/',
                    // American Express
                    'AE'  => '/^3[47][0-9]{13}$/',
                    // Discovery
                    'DI'  => '/^6011[0-9]{12}$/',
                    // JCB
                    'JCB' => '/^(3[0-9]{15}|(2131|1800)[0-9]{11})$/'
                );

                foreach ($ccTypeRegExpList as $ccTypeMatch=>$ccTypeRegExp) {
                    if (preg_match($ccTypeRegExp, $ccNumber)) {
                        $ccType = $ccTypeMatch;
                        break;
                    }
                }

                if (!$this->OtherCcType($info->getCcType()) && $ccType!=$info->getCcType()) {
                    $errorMsg = Mage::helper('payment')->__('Credit card number mismatch with credit card type.');
                }
            }
            else {
                $errorMsg = Mage::helper('payment')->__('Invalid Credit Card Number');
            }

        }
        else {
            $errorMsg = Mage::helper('payment')->__('Credit card type is not allowed for this payment method.');
        }

        //validate credit card verification number
        if ($errorMsg === false && $this->hasVerification()) {
            $verifcationRegEx = $this->getVerificationRegEx();
            $regExp = isset($verifcationRegEx[$info->getCcType()]) ? $verifcationRegEx[$info->getCcType()] : '';
            if (!$info->getCcCid() || !$regExp || !preg_match($regExp ,$info->getCcCid())){
                $errorMsg = Mage::helper('payment')->__('Please enter a valid credit card verification number.');
            }
        }

        if ($ccType != 'SS' && !$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
            $errorMsg = Mage::helper('payment')->__('Incorrect credit card expiration date.');
        }

        if($errorMsg){
        	Mage::log($errorMsg);
            Mage::throwException($errorMsg);
        }

        }

        //This must be after all validation conditions
        if ($this->getIsCentinelValidationEnabled()) {
            $this->getCentinelValidator()->validate($this->getCentinelValidationData());
        }

    	

        return $this;
    }
	
}