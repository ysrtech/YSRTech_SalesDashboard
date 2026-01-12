<?php
/**
 * YSRTech SalesDashboard Adminhtml Controller
 *
 * @category   YSRTech
 * @package    YSRTech_SalesDashboard
 */
class YSRTech_SalesDashboard_Adminhtml_SalesdashboardController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Index action - main dashboard page
     */
    public function indexAction()
    {
        $this->loadLayout();
        $this->_setActiveMenu('sales/salesdashboard');
        $this->_title($this->__('Sales'))->_title($this->__('Sales Dashboard'));
        $this->renderLayout();
    }
    
    /**
     * AJAX action to refresh dashboard data
     */
    public function refreshAction()
    {
        $from = $this->getRequest()->getParam('from');
        $to = $this->getRequest()->getParam('to');
        $groupingAttribute = $this->getRequest()->getParam('grouping_attribute');
        
        $block = $this->getLayout()->createBlock('ysrtech_salesdashboard/adminhtml_dashboard');
        $block->setFromDate($from);
        $block->setToDate($to);
        if ($groupingAttribute) {
            $block->setGroupingAttribute($groupingAttribute);
        }
        
        $data = $block->getSalesData();
        
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($data));
    }
    
    /**
     * Check ACL permissions
     */
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('sales/salesdashboard');
    }
}
