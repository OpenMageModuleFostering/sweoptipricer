<?php

class SWE_Optipricer_Adminhtml_SweoptipricerController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Return some checking result
     *
     * @return void
     */
    public function checkAction()
    {
        $result = 1;
        Mage::app()->getResponse()->setBody($result);
    }
}