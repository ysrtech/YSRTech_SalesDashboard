<?php
/**
 * YSRTech SalesDashboard Helper
 *
 * @category   YSRTech
 * @package    YSRTech_SalesDashboard
 */
class YSRTech_SalesDashboard_Helper_Data extends Mage_Core_Helper_Abstract
{
    /** @return array card reconciliation row kind => label */
    public function kindLabels()
    {
        return array(
            'capture'          => $this->__('Captured'),
            'refund'           => $this->__('Refunded'),
            'refund_offline'   => $this->__('Refunded offline'),
            'invoice_offline'  => $this->__('Invoiced without capture'),
            'capture_canceled' => $this->__('Captured, invoice canceled'),
            'invoice_canceled' => $this->__('Invoice canceled'),
        );
    }
}
