<?php

require_once('stripe/lib/stripe.php');

class Inchoo_Stripe_Block_Creditcard_Management extends Mage_Customer_Block_Account_Dashboard
{
    protected $stripe;

    function __construct()
    {
        parent::__construct();
        $this->setTemplate('stripe/creditcard/index.phtml');
        $this->stripe = Mage::getModel('stripe/paymentmethod');
    }


    function creditCard()
    {
        $result = Mage::registry('stripe_result');
        if (!empty($result))
        {
            $token = ($result->success) ? $result->creditCard->token : $result->params['paymentMethodToken'];
        }
        else
        {
            $token = Mage::app()->getRequest()->getParam('token');
        }
        return $this->stripe->storedCard($token);
    }

    function getPostParam($index, $default='')
    {
        $result = Mage::registry('stripe_result');
        if (!empty($result))
        {
            $indices = explode('.', $index);
            $value = $result->params;
            foreach($indices as $key)
            {
                if (is_array($value[$key]))
                {
                    $value = $value[$key];
                }
                else
                {
                    return $value[$key];
                }
            }

        }
        else
        {
            return $default;
        }
    }

    function errors()
    {
        $result = Mage::registry('stripe_result');
        if (!empty($result))
        {
            return Mage::helper('stripe/messages')->errors(explode("\n", $result->message));
        }
    }

    function hasErrors()
    {
        $result = Mage::registry('stripe_result');
        return !empty($result) && !$result->success;
    }

    function buildTrData($redirectUrl)
    {
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        if($this->stripe->exists($customer->getId()))
        {
            return stripe_TransparentRedirect::createCreditCardData(array(
                'redirectUrl' => $redirectUrl,
                'creditCard' => array('customerId' => $customer->getId())
            ));
        }
        else
        {
            $credit_card_billing = array();
            $billing = $customer->getDefaultBilling();
            if ($billing)
            {
                $address = Mage::getModel('customer/address')->load($billing);
                $credit_card_billing['billingAddress'] = $this->stripe->tostripeAddress($address);
            }
            return stripe_TransparentRedirect::createCustomerData(array(
                'redirectUrl' => $redirectUrl,
                'customer' => array(
                    'id' => $customer->getId(),
                    'firstName' => $customer->getFirstname(),
                    'lastName' => $customer->getLastname(),
                    'company' => $customer->getCompany(),
                    'phone' => $customer->getTelephone(),
                    'fax' => $customer->getFax(),
                    'email' => $customer->getEmail(),
                    'creditCard' => $credit_card_billing
               )));
        }
    }

    function hasDefaultAddress()
    {
        $defaultAddress = Mage::getSingleton('customer/session')->getCustomer()->getDefaultBilling();
        return !is_null($defaultAddress);
    }
}
