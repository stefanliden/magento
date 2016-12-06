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

        $Billing= $quote->getBillingAddress();
        $Shipping= $quote->getShippingAddress();

        $storeLanguage = Mage::app()->getLocale()->getLocaleCode();
        $countryCode = Mage::getStoreConfig('general/country/default',Mage::app()->getStore());
        $storeCountryIso2 = Mage::getModel('directory/country')->loadByCode($countryCode)->getIso2Code();

        $orderValues = array();

        $orderValues['CheckoutData'] = array(
            'windowmode' => 'iframe',
            'sendreciept' => 'yes',
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
        foreach( $quote->getAllItems() as $_item){
            /**
             * @var $_item Mage_Sales_Model_Quote_Item
             */
            // Continue if bundleArr contains item parent id, no need for get price then.
            if( in_array($_item->getParentItemId(),$bundleArr)){
                continue;
            }
            $request = Mage::getSingleton('tax/calculation')->getRateRequest(null, null, null, $store);
            $taxclassid = $_item->getProduct()->getData('tax_class_id');
            // If Product type == bunde and if bundle price type == fixed
            if($_item->getProductType() == 'bundle' && $_item->getProduct()->getPriceType() == 1){
                // Set bundle id to $bundleArr
                $bundleArr[] = $_item->getId();

            }
            if($_item->getProductType() == 'configurable'){
                $configSku = $_item->getSku();
                $cp = $_item->getProduct();
                $sp = Mage::getModel('catalog/product')->loadByAttribute('sku',$_item->getSku());

                $price = $_item->getCalculationPrice();
                $percent = Mage::getSingleton('tax/calculation')->getRate($request->setProductClassId($taxclassid));
                $discount = 0.0;
                $discountAmount = 0;
                if($_item->getDiscountPercent() != 0){
                    $discountAdded = true;

                    $marginal = ($percent/100)/ (1+($percent/100));
                    $discount = $_item->getDiscountPercent();
                    $discountAmount = $_item->getBaseDiscountAmount();
                    // $discountPerArticle without VAT
                    $discountAmount = $discountAmount - ($discountAmount * $marginal);

                }
                $total = ($discountAdded) ? (int) round((($price * $_item->getQty() - $discountAmount)* 100)) : (int)round($price*100) * $_item->getQty();
                $orderValues['Articles'][] = array(
                    'quantity'   => (int)$_item->getQty(),
                    'artnr'    => $_item->getProduct()->getSKU(),
                    'title'    => $cp->getName().' - '.$sp->getName(),
                    // Dynamic pricing set price to zero
                    'aprice'    => (int)round($price*100,0),
                    'taxrate'      => (float)$percent,
                    'discount' => $discount,
                    'withouttax' => $total

                );

                $temp = $total;
                $totalValue += $temp;
                $totalTax += $temp * ($percent/100);

                if(isset($discounts[$percent]))
                    $discounts[$percent] += $temp;
                else
                    $discounts[$percent] = $temp;

            }
            if($_item->getSku() == $configSku){


                continue;
            }
            // If Product type == bunde and if bundle price type == dynamic
            if($_item->getProductType() == 'bundle' && $_item->getProduct()->getPriceType() == 0){

                $percent = Mage::getSingleton('tax/calculation')->getRate($request->setProductClassId($taxclassid));
                $orderValues['Articles'][] = array(
                    'quantity'   => (int)$_item->getQty(),
                    'artnr'    => $_item->getProduct()->getSKU(),
                    'title'    => $_item->getName(),
                    // Dynamic pricing set price to zero
                    'aprice'    => (int)0,
                    'taxrate'      => (float)$percent,
                    'discount' => 0.0,
                    'withouttax' => (int)0

                );


                // Else the item is not bundle and dynamic priced
            } else {
                $temp = 0;
                $percent = Mage::getSingleton('tax/calculation')->getRate($request->setProductClassId($taxclassid));


                // For tierPrices to work, we need to get calculation price not the price on the product.
                // If a customer buys many of a kind and get a discounted price, the price will bee on the quote item.
                $price = $_item->getCalculationPrice();

                //Mage::throwException( 'error '.$_regularPrice.'1-'. $_finalPrice .'2-'.$_finalPriceInclTax.'3-'.$_price);
                $discount = 0.0;
                $discountAmount = 0;
                if($_item->getDiscountPercent() != 0){
                    $discountAdded = true;
                    //$discount = 100 *($_item->getBaseDiscountAmount() / $price);
                    $marginal = ($percent/100)/ (1+($percent/100));
                    $discount = $_item->getDiscountPercent();
                    $discountAmount = $_item->getBaseDiscountAmount();
                    // $discountPerArticle without VAT
                    $discountAmount = $discountAmount - ($discountAmount * $marginal);

                }
                $total = ($discountAdded) ? (int) round((($price * $_item->getQty() - $discountAmount)* 100)) : (int)round($price*100) * $_item->getQty();
                $orderValues['Articles'][] = array(
                    'quantity'   => (int)$_item->getQty(),
                    'artnr'    => $_item->getProduct()->getSKU(),
                    'title'    => $_item->getName(),
                    'aprice'    => (int)round($price*100,0),
                    'taxrate'      => (float)$percent,
                    'discount' => $discount,
                    'withouttax' => $total

                );
                $temp = $total;
                $totalValue += $temp;
                $totalTax += $temp * ($percent/100);

                if(isset($discounts[$percent]))
                    $discounts[$percent] += $temp;
                else
                    $discounts[$percent] = $temp;
            }
        }
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


        $rates = $quote->getShippingAddress()->getShippingRatesCollection();
        if(!empty($rates)){
            if( $Shipping->getBaseShippingTaxAmount() > 0 ){
                $taxCalculation = Mage::getModel('tax/calculation');
                $request = $taxCalculation->getRateRequest($Shipping,$Billing,null,$quote->getStore());
                $taxRateId = Mage::getStoreConfig('tax/classes/shipping_tax_class',$quote->getStore());
                $rate = $taxCalculation->getRate($request->setProductClassId($taxRateId));
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

        return $billmate->initCheckout($orderValues);

    }
}