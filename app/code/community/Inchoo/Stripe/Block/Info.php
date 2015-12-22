<?php

class Inchoo_Stripe_Block_Info extends Mage_Payment_Block_Info
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('stripe/info.phtml');
    }
}
