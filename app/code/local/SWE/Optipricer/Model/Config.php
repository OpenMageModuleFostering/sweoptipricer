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