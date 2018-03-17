<?php

/**
 * Created by PhpStorm.
 * User: Boxedsolutions
 * Date: 2016-10-19
 * Time: 12:37
 */
class Billmate_Common_Model_Checkout extends Varien_Object
{

    public function init()
    {
        $helper = Mage::helper('billmatecommon');

        $billmate = $helper->getBillmate();

        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $quote->collectTotals();

        $Billing= $quote->getBillingAddress();
        $Shipping= $quote->getShippingAddress();

        // Need country and postcode, add if missing
        if(strlen($Shipping->getCountry()) < 2){
            $Shipping->setCountryId('SE');
            $Shipping->setPostcode('12345');
            $Shipping->save();
        }
        $storeLanguage = Mage::app()->getLocale()->getLocaleCode();
        $countryCode = Mage::getStoreConfig('general/country/default',Mage::app()->getStore());
        $storeCountryIso2 = Mage::getModel('directory/country')->loadByCode($countryCode)->getIso2Code();

        $orderValues = array();

        $orderValues['CheckoutData'] = array(
            'windowmode' => 'iframe',
            'sendreciept' => 'yes',
            'terms' => Mage::getUrl('billmatecommon/billmatecheckout/terms')
        );

        if(!$quote->getReservedOrderId())
            $quote->reserveOrderId();

        $orderValues['PaymentData'] = array(
            'method' => 93,
            'currency' => Mage::app()->getStore()->getCurrentCurrencyCode(),
            'language' => BillmateCountry::fromLocale($storeLanguage),
            'country' => $storeCountryIso2,
            'orderid' => $quote->getReservedOrderId()
        );
        $orderValues['PaymentData']['accepturl'] = Mage::getUrl('billmatecommon/callback/accept', array('_query' => array('billmate_checkout' => true,'billmate_quote_id' => $quote->getId()), '_secure' => true));
        $orderValues['PaymentData']['cancelurl'] = Mage::getUrl('billmatecommon/callback/cancel', array('_secure' => true));
        $orderValues['PaymentData']['callbackurl'] = Mage::getUrl('billmatecommon/callback/callback', array('_query' => array('billmate_quote_id' => $quote->getId(),'billmate_checkout' => true), '_secure' => true));

        $orderValues['PaymentData']['returnmethod'] = (Mage::app()->getStore()->isCurrentlySecure()) ? 'POST' : 'GET';

        $_taxHelper  = Mage::helper('tax');
        $_weeeHelper = Mage::helper('weee');
        $percent = 0;
        $store = Mage::app()->getStore();
        $discountAmount = 0;
        $_simplePricesTax = ($_taxHelper->displayPriceIncludingTax() || $_taxHelper->displayBothPrices());
        // Create Array to save ParentId when bundle is fixed prised
        $bundleArr = array();
        $totalValue = 0;
        $totalTax = 0;
        $discountAdded = false;
        $discountValue = 0;
        $discountTax = 0;
        $discounts = array();
        $configSku = false;

        $preparedArticle = Mage::helper('billmatecommon')->prepareArticles($quote);
        $discounts = $preparedArticle['discounts'];
        $totalTax = $preparedArticle['totalTax'];
        $totalValue = $preparedArticle['totalValue'];
        $orderValues['Articles'] = $preparedArticle['articles'];

        $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals();

        //print_r($quote1['subtotal']->getData());

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
                    'title'      => Mage::helper( 'payment' )->__( 'Discount' ).' '. Mage::helper('billmatecardpay')->__('%s Vat',$percent),
                    'aprice'     => round( ($discountAmount * $marginal ) * 100 ),
                    'taxrate'    => (float) $percent,
                    'discount'   => 0.0,
                    'withouttax' => round( ($discountAmount * $marginal ) * 100 ),

                );
                $totalValue += ( 1 * round( $discountAmount * $marginal * 100 ) );
                $totalTax += ( 1 * round( ( $discountAmount * $marginal ) * 100 ) * ( $percent / 100 ) );
            }
        }


        // If no shipping method is selected, select last available shipping method
        $allShippingRates = $quote->getShippingAddress()->getGroupedAllShippingRates();
        if (is_array($allShippingRates)) {
            $_rate = end(end($allShippingRates));
            try {
                $_code = $_rate->getCode();
                if ($_code != '') {
                    $quote->getShippingAddress()->setCollectShippingRates(true)->setShippingMethod($_code)->collectTotals()->save();
                }
            } catch (\Exception $e){
                // Silent fail
            }
        }
        $quote->setTotalsCollectedFlag(false)->save();
        $quote->collectTotals();

        $rates = $quote->getShippingAddress()->getShippingRatesCollection();
        if(!empty($rates)){
            if( $Shipping->getBaseShippingTaxAmount() > 0 ){
                $shippingExclTax = $Shipping->getShippingAmount();
                $shippingIncTax = $Shipping->getShippingInclTax();
                $rate = $shippingExclTax > 0 ? (($shippingIncTax / $shippingExclTax) - 1) * 100 : 0;
            }
            else
                $rate = 0;
            if($Shipping->getShippingAmount() > 0 && $Shipping->getShippingDiscountAmount() != $Shipping->getShippingAmount()) {
                $orderValues['Cart']['Shipping'] = array(
                    'withouttax' => ($Shipping->getShippingDiscountAmount() < 0) ? ($Shipping->getShippingAmount() - $Shipping->getShippingDiscountAmount()) * 100 : $Shipping->getShippingAmount() * 100,
                    'taxrate' => (int)$rate
                );
                $totalValue += $Shipping->getShippingAmount() * 100;
                $totalTax += ($Shipping->getShippingAmount() * 100) * ($rate / 100);
            } else {
                $orderValues['Cart']['Shipping'] = array(
                    'withouttax' => 0,
                    'taxrate' => (int)$rate
                );
            }
        }
        $round = round($quote->getGrandTotal() * 100) - round($totalValue +  $totalTax);

        $invoiceFee = Mage::getStoreConfig( 'payment/billmateinvoice/billmate_fee' );
        $invoiceFee = Mage::helper( 'billmateinvoice' )->replaceSeparator( $invoiceFee );

        //if(Mage::getStoreConfig('payment/billmateinvoice/tax_class')){
        $feeinfo = Mage::helper( 'billmateinvoice' )
            ->getInvoiceFeeArray( $invoiceFee, $Shipping, $quote->getCustomerTaxClassId() );
        //}
        if ( ! empty( $invoiceFee ) && $invoiceFee > 0 )
        {
            $baseCurrencyCode    = Mage::app()->getStore()->getBaseCurrencyCode();
            $currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
            $_directory          = Mage::helper( 'directory' );
            $invoiceFee = $_directory->currencyConvert($invoiceFee,$baseCurrencyCode,$currentCurrencyCode);

            $orderValues['Cart']['Handling'] = array(
                'withouttax' => round($invoiceFee * 100),
                'taxrate'    => $feeinfo['rate']
            );
            $totalValue += $invoiceFee * 100;
            $totalTax += ( $invoiceFee * 100 ) * ( $feeinfo['rate'] / 100 );
        }

        $orderValues['Cart']['Total'] = array(
            'withouttax' => round($totalValue),
            'tax' => round($totalTax),
            'rounding' => round($round),
            'withtax' =>round($totalValue + $totalTax +  $round)
        );

        $result = $billmate->initCheckout($orderValues);

        if(!isset($result['code'])){
            $url = $result['url'];
            $parts = explode('/',$url);
            $sum = count($parts);
            $hash = ($parts[$sum-1] == 'test') ? str_replace('\\','',$parts[$sum-2]) : str_replace('\\','',$parts[$sum-1]);
            $quote->setBillmateHash($hash);
            $quote->save();
            Mage::getSingleton('checkout/session')->setBillmateHash($hash);
        }
        return $result;

    }

    public function updateCheckout()
    {
        $helper = Mage::helper('billmatecommon');

        $billmate = $helper->getBillmate();

        $quote = Mage::getSingleton('checkout/session')->getQuote();

        $Billing = $quote->getBillingAddress();
        $Shipping = $quote->getShippingAddress();

        $storeLanguage = Mage::app()->getLocale()->getLocaleCode();
        $countryCode = Mage::getStoreConfig('general/country/default',Mage::app()->getStore());
        $storeCountryIso2 = Mage::getModel('directory/country')->loadByCode($countryCode)->getIso2Code();
        $_orderValues = $billmate->getCheckout(array('PaymentData' => array('hash' => Mage::getSingleton('checkout/session')->getBillmateHash())));

        $orderValues = array(
            'PaymentData' => array(
                'number' => $_orderValues['PaymentData']['number']
            )
        );

        $previousTotal = $_orderValues['Cart']['Total']['withtax'];
        if(!$quote->getReservedOrderId())
            $quote->reserveOrderId();

        $orderValues['PaymentData']['currency'] = Mage::app()->getStore()->getCurrentCurrencyCode();
        $orderValues['PaymentData']['language'] = BillmateCountry::fromLocale($storeLanguage);
        $orderValues['PaymentData']['country'] = $storeCountryIso2;
        $orderValues['PaymentData']['orderid'] = $quote->getReservedOrderId();

        $_taxHelper  = Mage::helper('tax');
        $_weeeHelper = Mage::helper('weee');
        $percent = 0;
        $store = Mage::app()->getStore();
        $discountAmount = 0;
        $_simplePricesTax = ($_taxHelper->displayPriceIncludingTax() || $_taxHelper->displayBothPrices());
        // Create Array to save ParentId when bundle is fixed prised
        $bundleArr = array();
        $totalValue = 0;
        $totalTax = 0;
        $discountAdded = false;
        $discountValue = 0;
        $discountTax = 0;
        $discounts = array();
        $configSku = false;
        unset($orderValues['Articles']);
        
        $preparedArticle = Mage::helper('billmatecommon')->prepareArticles($quote);
        $discounts = $preparedArticle['discounts'];
        $totalTax = $preparedArticle['totalTax'];
        $totalValue = $preparedArticle['totalValue'];
        $orderValues['Articles'] = $preparedArticle['articles'];

        $totals = Mage::getSingleton('checkout/session')->getQuote()->collectTotals()->getTotals();

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
                    'title'      => Mage::helper( 'payment' )->__( 'Discount' ).' '. Mage::helper('billmatecardpay')->__('%s Vat',$percent),
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

        unset($orderValues['Cart']['Shipping']);
        unset($orderValues['Cart']['Handling']);
        unset($orderValues['Customer']);
        if(!empty($rates)){
            if( $Shipping->getBaseShippingTaxAmount() > 0 ){
                $shippingExclTax = $Shipping->getShippingAmount();
                $shippingIncTax = $Shipping->getShippingInclTax();
                $rate = $shippingExclTax > 0 ? (($shippingIncTax / $shippingExclTax) - 1) * 100 : 0;
            }
            else
                $rate = 0;

            if($Shipping->getShippingAmount() > 0 && $Shipping->getShippingDiscountAmount() != $Shipping->getShippingAmount()) {
                $orderValues['Cart']['Shipping'] = array(
                    'withouttax' => ($Shipping->getShippingDiscountAmount() < 0) ? ($Shipping->getShippingAmount() - $Shipping->getShippingDiscountAmount()) * 100 : $Shipping->getShippingAmount() * 100,
                    'taxrate' => (int)$rate
                );
                $totalValue += $Shipping->getShippingAmount() * 100;
                $totalTax += ($Shipping->getShippingAmount() * 100) * ($rate / 100);
            }
        }
        $round = round($quote->getGrandTotal() * 100) - round($totalValue +  $totalTax);

        $invoiceFee = Mage::getStoreConfig( 'payment/billmateinvoice/billmate_fee' );
        $invoiceFee = Mage::helper( 'billmateinvoice' )->replaceSeparator( $invoiceFee );

        $feeinfo = Mage::helper( 'billmateinvoice' )
            ->getInvoiceFeeArray( $invoiceFee, $Shipping, $quote->getCustomerTaxClassId() );

        if ( ! empty( $invoiceFee ) && $invoiceFee > 0 )
        {
            $baseCurrencyCode    = Mage::app()->getStore()->getBaseCurrencyCode();
            $currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
            $_directory          = Mage::helper( 'directory' );
            $invoiceFee = $_directory->currencyConvert($invoiceFee,$baseCurrencyCode,$currentCurrencyCode);

            $orderValues['Cart']['Handling'] = array(
                'withouttax' => round($invoiceFee * 100),
                'taxrate'    => $feeinfo['rate']
            );
            $totalValue += $invoiceFee * 100;
            $totalTax += ( $invoiceFee * 100 ) * ( $feeinfo['rate'] / 100 );
        }

        $orderValues['Cart']['Total'] = array(
            'withouttax' => round($totalValue),
            'tax' => round($totalTax),
            'rounding' => round($round),
            'withtax' =>round($totalValue + $totalTax +  $round)
        );

        $result = array();
        $result = $billmate->updateCheckout($orderValues);
        if($previousTotal != $orderValues['Cart']['Total']['withtax']){
            $result['update_checkout'] = true;
            $result['data'] = $orderValues;
        } else {
            $result['update_checkout'] = false;
            $result['data'] = array();

        }
        return $result;
    }
}