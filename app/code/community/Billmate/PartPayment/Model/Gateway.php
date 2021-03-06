<?php
class Billmate_Partpayment_Model_Gateway extends Varien_Object{
    public $isMatched = true;
    function makePayment(){
        // Init $orderValues Array
        $orderValues = array();
        $quote = Mage::getSingleton('checkout/session')->getQuote();

        $Billing= $quote->getBillingAddress();
        $Shipping= $quote->getShippingAddress();

        $payment = Mage::app()->getRequest()->getPost('payment');
        $_customer  = Mage::getSingleton('customer/session')->isLoggedIn() ? Mage::getSingleton('customer/session')->getCustomer()->getData() : null;

        $Customer = (object)$_customer;

        $country_to_currency = array(
            'NO' => 'NOK',
            'SE' => 'SEK',
            'FI' => 'EUR',
            'DK' => 'DKK',
            'DE' => 'EUR',
            'NL' => 'EUR',
        );
        $methodname = $payment['method'] == 'billmateinvoice'? 'billmateinvoice': 'billmatepartpayment';
        $k = Mage::helper('partpayment')->getBillmate(true, false);

        $customerId = (!Mage::getSingleton('customer/session')->getCustomer()->getId()) ? $quote->getCustomerId() : Mage::getSingleton('customer/session')->getCustomer()->getId();
        $iso3 = Mage::getModel('directory/country')->load($Billing->getCountryId())->getIso3Code();
        $countryCode = Mage::getStoreConfig('general/country/default',Mage::app()->getStore());
        $storeCountryIso2 = Mage::getModel('directory/country')->loadByCode($countryCode)->getIso2Code();
        $storeLanguage = Mage::app()->getLocale()->getLocaleCode();

        if( $payment['method'] == 'billmatecardpay' ){
            $country = Mage::getModel('directory/country')->load($Billing->getCountryId())->getName() ;
        } else {
            $country = 209;
        }
        $language = 138;
        $encoding = 2;
        $currency = 0;

        switch ($iso3) {
            // Sweden
            case 'SWE':
                $country = 209;
                $language = 138;
                $encoding = 2;
                $currency = 0;
                break;
        }
        $ship_address = $bill_address = array();
        $shipp = $Shipping->getStreet();

        $bill = $Billing->getStreet();

        foreach($bill_address as $key => $col ){
            $bill_address[$key] = mb_convert_encoding($col,'UTF-8','auto');
        }
        foreach($ship_address as $key => $col ){
            $ship_address[$key] = mb_convert_encoding($col,'UTF-8','auto');
        }


        $baseCurrencyCode = Mage::app()->getStore()->getBaseCurrencyCode();
        $currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        $_directory = Mage::helper('directory');



        $store = Mage::app()->getStore();

        $orderValues['PaymentData'] = array(
            'method' => 4,
            'currency' => $currentCurrencyCode,
            'paymentplanid' => $_POST['pclass'],
            'country' => $storeCountryIso2,
            'orderid' => ($quote->getReservedOrderId()) ? $quote->getReservedOrderId() : (string)time(),
            'autoactivate' => 0,
            'language' => BillmateCountry::fromLocale($storeLanguage),
            'logo' => (strlen(Mage::getStoreConfig('billmate/settings/logo')) > 0) ? Mage::getStoreConfig('billmate/settings/logo') : ''


        );
        $orderValues['PaymentInfo'] = array(
            'paymentdate' => (string)date('Y-m-d'),
            'yourreference' => $Billing->getFirstname(). ' ' . $Billing->getLastname(),
            'delivery' => $Shipping->getShippingDescription(),

        );

        $orderValues['Customer'] = array(
            'nr' => $customerId,
            'pno' => (empty($payment[$methodname.'_pno'])) ? $payment['person_number'] : $payment[$methodname.'_pno']
        );
	    $orderValues['Customer']['Billing'] = array(
		    'firstname' => $Billing->getFirstname(),
		    'lastname'  => $Billing->getLastname(),
		    'company'   => $Billing->getCompany(),
		    'street'    => $bill[0],
		    'street2'   => isset( $bill[1] ) ? $bill[1] : '',
		    'zip'       => $Billing->getPostcode(),
		    'city'      => $Billing->getCity(),
		    'country'   => $Billing->getCountryId(),
		    'phone'     => $Billing->getTelephone(),
		    'email'     => $Billing->email
	    );

	    $orderValues['Customer']['Shipping'] = array(
		    'firstname' => $Shipping->getFirstname(),
		    'lastname'  => $Shipping->getLastname(),
		    'company'   => $Shipping->getCompany(),
		    'street'    => $shipp[0],
		    'street2'   => isset( $shipp[1] ) ? $shipp[1] : '',
		    'zip'       => $Shipping->getPostcode(),
		    'city'      => $Shipping->getCity(),
		    'country'   => $Shipping->getCountryId(),
		    'phone'     => $Shipping->getTelephone()
	    );

        // Create Array to save ParentId when bundle is fixed prised
        $bundleArr = array();
        $totalValue = 0;
        $totalTax = 0;
        $discountAdded = false;
        $discountValue = 0;
        $configSku = false;
        $discounts = array();


        $preparedArticle = Mage::helper('billmatecommon')->prepareArticles($quote);
        $discounts = $preparedArticle['discounts'];
        $totalTax = $preparedArticle['totalTax'];
        $totalValue = $preparedArticle['totalValue'];
        $orderValues['Articles'] = $preparedArticle['articles'];


        $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals();

	    if(isset($totals['discount']) && !$discountAdded) {
		    $totalDiscountInclTax = $totals['discount']->getValue();
		    $subtotal = $totalValue;
		    foreach($discounts as $percent => $amount) {
			    $discountPercent = $amount / $subtotal;
			    $floor    = 1 + ( $percent / 100 );
			    $marginal = 1 / $floor;
			    $discountAmount = $discountPercent * $totalDiscountInclTax;
			    $orderValues['Articles'][] = array(
				    'quantity'   => (int) 1,
				    'artnr'      => 'discount',
				    'title'      => Mage::helper( 'payment' )->__( 'Discount' ).' '. Mage::helper('partpayment')->__('%s Vat',$percent),
				    'aprice'     => round( ($discountAmount * $marginal ) * 100 ),
				    'taxrate'    => (float) $percent,
				    'discount'   => 0.0,
				    'withouttax' => round( ($discountAmount * $marginal ) * 100 ),

			    );
			    $totalValue += ( 1 * round( $discountAmount * $marginal * 100 ) );
			    $totalTax += ( 1 * round( ( $discountAmount * $marginal ) * 100 ) * ( $percent / 100 ) );
		    }
	    }


        $rates = $quote->getShippingAddress()->getShippingRatesCollection();
        if(!empty($rates)){
            if( $Shipping->getBaseShippingTaxAmount() > 0 ){

                $shippingExclTax = $Shipping->getShippingAmount();
                $shippingIncTax = $Shipping->getShippingInclTax();
                $rate = $shippingExclTax > 0 ? (($shippingIncTax / $shippingExclTax) - 1) * 100 : 0;
            }
            else
                $rate = 0;

            if($Shipping->getShippingAmount() > 0) {
                $orderValues['Cart']['Shipping'] = array(
                    'withouttax' => $Shipping->getShippingAmount() * 100,
                    'taxrate' => (int)$rate
                );
                $totalValue += $Shipping->getShippingAmount() * 100;
                $totalTax += ($Shipping->getShippingAmount() * 100) * ($rate / 100);
            }
        }

        $round = round($quote->getGrandTotal() * 100) - round($totalValue +  $totalTax);


        $orderValues['Cart']['Total'] = array(
            'withouttax' => round($totalValue),
            'tax' => round($totalTax),
            'rounding' => round($round),
            'withtax' =>round($totalValue + $totalTax +  $round)
        );
        $result  = $k->addPayment($orderValues);

        if(isset($result['code'])){
            switch($result['code']){
                case 2401:
                case 2402:
                case 2403:
                case 2404:
                case 2405:
                    $this->init();
                    echo Mage::app()->getLayout()->createBlock('partpayment/changeaddress')->toHtml();
                    die();
                    break;
                default:
                    Mage::throwException( utf8_encode( $result['message'] ) );
            }
        } else {
            $session = Mage::getSingleton('core/session', array('name' => 'frontend'));
            $session->setData('billmateinvoice_id', $result['number']);
            $session->setData('billmateorder_id', $result['orderid']);
	        $session->setData('billmate_status',$result['status']);

	        return $result['number'];
        }
    }

    function init($update = false){

        $payment = Mage::app()->getRequest()->getPost('payment');
        $_customer  = Mage::getSingleton('customer/session')->isLoggedIn() ? Mage::getSingleton('customer/session')->getCustomer()->getData() : null;
        $Customer = (object)$_customer;

        $country_to_currency = array(
            'NO' => 'NOK',
            'SE' => 'SEK',
            'FI' => 'EUR',
            'DK' => 'DKK',
            'DE' => 'EUR',
            'NL' => 'EUR',
        );

        $methodname = $payment['method'];
        $k = Mage::helper('partpayment')->getBillmate(true, false);
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $Billing= $quote->getBillingAddress();
        $Shipping= $quote->getShippingAddress();

        try{
            $pno = (empty($payment[$methodname.'_pno'])) ? $payment['person_number'] : $payment[$methodname.'_pno'];
            $addr = $k->getAddress(array('pno' =>$pno));

            if(!is_array($addr)){
                Mage::throwException( Mage::helper('payment')->__(utf8_encode($addr)));
            }

            if( isset($addr['code']) ){

                Mage::throwException(utf8_encode($addr['message']));

            }
            foreach( $addr as $key => $col ){
                $addr[$key] = mb_convert_encoding($col,'UTF-8','auto');
            }
            if( empty( $addr['firstname'] ) ){
                $this->firstname = $Billing->getFirstname();
                $this->lastname = $Billing->getLastname();
                $this->company  = $addr['lastname'];
            } else {
                $this->firstname = $addr['firstname'];
                $this->lastname = $addr['lastname'];
                $this->company  = '';
            }
            $this->street = $addr['street'];
            $this->postcode = $addr['zip'];
            $this->city = $addr['city'];
	        if(Mage::getSingleton('customer/session')->isLoggedIn())
		        $this->telephone = $Billing->getTelephone();
            $this->country = $addr['country'];
            $this->country_name = Mage::getModel('directory/country')->loadByCode($this->country)->getName();

        }catch( Exception $ex ){
            Mage::logException( $ex );
            die('alert("'.utf8_encode($ex->getMessage())./*strip_tags( str_replace("<br> ",'\n\n', $ex->getMessage()) ).*/'");');
        }
        $customerId = Mage::getSingleton('customer/session')->getCustomer()->getId();

        $fullname = $Billing->getFirstname().' '.$Billing->getLastname().' '.$Billing->getCompany();
        if( empty($addr['firstname']) ){
            $apiName = $Billing->getFirstname().' '.$Billing->getLastname().' '.$Billing->getCompany();
        } else {
            $apiName  = $addr['firstname'].' '.$addr['lastname'];
        }
        $billingStreet = $Billing->getStreet();

        $addressNotMatched = !isEqual($addr['street'], $billingStreet[0] ) ||
            !isEqual($addr['zip'], $Billing->getPostcode()) ||
            !isEqual($addr['city'], $Billing->getCity()) ||
            !isEqual(strtolower($addr['country']), $Billing->getCountryId());


        $shippingStreet = $Shipping->getStreet();

        $shippingAndBilling =  !match_usernamevp( $fullname , $apiName) ||
            !isEqual($shippingStreet[0], $billingStreet[0] ) ||
            !isEqual($Shipping->getPostcode(), $Billing->getPostcode()) ||
            !isEqual($Shipping->getCity(), $Billing->getCity()) ||
            !isEqual($Shipping->getCountryId(), $Billing->getCountryId()) ;

        if( $addressNotMatched || $shippingAndBilling ){
            $this->isMatched = false;
        }
        if( $update) {
            $this->isMatched = true;
            $data = array(
                'firstname' => $this->firstname,
                'lastname'  => $this->lastname,
                'street'    => $this->street,
                'company'   => $this->company,
                'postcode'  => $this->postcode,
                'city'      => $this->city,
                'country_id'   => strtoupper($this->country),
            );

            $customerAddress = Mage::getModel('customer/address');
            $customer = Mage::getSingleton('customer/session')->getCustomer();
            $Billing->addData( $data )->save();
            $Shipping->addData($data)->save();
            //    Mage::getSingleton('checkout/session')->clear();
            Mage::getModel('checkout/session')->loadCustomerQuote();
        }
    }
}
