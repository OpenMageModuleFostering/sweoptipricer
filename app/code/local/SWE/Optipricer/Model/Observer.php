<?php

/**
 * Class SWE_Optipricer_Model_Observer
 *
 * @package   SWE_Optipricer
 * @author    Ubiprism Lda. / be.ubi <contact@beubi.com>
 * @copyright 2014 be.ubi
 * @license   GNU Lesser General Public License (LGPL)
 * @version   v.0.2
 */
class SWE_Optipricer_Model_Observer extends Varien_Event_Observer
{
    const STATE_CANCELED                       = 'canceled';
    const SCENARIO_ONE_PAGE_CHECKOUT           = 'one_page';
    const SCENARIO_MULTIPLE_ADDRESSES_CHECKOUT = 'multiple_addresses';
    const URL_ENDPOINT_COUPON = 'coupon';
    const URL_ENDPOINT_REDEEM = 'redeem';
    const URL_SEPARATOR = '/';

    protected $_serializer = null;
    private $token;
    private $key;
    private $enabledGlobal;
    private $minDiscount;
    private $maxDiscount;
    private $text;
    private $endPoint;
    private $pageView;
    private $renderView;
    private $locale;
    private $expiryOffset;

    /**
     * Initialization
     */
    protected function _construct()
    {
        $this->token         = Mage::getStoreConfig('swe/swe_group_activation/swe_token',Mage::app()->getStore());
        $this->key           = Mage::getStoreConfig('swe/swe_group_activation/swe_key', Mage::app()->getStore());
        $this->enabledGlobal = Mage::getStoreConfig('swe/swe_group_activation/swe_enable',Mage::app()->getStore());
        $this->endPoint      = Mage::getStoreConfig('swe/swe_group_activation/swe_endpoint',Mage::app()->getStore());
        $this->minDiscount   = Mage::getStoreConfig('swe/swe_group_parameters/swe_min',Mage::app()->getStore());
        $this->maxDiscount   = Mage::getStoreConfig('swe/swe_group_parameters/swe_max',Mage::app()->getStore());
        $this->text          = '';
        $this->pageView      = Mage::getStoreConfig('swe/swe_group_parameters/swe_pageview',Mage::app()->getStore());
        $this->renderView    = Mage::getStoreConfig('swe/swe_group_parameters/swe_renderview',Mage::app()->getStore());
        $this->expiryOffset  = Mage::getStoreConfig('swe/swe_group_parameters/swe_expiryoffset',Mage::app()->getStore());
        $this->locale        = Mage::app()->getLocale()->getLocaleCode();
        $this->_serializer   = new Varien_Object();
    }

    /**
     * Verify each price for each discount given
     *
     * @param Varien_Event_Observer $observer Observer
     *
     * @return void
     */
    public function validateCart(Varien_Event_Observer $observer)
    {
        $session = Mage::getSingleton('checkout/session');
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = $session->getQuote();
        $quote->setIsMultiShipping(false);
        $scenario = $this->getCurrentScenario($quote);
        $this->_construct();
        if (!$this->token) {
            return;
        }
        try {
            $errors = array();
            foreach($quote->getAllItems() as $item) {
                $error = $this->validateItem($item, $scenario);
                if ($error) {
                    $errors[] = $error;
                }
            }
            $quote->setTotalsCollectedFlag(false);

        } catch (Exception $e) {
            //ToDo: send email with the error
            return;
        }
    }

    /**
     * Send Order status to Optipricer System
     *
     * @param Varien_Event_Observer $observer Observer
     *
     * @return void
     */
    public function sendOrderStatus(Varien_Event_Observer $observer)
    {
        $this->_construct();
        if (!$this->token) {
            return;
        }
        $cookiesToRemove = array();
        $session = Mage::getSingleton('checkout/session');
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = $session->getQuote();
        $scenario = $this->getCurrentScenario($quote);
        $errors = array();
        try {
            //Validate each product (that has a coupon assigned to it)
            foreach($quote->getAllItems() as $item)
            {
                $error = $this->validateItem($item, $scenario);
                if ($error) {
                    $errors[] = $item->getName();
                } else {
                    $productId = $item->getProductId();
                    $oldProduct = Mage::getModel('catalog/product')->load($productId);
                    $originalPrice = round($oldProduct->getData('price'), 2);
                    list($discountToken, $priceWithDiscount) = $this->getDiscountTokenAndValueCookie($this->token, $productId);
                    // Price has changed and a Cookie exists
                    if ($discountToken) {
                        $products = $this->getAllQuoteProducts($scenario, $quote);
                        //Try to Redeem coupon
                        if (!$this->redeemCoupon($discountToken, $products)) {
                            $errors[] = $item->getName();
                            //Remove invalid cookie
                            setcookie('discount_'.$this->token.'_'.$productId, null, -1, '/');
                            $this->changeItemPrice($item, $originalPrice, $scenario);
                        } else {
                            $cookiesToRemove[] = 'discount_'.$this->token.'_'.$productId;
                            $this->changeItemPrice($item, $priceWithDiscount, $scenario);
                        }
                    }
                }
            }
        } catch (Exception $e){
            //ToDo: send email with the error
            return;
        }

        //Process the error regarding the scenario
        if (count($errors)) {
            $result['success'] = false;
            $result['error'] = true;
            if (count($errors) > 1) {
                $message = 'Products '.implode(', ', $errors).' are invalid!';
            } elseif (count($errors) == 1) {
                $message = 'Product '.$errors[0].' is invalid!';
            }
            $result['error_messages'] = $message;
            $response = Mage::app()->getResponse();
            $response->setBody(Mage::helper('core')->jsonEncode($result));
            $response->sendResponse();
            exit();
        } else {
            //Remove cookies of used coupons
            foreach ($cookiesToRemove as $cookie) {
                setcookie($cookie, null, -1, '/');
            }
        }
    }

    /************************* AUXILIARY METHODS ***************************/

    /**
     * Method to manage secure content
     *
     * @param string $content Content
     * @param string $task Task
     *
     * @return string
     */
    private function secureContent($content, $task = 'encrypt')
    {
        if ($task == 'decrypt') {
            $contentSecured = $this->decryptContent($this->key, $content);
        } else {
            $contentSecured = $this->encryptContent($this->key, $content);
        }

        return $contentSecured;
    }

    /**
     * Encrypt content
     *
     * @param String $key     Key
     * @param String $content Content
     *
     * @return string
     */
    private function encryptContent($key, $content)
    {
        $ivSize = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
        $iv = mcrypt_create_iv($ivSize, MCRYPT_DEV_RANDOM);
        $ciphertext = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $content, MCRYPT_MODE_CBC, $iv);
        $ciphertextArr = array('cipher' => base64_encode($ciphertext), 'iv' => base64_encode($iv));
        $ciphertextArr = json_encode($ciphertextArr);
        $ciphertextBase64 = base64_encode($ciphertextArr);

        return $ciphertextBase64;
    }

    /**
     * Decrypt content
     *
     * @param String $key    Key
     * @param String $cipher Cipher
     *
     * @return string
     */
    private function decryptContent($key, $cipher)
    {
        $ciphertextDec = base64_decode($cipher);
        $ciphertextDec = json_decode($ciphertextDec);
        $content = trim(mcrypt_decrypt(
            MCRYPT_RIJNDAEL_128,
            $key,
            base64_decode($ciphertextDec->cipher),
            MCRYPT_MODE_CBC,
            base64_decode($ciphertextDec->iv)
        ));
        return $content;
    }

    /**
     * Method to get the DiscountToken and the Value from the cookie
     *
     * @param string $token Token Store
     * @param int $productId Product Id
     *
     * @return array
     */
    private function getDiscountTokenAndValueCookie($token, $productId)
    {
        $discountToken = '';
        $finalPrice = 0;
        if (isset($_COOKIE['discount_'.$token.'_'.$productId])) {
            $cookie = $_COOKIE['discount_'.$token.'_'.$productId];
            $dataAux = explode(':', $cookie);
            $data = $this->secureContent(json_encode($dataAux[0]), 'decrypt');
            $coupon = json_decode($data, true);
            if (is_array($coupon)) {
                if (array_key_exists('token', $coupon)) {
                    $discountToken = $coupon['token'];
                }
            } else {
                //ToDo: alert that a cookie was violated
                setcookie($cookie, null, -1, '/');
            }
            if (is_array($coupon)) {
                if (array_key_exists('value', $coupon)) {
                    $finalPrice = $coupon['value'];
                }
            }
        }
        return array($discountToken, $finalPrice);
    }

    /**
     * Method to redeem a given coupon
     *
     * @param string $discountToken Discount token
     * @param array $products Products
     * @param string $discountTokens
     *
     * @return bool
     */
    private function redeemCoupon($discountToken, $products, $discountTokens = '')
    {
        $info = array();
        if ($products) {
            $info['products'] = $products;
        }
        $url = $this->endPoint.self::URL_ENDPOINT_COUPON.
            self::URL_SEPARATOR.$discountToken.self::URL_SEPARATOR.self::URL_ENDPOINT_REDEEM;

        $client = new Zend_Http_Client($url);
        $client->setHeaders(Zend_Http_Client::CONTENT_TYPE, 'application/json');
        $client->setHeaders('Authorization', 'Token '.$this->token);
        if (!$discountTokens) {
            $discountTokens = array($discountToken);
        }
        $data = array(
            'tokens' => $discountTokens,
            'notes' => $info,
            'location' => ''
        );
        $securedData['data'] = $this->secureContent(json_encode($data));
        $client->setRawData(json_encode($securedData));
        try {
            $response = $client->request('PUT');
            if ($response->isSuccessful()) {
                //$resp = $response->getBody();
                $result = true;
            } else {
                $result = false;
            }
        } catch (Exception $e) {
            //ToDo: send an email
            $result = false;
        }
        return $result;
    }

    /**
     * Method to change the price of the item/product
     *
     * @param Mage_Sales_Model_Quote_Item $item
     * @param double $finalValue Final Price
     * @param string $scenario Scenario
     *
     * @throws Exception
     */
    private function changeItemPrice($item, $finalValue, $scenario='other')
    {
        try {
            if ($finalValue > 0) {
                $product = $item->getProduct();
                $oldProduct = Mage::getModel('catalog/product')->load($product->getId());
                $originalPrice = $oldProduct->getData('price');
                //ToDo: discount field (amount)
                $item->setOriginalPrice($product->getPrice());
                $item->setCustomPrice($finalValue);
                $item->setOriginalCustomPrice($finalValue);
                $price = $product->getPrice();
                $discount = $price - $finalValue;
                $item->setBaseDiscountAmount($discount);
                $perc = $discount / $price * 100;
                $item->save();
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Method to collect all products from the quote
     *
     * @param string $scenario Current Scenario
     * @param Mage_Sales_Model_Service_Quote $quote Quote
     *
     * @throws Exception
     *
     * @return array
     */
    private function getAllQuoteProducts($scenario, $quote)
    {
        $products = array();
        foreach($quote->getAllItems() as $item)
        {
            $productId = $item->getProductId();
            list($discountToken, $finalValue) = $this->getDiscountTokenAndValueCookie($this->token, $productId);
            $productAux = array();
            $productAux['productId'] = $productId;
            $productAux['discountToken'] = $discountToken;
            $productAux['finalValue'] = $finalValue;
            $products[] = $productAux;
        }
        return $products;
    }

    /**
     * Method to return current Scenario
     *
     * @return string
     */
    private function getCurrentScenario($quote)
    {
        if ($quote->getIsMultiShipping()) {
            $scenario = self::SCENARIO_MULTIPLE_ADDRESSES_CHECKOUT;
        } else {
            $scenario = self::SCENARIO_ONE_PAGE_CHECKOUT;
        }
        return $scenario;
    }

    /**
     * Method to validate an item
     *
     * @param Mage_Sales_Model_Quote_Item $item Item
     * @param string $scenario Current Scenario
     *
     * @throws Exception
     *
     * @return string
     */
    private function validateItem($item, $scenario='other')
    {
        $error = '';
        $productId = $item->getProductId();
        $newProduct = $item->getProduct();
        $oldProduct = Mage::getModel('catalog/product')->load($newProduct->getId());
        list($discountToken, $priceWithDiscount) = $this->getDiscountTokenAndValueCookie($this->token, $productId);
        $originalPrice       = round($oldProduct->getData('price'), 2);
        $currentProductPrice = round($newProduct->getData('price'), 2);
        $currentItemPrice    = round($item->getCustomPrice(), 2);
        if ($originalPrice != $currentProductPrice || $originalPrice != $currentItemPrice || $discountToken) {
            // Price has changed and cookie was deleted/violated
            if (!$discountToken) {
                $error = $item->getName();
                $this->changeItemPrice($item, $originalPrice, $scenario);
                setcookie('discount_'.$this->token.'_'.$productId, null, -1, '/');
            } else {
                $this->changeItemPrice($item, $priceWithDiscount, $scenario);
            }
        }
        return $error;
    }
}
