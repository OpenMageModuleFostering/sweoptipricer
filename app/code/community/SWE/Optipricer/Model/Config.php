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
class SWE_Optipricer_Model_Config
{
    public function toOptionArray()
    {
        $result = array();
        $result[] = array('value' => '0', 'label'=>' Local');
        $result[] = array('value' => '1', 'label'=>' Remote');

        return $result;
    }
}