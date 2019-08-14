<?php
/**
 * Widget to share the product in Facebook and get a personalized discount
 *
 * @package   SWE_Optipricer
 * @author    Ubiprism Lda. / be.ubi <contact@beubi.com>
 * @copyright 2015 be.ubi
 * @license   GNU Lesser General Public License (LGPL)
 * @version   v.0.1.2
 */
class SWE_Optipricer_Block_Button extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    const URL_ENDPOINT_CONTACT = 'contact/email';

    /**
     * Construct command
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('swe/optipricer/system/config/button.phtml');
    }

    /**
     * Return element html
     *
     * @param  Varien_Data_Form_Element_Abstract $element Element
     *
     * @return String
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        return $this->_toHtml();
    }

    /**
     * Return ajax url for button
     *
     * @return String
     */
    public function getAjaxCheckUrl()
    {
        $endPoint = Mage::getStoreConfig('swe/swe_group_activation/swe_endpoint', Mage::app()->getStore());
        $uriPageView = $endPoint.self::URL_ENDPOINT_CONTACT;

        return $uriPageView;
    }

    /**
     * Generate button html
     *
     * @return String
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setData(array(
                'id'        => 'swe_optipricer_button',
                'label'     => $this->helper('adminhtml')->__('Send Contact'),
                'onclick'   => 'javascript:check(); return false;'
            ));

        return $button->toHtml();
    }
}
