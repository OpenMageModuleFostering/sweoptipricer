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
class SWE_Optipricer_Block_Discount extends Mage_Core_Block_Template implements Mage_Widget_Block_Interface
{
    /**
     * @var String Token Store
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
     * @var bool EnabledLocal flag
     */
    private $enabledLocal;

    /**
     * @var Int Minimum Discount
     */
    private $minDiscount;

    /**
     * @var Int Maximum Discount
     */
    private $maxDiscount;

    /**
     * @var String EndPoint
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
     * @var String Background Color of the Button
     */
    private $backgroundColor;

    /**
     * @var String Font Color of the Button
     */
    private $colorFont;

    const HTTP_OK = 200;
    const URL_ENDPOINT_COUPON   = 'coupon';
    const URL_ENDPOINT_PAGEVIEW = 'pageview';

    /**
     * Initialization command
     *
     * @return void
     */
    protected function _construct()
    {
        $this->token           = Mage::getStoreConfig('swe/swe_group_activation/swe_token', Mage::app()->getStore());
        $this->key             = Mage::getStoreConfig('swe/swe_group_activation/swe_key', Mage::app()->getStore());
        $this->endPoint        = Mage::getStoreConfig('swe/swe_group_activation/swe_endpoint', Mage::app()->getStore());
        $this->enabledGlobal   = Mage::getStoreConfig('swe/swe_group_activation/swe_enable', Mage::app()->getStore());
        $this->minDiscount     = Mage::getStoreConfig('swe/swe_group_parameters/swe_min', Mage::app()->getStore());
        $this->maxDiscount     = Mage::getStoreConfig('swe/swe_group_parameters/swe_max', Mage::app()->getStore());
        $this->pageView        = Mage::getStoreConfig('swe/swe_group_parameters/swe_pageview', Mage::app()->getStore());
        $this->renderView      = Mage::getStoreConfig('swe/swe_group_parameters/swe_renderview', Mage::app()->getStore());
        $this->expiryOffset    = Mage::getStoreConfig('swe/swe_group_parameters/swe_expiryoffset', Mage::app()->getStore());
        $this->backgroundColor = Mage::getStoreConfig('swe/swe_group_parameters/swe_background_color', Mage::app()->getStore());
        $this->colorFont       = Mage::getStoreConfig('swe/swe_group_parameters/swe_font_color', Mage::app()->getStore());
        $this->locale          = Mage::app()->getLocale()->getLocaleCode();
        parent::_construct();
    }

    /**
     * Prepare Layout
     *
     * @return void
     */
    protected function _prepareLayout() {
        $this->getLayout()->getBlock('head')->addJs('swe/optipricer.min.js');
        $this->getLayout()->getBlock('head')->addJs('swe/optispin.min.js');
        $this->getLayout()->getBlock('head')->addJs('swe/optialert.min.js');
    }

    /**
     * Produces the widget html
     *
     * @return string
     */
    protected function _toHtml()
    {
        $this->loadLocalParameters();

        $result = $html = '';
        if (!$this->enabledGlobal || !$this->enabledLocal) {
            return $html;
        }

        //get ProductDetails and other relevant data to the requests
        $data = $this->getProductDetails();
        $data['min'] = $this->minDiscount;
        $data['max'] = $this->maxDiscount;
        $data['text'] = '';
        $data['discount_render'] = $this->renderView;
        $data['expiry_offset'] = $this->expiryOffset;
        $data['social_credentials'] = array('facebookId' => '', 'facebookToken' => '');
        $securedData['data'] = $this->secureContent(json_encode($data));
        $securedData['social_credentials'] = $data['social_credentials'];

        //PageView feature
        if ($this->pageView) {
            $result = $this->updatePageView($securedData, $this->renderView);
        }

        $securedData['store_token']     = $this->token;
        $securedData['product_id']      = $data['product_id'];
        $securedData['name']            = json_encode($data['name']);
        $securedData['url_api']         = $this->endPoint;
        $securedData['o_price']         = $data['price'];
        $securedData['locale']          = $this->locale;
        $securedData['currency']        = $data['currency'];
        $securedData['formatted_price'] = $data['formatted_price'];

        //Generate the Optipricer Button
        if ($result && $this->renderView) {
            if ($result['http_code'] == self::HTTP_OK && $result['content']) {
                return $result['content'];
            }
        }
        //Get text variables
        $transButton      = $this->__('Share and get a discount!');
        $transCommentInit = $this->__('Hey! Check this');
        $transCommentMid  = $this->__('I found on');
        $transCommentEnd  = $this->__('I loved it!');
        $name             = Mage::app()->getStore()->getFrontendName();
        $transComment     = $transCommentInit.' '.$data['name'].' '.$transCommentMid.' '.$name.'. '.$transCommentEnd;

        //Assign variables to be rendered in the template
        $this->assign('buttoncolor', '#'.$this->backgroundColor);
        $this->assign('fontcolor', '#'.$this->colorFont);
        $this->assign('transButton', $transButton);
        $this->assign('transComment', $transComment);
        $this->assign('offer', $securedData);

        return parent::_toHtml();
    }

    /**
     * Update Page View feature
     *
     * @param String $data       Data
     * @param Bool   $renderView RenderView flag
     *
     * @return mixed
     */
    private function updatePageView($data, $renderView = false)
    {
        $uriPageView = $this->endPoint.self::URL_ENDPOINT_PAGEVIEW;
        $headers = array();
        $headers[] = 'Authorization: Token '.$this->token;
        $headers[] = 'Accept-Language: '.$this->locale;
        if ($renderView) {
            $headers[] = 'Accept: text/html';
        }
        // Get cURL resource
        $response = $this->executeApiRequest($uriPageView, 'PUT', $headers, $data);

        return $response;
    }

    /**
     * Request execution
     *
     * @param String $uri     URL endpoint
     * @param String $method  Method
     * @param Array  $headers Headers
     * @param String $rawData Raw Data
     *
     * @return mixed
     */
    private function executeApiRequest($uri, $method, $headers = array(), $rawData = '')
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $uri);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        //FixMe: delete ssl options
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        if ($headers) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }
        if ($rawData) {
            $data = json_encode($rawData);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        // Send the request & save response to $content
        $content = curl_exec($curl);
        if(curl_errno($curl)) {
            curl_close($curl);
            return false;
        }
        $info = curl_getinfo($curl);
        $result['http_code'] = $info['http_code'];
        $result['content'] = $content;
        // Close request to clear up some resources
        curl_close($curl);

        return $result;
    }

    /**
     * get Product details
     *
     * @return array
     */
    private function getProductDetails()
    {
        $product = Mage::registry('current_product');
        $price   = $product->getFinalPrice();;
        $productDetails                    = array();
        $productDetails['product_id']      = $product->getId();
        $productDetails['name']            = $product->getName();
        $productDetails['description']     = $product->description;
        $productDetails['price']           = $price;
        $productDetails['product_barcode'] = $product->getBarcode();
        $productDetails['image_url']       = $product->getImageUrl();
        $productDetails['link']            = $product->getProductUrl();
        $brand = $product->getAttributeText('manufacturer');
        $productDetails['product_brand']   = $brand ? $brand : '';
        $categories = $product->getCategoryIds();
        $categoriesAux = array();
        foreach ($categories as $category_id) {
            $_cat = Mage::getModel('catalog/category')->load($category_id);
            if ($_cat->getName() && !in_array($_cat->getName(), $categoriesAux)) {
                $categoriesAux[] = $_cat->getName();
            }
        }
        $productDetails['categories']      = $categoriesAux;
        $currentCurrencyCode               = Mage::app()->getStore()->getBaseCurrencyCode();
        $currentCurrencySymbol             = Mage::app()->getLocale()->currency($currentCurrencyCode)->getSymbol();
        $productDetails['currency']        = $currentCurrencySymbol;
        $productDetails['formatted_price'] = Mage::helper('core')->formatPrice($price, false);

        return $productDetails;
    }

    /**
     * Load Local Parameters of the Widget
     *
     * @return void
     */
    private function loadLocalParameters()
    {
        $this->enabledLocal    = $this->getData('enable_service');
        $colorFont       = $this->getData('swe_font_color');
        $backgroundColor = $this->getData('swe_background_color');
        $expiryOffsetLocal     = $this->getData('swe_expiryoffset');

        if($colorFont) {
            $this->colorFont = $colorFont;
        }
        if($backgroundColor){
            $this->backgroundColor = $backgroundColor;
        }
        if ($expiryOffsetLocal) {
            $this->expiryOffset = $expiryOffsetLocal;
        }
        $minimumLocal = $this->getData('swe_min');
        if ($minimumLocal) {
            $this->minDiscount = $minimumLocal;
        }
        $maximumLocal = $this->getData('swe_max');
        if ($maximumLocal && !($maximumLocal < $this->minDiscount)) {
            $this->maxDiscount = $maximumLocal;
        }
        if($this->maxDiscount < $this->minDiscount) {
            $this->maxDiscount = $this->minDiscount;
        }
    }

    /**
     * Method to secure content
     *
     * @param String $content Content
     * @param String $task    Task to be done
     *
     * @return String
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
}
