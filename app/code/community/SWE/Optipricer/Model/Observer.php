<?php

/**
 * Widget to share the product in Facebook and get a personalized discount
 *
 * @package   SWE_Optipricer
 * @author    Ubiprism Lda. / be.ubi <contact@beubi.com>
 * @copyright 2015 be.ubi
 * @license   GNU Lesser General Public License (LGPL)
 * @version   v.0.1.1
 */
class SWE_Optipricer_Model_Observer extends Varien_Event_Observer
{
    const STATE_CANCELED        = 'canceled';
    const URL_ENDPOINT_COUPON   = 'coupon';
    const URL_ENDPOINT_REDEEM   = 'redeem';
    const URL_SEPARATOR         = '/';
    const SWE_CUSTOM_OPTION_KEY = 'SWE_Optipricer_Coupon';
    const LOG_KEY               = 'OPTIPRICER# ';

    /**
     * @var String Token
     */
    private $token;

    /**
     * @var String Encrypt Key
     */
    private $key;

    /**
     * @var bool EnabledGlobal flag
     */
    private $enabledGlobal;

    /**
     * @var Int Minimum Discount
     */
    private $minDiscount;

    /**
     * @var Int Maximum Discount
     */
    private $maxDiscount;

    /**
     * @var @var String EndPoint
     */
    private $endPoint;

    /**
     * @var bool PageView flag
     */
    private $pageView;

    /**
     * @var bool RenderView flag
     */
    private $renderView;

    /**
     * @var String Locale
     */
    private $locale;

    /**
     * @var Int Expiry Offset time (minutes)
     */
    private $expiryOffset;

    /**
     * @var Array Products for Redeem Request
     */
    private $products;

    /**
     * Initialization command
     */
    protected function _construct()
    {
        $this->token         = Mage::getStoreConfig('swe/swe_group_activation/swe_token',Mage::app()->getStore());
        $this->key           = Mage::getStoreConfig('swe/swe_group_activation/swe_key', Mage::app()->getStore());
        $this->enabledGlobal = Mage::getStoreConfig('swe/swe_group_activation/swe_enable',Mage::app()->getStore());
        $this->endPoint      = Mage::getStoreConfig('swe/swe_group_activation/swe_endpoint',Mage::app()->getStore());
        $this->minDiscount   = Mage::getStoreConfig('swe/swe_group_parameters/swe_min',Mage::app()->getStore());
        $this->maxDiscount   = Mage::getStoreConfig('swe/swe_group_parameters/swe_max',Mage::app()->getStore());
        $this->pageView      = Mage::getStoreConfig('swe/swe_group_parameters/swe_pageview',Mage::app()->getStore());
        $this->renderView    = Mage::getStoreConfig('swe/swe_group_parameters/swe_renderview',Mage::app()->getStore());
        $this->expiryOffset  = Mage::getStoreConfig('swe/swe_group_parameters/swe_expiryoffset',Mage::app()->getStore());
        $this->locale        = Mage::app()->getLocale()->getLocaleCode();
        $this->products      = array();
    }

    /**
     * Cart validation
     * Verify each price for each discount given
     *
     * @param Varien_Event_Observer $observer Observer
     *
     * @return void
     */
    public function validateCart(Varien_Event_Observer $observer)
    {
        $this->_construct();
        if (!$this->enabledGlobal) {
            return;
        }
        $session = Mage::getSingleton('checkout/session');
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = $session->getQuote();
        $quote->setIsMultiShipping(false);
        try {
            //Validate all quote items (check if is necessary validate an Optipricer discount)
            foreach($quote->getAllItems() as $item) {
                $prodSessionItems = $this->getProductSessionItems(self::SWE_CUSTOM_OPTION_KEY.'_'.$item->getProductId());
                $error = $this->validateQuoteItem($item, $prodSessionItems);
                if ($error) {
                    $this->logOptipricer(self::LOG_KEY.'_validateCart_'.$error);
                }
            }
            $quote->setTotalsCollectedFlag(false);
            $quote->save();
        } catch (Exception $e) {
            $this->logOptipricer(self::LOG_KEY.'_validateCart_'.$e);
        }
        return;
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
        if (!$this->enabledGlobal) {
            return;
        }
        $cookiesToRemove = array();
        $session = Mage::getSingleton('checkout/session');
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = $session->getQuote();
        $errors = array();

        try {
            $this->products = $this->getAllQuoteProducts($quote);
            //Validate each product (that has a coupon assigned to it)
            foreach($quote->getAllItems() as $item)
            {
                $productId = $item->getProductId();
                $sessionKey = self::SWE_CUSTOM_OPTION_KEY.'_'.$productId;
                $prodSessionItems = $this->getProductSessionItems($sessionKey);
                if ($prodSessionItems) {
                    //Check if the product needs to be validated (has an Optipricer discount)
                    $error = $this->validateQuoteItem($item, $prodSessionItems, false);
                    if ($error) {
                        $errors[] = $item->getName();
                    } else {
                        //Try to Redeem coupon
                        $cookieParams = $this->getCookieParams($this->token, $productId);
                        $error = $this->tryRedeem($item, $cookieParams);
                        if ($error) {
                            $errors[] = $item->getName();
                        }
                    }
                }
            }
        } catch (Exception $e){
            $this->logOptipricer(self::LOG_KEY.'_sendOrderStatus_'.$e);
            return;
        }
        $this->processCheckoutResult($errors, $cookiesToRemove);
    }

    /************************* AUXILIARY METHODS ***************************/

    /**
     * Check if the product has a discount and validate it if exists
     *
     * @param Mage_Sales_Model_Quote_Item $item             Item
     * @param Array                       $prodSessionItems Session Items
     * @param Boolean                     $cart             Add to Cart action
     *
     * @return string
     */
    private function validateQuoteItem($item, $prodSessionItems, $cart = true)
    {
        $error = null;
        try{
            $product = $item->getProduct();
            $productId = $item->getProductId();
            //Check if ProductId is in Session Checkout (SWE_Optipricer_Coupon_ID)
            $sessionKey = self::SWE_CUSTOM_OPTION_KEY.'_'.$productId;
            if ($prodSessionItems) {
                //Validate Session Parameters matching with the Cookie
                list($item, $error) = $this->validateSessionParamsWithCookie($item, $prodSessionItems,$sessionKey);
            } else {
                if ($cart && $this->hasCookie($this->token, $productId)) {
                    // Optipricer discount was activated and this is the first time adding to the cart
                    $cookieParams = $this->getCookieParams($this->token, $productId);
                    if (count($cookieParams)) {
                        $item = $this->changeItemPrice($item, $product->getFinalPrice(), $cookieParams['finalPrice']);
                        $item = $this->createSessionParameter($item);
                    } else {
                        $this->removeCookie('discount_'.$this->token.'_'.$productId);
                    }
                }
            }
            $item->save();
        } catch (Exception $e) {
            $this->logOptipricer(self::LOG_KEY.'_validateQuoteItem_'.$e);
        }

        return $error;
    }

    /**
     * Method to redeem a given coupon
     *
     * @param String $discountToken  Discount token
     *
     * @return bool
     */
    private function redeemCoupon($discountToken)
    {

        $url = $this->endPoint.self::URL_ENDPOINT_COUPON.
            self::URL_SEPARATOR.$discountToken.self::URL_SEPARATOR.self::URL_ENDPOINT_REDEEM;

        $client = new Zend_Http_Client($url);
        $client->setHeaders(Zend_Http_Client::CONTENT_TYPE, 'application/json');
        $client->setHeaders('Authorization', 'Token '.$this->token);
        $data = array(
            'tokens' => array($discountToken),
            'notes' => $this->products,
            'location' => ''
        );
        $securedData['data'] = $this->secureContent(json_encode($data));
        $client->setRawData(json_encode($securedData));
        try {
            $response = $client->request('PUT');
            return $response->isSuccessful();
        } catch (Exception $e) {
            $this->logOptipricer(self::LOG_KEY.'_redeemCoupon_'.$e);
            return false;
        }
    }

    /**
     * Method to collect all products from the quote
     *
     * @param Mage_Sales_Model_Service_Quote $quote Quote
     *
     * @throws Exception
     *
     * @return array
     */
    private function getAllQuoteProducts($quote)
    {
        $products = array();
        foreach($quote->getAllItems() as $item)
        {
            $productId = $item->getProductId();
            $product   = $item->getProduct();
            //Check if ProductId is in Session Checkout (SWE_Optipricer_Coupon_ID)
            $sessionItem = Mage::getSingleton('checkout/session')->getData(self::SWE_CUSTOM_OPTION_KEY.'_'.$productId);
            $productAux  = array();
            $productAux['productId'] = $productId;
            $productAux['name']      = $product->getName();
            if ($sessionItem) {
                $productAux['hasOptipricerDiscount'] = 'Yes';
            }
            $brand = $product->getAttributeText('manufacturer');
            $productAux['product_brand'] = $brand ? $brand : '';
            $categories = $product->getCategoryIds();
            $categoriesAux = array();
            foreach ($categories as $category_id) {
                $_cat = Mage::getModel('catalog/category')->load($category_id);
                if ($_cat->getName() && !in_array($_cat->getName(), $categoriesAux)) {
                    $categoriesAux[] = $_cat->getName();
                }
            }
            $productAux['categories'] = $categoriesAux;
            $productAux['finalValue'] = $product->getFinalPrice();
            $products[] = $productAux;
        }
        return $products;
    }

    /**
     * Get original price stored in the session
     *
     * @param  String $optiKeySession Optipricer Key Session
     *
     * @return string
     */
    private function getOriginalPriceBySession($optiKeySession)
    {
        $sessionItem = Mage::getSingleton('checkout/session')->getData($optiKeySession);
        $price = '';
        if ($sessionItem) {
            $productItems = unserialize($sessionItem);
            $price = $productItems['originalPrice'];
        }
        return $price;
    }

    /**
     * Remove cookie
     *
     * @param String $cookie Cookie Name
     *
     * @return void
     */
    private function removeCookie($cookie)
    {
        setcookie($cookie, null, -1, '/');
    }

    /**
     * Process Checkout Errors
     *
     * @param Array $errors Errors
     *
     * @return void
     */
    private function processCheckoutResult($errors)
    {
        if (count($errors)) {
            $result['success'] = false;
            $result['error'] = true;
            if (count($errors) > 1) {
                $message = 'Products ' . implode(', ', $errors) . ' are invalid! Please, edit your cart. (Optipricer)';
            } else {
                $message = 'Product ' . $errors[0] . ' is invalid! Please, edit your cart. (Optipricer)';
            }
            $result['error_messages'] = $message;
            $response = Mage::app()->getResponse();
            $response->setBody(Mage::helper('core')->jsonEncode($result));
            $response->sendResponse();
            exit();
        }
    }

    /**
     * Try to redeem coupon
     *
     * @param Mage_Sales_Model_Quote_Item $item         Item
     * @param Array                       $couponParams Coupon Parameters
     *
     * @return null|String
     */
    private function tryRedeem($item, $couponParams)
    {
        $error = null;
        $productId = $item->getProductId();
        $optiKeySession = self::SWE_CUSTOM_OPTION_KEY.'_'.$productId;

        //Try to Redeem coupon
        if (!$this->redeemCoupon($couponParams['couponToken'])) {
            $error = $item->getName();
        }

        $item = $this->unvalidateItemSession($item, $this->getOriginalPriceBySession($optiKeySession), $optiKeySession);
        $item->save();
        $this->removeCookie('discount_'.$this->token.'_'.$productId);

        return $error;
    }

    /**
     * Create Session Parameter
     *
     * @param Mage_Sales_Model_Quote_Item $item  Item
     *
     * @return Mage_Sales_Model_Quote_Item
     */
    private function createSessionParameter($item)
    {
        $product = $item->getProduct();
        $productId = $item->getProductId();
        //Check if ProductId is in Session Checkout (SWE_Optipricer_Coupon_ID)
        $sessionKey = self::SWE_CUSTOM_OPTION_KEY.'_'.$productId;
        $parms = array(
            'cookieName' => 'discount_'.$this->token.'_'.$productId,
            'originalPrice' => $product->getFinalPrice()
        );
        Mage::getSingleton('checkout/session')->setData($sessionKey, serialize($parms));

        return $item;
    }

    /**
     * Validate Session Parameters with Cookie
     *
     * @param Mage_Sales_Model_Quote_Item $item                Item
     * @param Array                       $productSessionItems Product Session items
     * @param String                      $optiKeySession      Api Key Session
     *
     * @return array
     */
    private function validateSessionParamsWithCookie($item, $productSessionItems, $optiKeySession)
    {
        $error = null;
        $productId = $item->getProductId();
        $originalPrice = $productSessionItems['originalPrice'];
        //Check if has cookie for this product
        if ($this->hasCookie($this->token, $productId)) {
            //Validate Cookie
            $cookieParams = $this->getCookieParams($this->token, $productId);
            //Product doesn't have a valid Cookie
            if (!$cookieParams) {
                $item = $this->unvalidateItemSession($item, $originalPrice, $optiKeySession);
                $this->removeCookie('discount_'.$this->token.'_'.$productId);
                $error = $item->getName();
            }
        } else {
            $item = $this->unvalidateItemSession($item, $originalPrice, $optiKeySession);
            $error = $item->getName();
        }
        return array($item, $error);
    }

    /**
     * Method to change the price of the item/product
     *
     * @param Mage_Sales_Model_Quote_Item $item          Item
     * @param Double                      $originalPrice Final Price
     * @param Double                      $finalValue    Final Price
     *
     * @return Mage_Sales_Model_Quote_Item
     */
    private function changeItemPrice($item, $originalPrice, $finalValue)
    {
        try {
            if ($finalValue > 0) {
                $item->setOriginalPrice($originalPrice);
                $item->setCustomPrice($finalValue);
                $item->setOriginalCustomPrice($finalValue);
            }
        } catch (Exception $e) {
            $this->logOptipricer(self::LOG_KEY.'_changeItemPrice_'.$e);
        }
        return $item;
    }

    /**
     * Unvalidate item session (restore price and unset session parameter of the product)
     *
     * @param Mage_Sales_Model_Quote_Item $item           Item Quote
     * @param Float                       $originalPrice  Original Price
     * @param String                      $optiKeySession Key for the session
     *
     * @return Mage_Sales_Model_Quote_Item
     */
    private function unvalidateItemSession($item, $originalPrice, $optiKeySession)
    {
        $item = $this->changeItemPrice($item, $originalPrice, $originalPrice);
        Mage::getSingleton('checkout/session')->unsetData($optiKeySession);

        return $item;
    }

    /**
     * Method to validate and get the DiscountToken and the Value from the cookie
     *
     * @param String $token     Token Store
     * @param Int    $productId Product Id
     *
     * @return Array
     */
    private function getCookieParams($token, $productId)
    {
        $result = array();
        $cookie = $_COOKIE['discount_'.$token.'_'.$productId];
        $dataAux = explode(':', $cookie);
        $data = $this->secureContent(json_encode($dataAux[0]), 'decrypt');
        $coupon = json_decode($data, true);
        if (is_array($coupon)) {
            //Get CouponToken
            $result['couponToken'] = array_key_exists('token', $coupon) ? $coupon['token'] : '';
            //Get Final Price
            $result['finalPrice'] = array_key_exists('value', $coupon) ? $coupon['value'] : '';
        }
        return $result;
    }

    /**
     * Check if a cookie exists
     *
     * @param String $token     TokenStore
     * @param String $productId ProductId
     *
     * @return bool
     */
    private function hasCookie($token, $productId)
    {
        return isset($_COOKIE['discount_'.$token.'_'.$productId]);
    }

    /**
     * Get Session parameter with a given key
     *
     * @param String $key Key to check
     *
     * @return array|null
     */
    private function getProductSessionItems($key)
    {
        $sessionItem  = Mage::getSingleton('checkout/session')->getData($key);
        $productItems = $sessionItem ? unserialize($sessionItem) : null;

        return $productItems;
    }

    /**
     * Log to Optipricer file log
     *
     * @param String $message Message
     * @param String $level   Level
     */
    public function logOptipricer($message, $level = null)
    {
        Mage::log($message, $level, 'OptipricerLogFile.log');
    }

    /**
     * Method to manage secure content
     *
     * @param string $content Content
     * @param string $task    Task
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
     * @return String
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
     * @return String
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
}
