<?php
/**
 * YSRTech SalesDashboard Block
 *
 * @category   YSRTech
 * @package    YSRTech_SalesDashboard
 */
class YSRTech_SalesDashboard_Block_Adminhtml_Dashboard extends Mage_Adminhtml_Block_Template
{
    protected $_fromDate;
    protected $_toDate;
    protected $_groupingAttribute;
    
    /**
     * Constructor
     */
    public function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech/salesdashboard/dashboard.phtml');
    }
    
    /**
     * Get default from date (30 days ago)
     */
    public function getDefaultFromDate()
    {
        return date('Y-m-d', strtotime('-30 days'));
    }
    
    /**
     * Get default to date (today)
     */
    public function getDefaultToDate()
    {
        return date('Y-m-d');
    }
    
    /**
     * Set from date
     */
    public function setFromDate($date)
    {
        $this->_fromDate = $date;
        return $this;
    }
    
    /**
     * Set to date
     */
    public function setToDate($date)
    {
        $this->_toDate = $date;
        return $this;
    }
    
    /**
     * Get from date
     */
    public function getFromDate()
    {
        if (!$this->_fromDate) {
            $this->_fromDate = $this->getDefaultFromDate();
        }
        return $this->_fromDate;
    }
    
    /**
     * Get to date
     */
    public function getToDate()
    {
        if (!$this->_toDate) {
            $this->_toDate = $this->getDefaultToDate();
        }
        return $this->_toDate;
    }
    
    /**
     * Set grouping attribute override
     */
    public function setGroupingAttribute($attribute)
    {
        $this->_groupingAttribute = $attribute;
        return $this;
    }
    
    /**
     * Get grouping attribute (override or config)
     */
    public function getGroupingAttribute()
    {
        if ($this->_groupingAttribute) {
            return $this->_groupingAttribute;
        }
        return Mage::getStoreConfig('ysrtech_salesdashboard/general/product_grouping_attribute');
    }
    
    /**
     * Get available product attributes for grouping
     */
    public function getAvailableGroupingAttributes()
    {
        $source = Mage::getModel('ysrtech_salesdashboard/system_config_source_product_attributes');
        return $source->toOptionArray();
    }
    
    /**
     * Get sales data for the selected period
     */
    public function getSalesData()
    {
        $from = $this->getFromDate() . ' 00:00:00';
        $to = $this->getToDate() . ' 23:59:59';
        
        // Get configured order statuses with safe fallback
        $statuses = Mage::getStoreConfig('ysrtech_salesdashboard/general/order_statuses');
        $statusArray = array();
        if (is_string($statuses) && $statuses !== '') {
            foreach (explode(',', $statuses) as $s) {
                $s = trim($s);
                if ($s !== '') {
                    $statusArray[] = $s;
                }
            }
        }
        if (empty($statusArray)) {
            $statusArray = array('complete');
        }
        
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $orderTable = Mage::getSingleton('core/resource')->getTableName('sales/order');
        $orderItemTable = Mage::getSingleton('core/resource')->getTableName('sales/order_item');
        $taxTable = Mage::getSingleton('core/resource')->getTableName('sales/order_tax');
        
        // Build status condition
        $statusConditions = array();
        foreach ($statusArray as $status) {
            $statusConditions[] = $read->quote($status);
        }
        $statusIn = implode(',', $statusConditions);
        
        // Get order totals using SQL
        $orderQuery = $read->select()
            ->from($orderTable, array(
                'order_count' => 'COUNT(*)',
                'total_grand_total' => 'SUM(grand_total)',
                'total_shipping' => 'SUM(shipping_amount)',
                'total_shipping_tax' => 'SUM(shipping_tax_amount)',
                'total_refunded' => 'SUM(IFNULL(total_refunded, 0))',
                'total_shipping_refunded' => 'SUM(IFNULL(shipping_refunded, 0))'
            ))
            ->where('created_at >= ?', $from)
            ->where('created_at <= ?', $to)
            ->where("status IN ($statusIn)");
        
        $orderTotals = $read->fetchRow($orderQuery);
        
        $totalRefunded = (float)$orderTotals['total_refunded'];
        $totalSalesWithShipping = (float)$orderTotals['total_grand_total'] - $totalRefunded;
        $totalShipping = (float)$orderTotals['total_shipping'] - (float)$orderTotals['total_shipping_refunded'];
        $totalSalesWithoutShipping = $totalSalesWithShipping - $totalShipping;
        
        // Get tax breakdown using SQL
        $taxQuery = $read->select()
            ->from(array('sot' => $taxTable), array(
                'code',
                'total_amount' => 'SUM(amount)'
            ))
            ->join(
                array('so' => $orderTable),
                'sot.order_id = so.entity_id',
                array()
            )
            ->where('so.created_at >= ?', $from)
            ->where('so.created_at <= ?', $to)
            ->where("so.status IN ($statusIn)")
            ->group('sot.code');
        
        $taxResults = $read->fetchAll($taxQuery);
        
        $taxesByZone = array();
        $totalTax = 0;
        $federalTax = 0;
        
        foreach ($taxResults as $tax) {
            $taxCode = $tax['code'];
            $taxAmount = (float)$tax['total_amount'];
            
            if ($taxAmount != 0) {
                $taxesByZone[$taxCode] = $taxAmount;
                $totalTax += $taxAmount;
                
                // Add to federal tax if it doesn't contain 'qst' or 'tvq'
                if (stripos($taxCode, 'qst') === false && stripos($taxCode, 'tvq') === false) {
                    $federalTax += $taxAmount;
                }
            }
        }
        
        // Get total items shipped using SQL
        $itemQuery = $read->select()
            ->from(array('soi' => $orderItemTable), array(
                'total_qty_shipped' => 'SUM(IFNULL(qty_shipped, 0))'
            ))
            ->join(
                array('so' => $orderTable),
                'soi.order_id = so.entity_id',
                array()
            )
            ->where('so.created_at >= ?', $from)
            ->where('so.created_at <= ?', $to)
            ->where("so.status IN ($statusIn)")
            ->where('soi.parent_item_id IS NULL');
        
        $itemTotals = $read->fetchRow($itemQuery);
        $totalItemsShipped = (float)$itemTotals['total_qty_shipped'];
        
        // Get products by attribute if grouping is enabled
        $productsByAttribute = array();
        $groupingAttribute = $this->getGroupingAttribute();
        
        if ($groupingAttribute) {
            $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $groupingAttribute);
            
            if ($attribute && $attribute->getId()) {
                $attributeTable = $attribute->getBackendTable();
                $attributeId = $attribute->getId();
                
                if ($attribute->getFrontendInput() == 'select' || $attribute->getFrontendInput() == 'multiselect') {
                    // For select/multiselect attributes, join with eav_attribute_option_value
                    $optionTable = Mage::getSingleton('core/resource')->getTableName('eav/attribute_option_value');
                    
                    $productQuery = $read->select()
                        ->from(array('soi' => $orderItemTable), array(
                            'total_qty' => 'SUM(IFNULL(soi.qty_shipped, 0))',
                            'total_amount' => 'SUM(soi.row_total)'
                        ))
                        ->join(
                            array('so' => $orderTable),
                            'soi.order_id = so.entity_id',
                            array()
                        )
                        ->joinLeft(
                            array('ea' => $attributeTable),
                            "soi.product_id = ea.entity_id AND ea.attribute_id = $attributeId AND ea.store_id = 0",
                            array()
                        )
                        ->joinLeft(
                            array('eaov' => $optionTable),
                            'ea.value = eaov.option_id AND eaov.store_id = 0',
                            array('attribute_value' => 'IFNULL(eaov.value, "Unassigned")')
                        )
                        ->where('so.created_at >= ?', $from)
                        ->where('so.created_at <= ?', $to)
                        ->where("so.status IN ($statusIn)")
                        ->where('soi.parent_item_id IS NULL')
                        ->group('eaov.value');
                } else {
                    // For text/varchar attributes
                    $productQuery = $read->select()
                        ->from(array('soi' => $orderItemTable), array(
                            'total_qty' => 'SUM(IFNULL(soi.qty_shipped, 0))',
                            'total_amount' => 'SUM(soi.row_total)'
                        ))
                        ->join(
                            array('so' => $orderTable),
                            'soi.order_id = so.entity_id',
                            array()
                        )
                        ->joinLeft(
                            array('ea' => $attributeTable),
                            "soi.product_id = ea.entity_id AND ea.attribute_id = $attributeId AND ea.store_id = 0",
                            array('attribute_value' => 'IFNULL(ea.value, "Unassigned")')
                        )
                        ->where('so.created_at >= ?', $from)
                        ->where('so.created_at <= ?', $to)
                        ->where("so.status IN ($statusIn)")
                        ->where('soi.parent_item_id IS NULL')
                        ->group('ea.value');
                }
                
                $productResults = $read->fetchAll($productQuery);
                
                foreach ($productResults as $product) {
                    $attrValue = $product['attribute_value'] ? $product['attribute_value'] : 'Unassigned';
                    $productsByAttribute[$attrValue] = array(
                        'qty' => (float)$product['total_qty'],
                        'amount' => (float)$product['total_amount']
                    );
                }
            }
        }
        
        // Format taxes
        $taxesFormatted = array();
        ksort($taxesByZone);
        
        foreach ($taxesByZone as $identifier => $amount) {
            $taxesFormatted[] = array(
                'identifier' => $identifier,
                'amount' => Mage::helper('core')->currency($amount, true, false),
                'amount_raw' => $amount
            );
        }
        
        // Format products
        $productsFormatted = array();
        ksort($productsByAttribute);
        
        foreach ($productsByAttribute as $attributeValue => $data) {
            $productsFormatted[] = array(
                'attribute' => $attributeValue,
                'qty' => number_format($data['qty'], 0),
                'qty_raw' => $data['qty'],
                'amount' => Mage::helper('core')->currency($data['amount'], true, false),
                'amount_raw' => $data['amount']
            );
        }
        
        return array(
            'total_sales_with_shipping' => Mage::helper('core')->currency($totalSalesWithShipping, true, false),
            'total_sales_without_shipping' => Mage::helper('core')->currency($totalSalesWithoutShipping, true, false),
            'total_sales_with_shipping_raw' => $totalSalesWithShipping,
            'total_sales_without_shipping_raw' => $totalSalesWithoutShipping,
            'total_refunded' => Mage::helper('core')->currency($totalRefunded, true, false),
            'total_refunded_raw' => $totalRefunded,
            'total_shipping' => Mage::helper('core')->currency($totalShipping, true, false),
            'total_shipping_raw' => $totalShipping,
            'total_tax' => Mage::helper('core')->currency($totalTax, true, false),
            'total_tax_raw' => $totalTax,
            'total_tax_display' => Mage::helper('core')->currency($totalTax, true, false),
            'federal_tax' => Mage::helper('core')->currency($federalTax, true, false),
            'federal_tax_raw' => $federalTax,
            'taxes_by_zone' => $taxesFormatted,
            'total_items_shipped' => number_format($totalItemsShipped, 0),
            'total_items_shipped_raw' => $totalItemsShipped,
            'products_by_attribute' => $productsFormatted,
            'grouping_attribute' => Mage::getStoreConfig('ysrtech_salesdashboard/general/product_grouping_attribute'),
            'order_count' => (int)$orderTotals['order_count']
        );
    }
    
    /**
     * Get refresh URL for AJAX
     */
    public function getRefreshUrl()
    {
        return $this->getUrl('*/*/refresh');
    }
}
