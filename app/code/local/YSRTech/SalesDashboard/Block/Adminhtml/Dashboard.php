<?php
/**
 * YSRTech SalesDashboard Block
 *
 * Reads the filters from the query string and hands the numbers from
 * YSRTech_SalesDashboard_Model_Report to the template.
 *
 * @category   YSRTech
 * @package    YSRTech_SalesDashboard
 */
class YSRTech_SalesDashboard_Block_Adminhtml_Dashboard extends Mage_Adminhtml_Block_Template
{
    /** @var YSRTech_SalesDashboard_Model_Report */
    protected $_report;

    public function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech/salesdashboard/dashboard.phtml');
    }

    /** @return YSRTech_SalesDashboard_Model_Report */
    public function getReport()
    {
        if ($this->_report === null) {
            $r = $this->getRequest();
            $this->_report = Mage::getModel('ysrtech_salesdashboard/report', array(
                'from'    => $r->getParam('from'),
                'to'      => $r->getParam('to'),
                'website' => $r->getParam('website'),
                'group'   => $r->getParam('group'),
                'channel' => $r->getParam('channel'),
            ));
        }
        return $this->_report;
    }

    /** @return array from, to, website, group, channel */
    public function getFilters()
    {
        return $this->getReport()->getFilters();
    }

    /** @return string attribute code products are grouped by ('' for none) */
    public function getGroupingAttribute()
    {
        $param = $this->getRequest()->getParam('grouping_attribute');
        if ($param !== null) {
            return (string) $param;
        }
        return (string) Mage::getStoreConfig('ysrtech_salesdashboard/general/product_grouping_attribute');
    }

    /** @return array */
    public function getAvailableGroupingAttributes()
    {
        $out = array();
        foreach (Mage::getModel('ysrtech_salesdashboard/system_config_source_product_attributes')->toOptionArray() as $option) {
            if ((string) $option['value'] !== '') {
                $out[] = $option;
            }
        }
        return $out;
    }

    /** @return array value => label for the customer type filter */
    public function getGroupOptions()
    {
        $options = array(
            YSRTech_SalesDashboard_Model_Report::GROUP_ALL       => $this->__('All customers'),
            YSRTech_SalesDashboard_Model_Report::GROUP_WHOLESALE => $this->__('Wholesale'),
            YSRTech_SalesDashboard_Model_Report::GROUP_RETAIL    => $this->__('Retail (all but wholesale)'),
        );
        foreach ($this->getReport()->getCustomerGroups() as $id => $name) {
            $options[(string) $id] = $this->__('Group: %s', $name);
        }
        return $options;
    }

    /** @return array value => label for the channel filter */
    public function getChannelOptions()
    {
        return array(
            YSRTech_SalesDashboard_Model_Report::CHANNEL_ALL     => $this->__('All channels'),
            YSRTech_SalesDashboard_Model_Report::CHANNEL_ONLINE  => $this->__('Online (website checkout)'),
            YSRTech_SalesDashboard_Model_Report::CHANNEL_MANUAL  => $this->__('Manual (entered in admin)'),
            YSRTech_SalesDashboard_Model_Report::CHANNEL_MARKETPLACE => $this->getMarketplaceLabel(),
        );
    }

    /** @return string label of the marketplace channel (orders with an external order id) */
    public function getMarketplaceLabel()
    {
        $label = trim((string) Mage::getStoreConfig('ysrtech_salesdashboard/general/marketplace_label'));
        return $label !== '' ? $label : $this->__('Marketplace import');
    }

    /** @return string */
    public function getChannelLabel($code)
    {
        $o = $this->getChannelOptions();
        return isset($o[$code]) ? $o[$code] : $code;
    }

    /** @return string the dashboard URL, for the filter form */
    public function getFormUrl()
    {
        return $this->getUrl('*/*/index');
    }

    /** @return string */
    public function getExportUrl()
    {
        $f = $this->getFilters();
        return $this->getUrl('*/*/export', array('_query' => array('from' => $f['from'], 'to' => $f['to'], 'website' => $f['website'])));
    }

    /** @return string */
    public function getConfigUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', array('section' => 'ysrtech_salesdashboard'));
    }

    /**
     * @param  int $orderId
     * @return string
     */
    public function getOrderUrl($orderId)
    {
        return $this->getUrl('adminhtml/sales_order/view', array('order_id' => $orderId));
    }

    /**
     * Admin URL for an invoice or credit memo row.
     *
     * @param  array $row
     * @return string
     */
    public function getDocUrl(array $row)
    {
        if (in_array($row['kind'], array('refund', 'refund_offline'), true)) {
            return $this->getUrl('adminhtml/sales_creditmemo/view', array('creditmemo_id' => $row['doc_id']));
        }
        return $this->getUrl('adminhtml/sales_invoice/view', array('invoice_id' => $row['doc_id']));
    }

    /**
     * @param  float $amount
     * @return string
     */
    public function money($amount)
    {
        return Mage::getModel('directory/currency')->load($this->getReport()->getCurrencyCode())->formatTxt((float) $amount);
    }

    /**
     * Readable date from a Y-m-d string.
     *
     * @param  string $ymd
     * @return string
     */
    public function day($ymd)
    {
        $d = DateTime::createFromFormat('Y-m-d', $ymd);
        return $d ? $d->format('D M j, Y') : $ymd;
    }

    /** @return array */
    public function kindLabels()
    {
        return Mage::helper('ysrtech_salesdashboard')->kindLabels();
    }

    /** @return string e.g. "America/Toronto" */
    public function getTimezoneName()
    {
        return $this->getReport()->getTimezone()->getName();
    }
}
