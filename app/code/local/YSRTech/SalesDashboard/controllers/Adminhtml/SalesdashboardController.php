<?php
/**
 * YSRTech SalesDashboard Adminhtml Controller
 *
 * Routes (adminhtml/salesdashboard/*):
 *   index   from, to, website, group, channel, grouping_attribute   the dashboard (filters in the query string)
 *   export  from, to, website                             CSV of the card reconciliation transactions
 *
 * @category   YSRTech
 * @package    YSRTech_SalesDashboard
 */
class YSRTech_SalesDashboard_Adminhtml_SalesdashboardController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Main dashboard page
     */
    public function indexAction()
    {
        $this->loadLayout();
        $this->_setActiveMenu('sales/salesdashboard');
        $this->_title($this->__('Sales'))->_title($this->__('Sales Dashboard'));
        $this->renderLayout();
    }

    /**
     * Card reconciliation as CSV: one line per capture or refund, then one line per day.
     */
    public function exportAction()
    {
        $report = Mage::getModel('ysrtech_salesdashboard/report', array(
            'from' => $this->getRequest()->getParam('from'),
            'to'      => $this->getRequest()->getParam('to'),
            'website' => $this->getRequest()->getParam('website'),
        ));
        $data = $report->getCardReconciliation();
        $f = $report->getFilters();
        $labels = Mage::helper('ysrtech_salesdashboard')->kindLabels();

        $out = fopen('php://temp', 'w+');
        fputcsv($out, array('Date', 'Time', 'Type', 'Order', 'Invoice / Credit memo', 'Gateway transaction', 'Payment method', 'Amount'));
        foreach ($data['rows'] as $r) {
            $sign = in_array($r['kind'], array('refund', 'refund_offline'), true) ? -1 : 1;
            fputcsv($out, array($r['date'], $r['time'], $labels[$r['kind']], $r['order_no'], $r['doc'], $r['txn'], $r['method'], number_format($sign * $r['amount'], 2, '.', '')));
        }
        fputcsv($out, array());
        fputcsv($out, array('Date', 'Captured', 'Refunded', 'Net', 'Refunded offline (not through gateway)', 'Invoiced without capture', 'Canceled'));
        foreach ($data['days'] as $day => $d) {
            fputcsv($out, array($day, number_format($d['captured'], 2, '.', ''), number_format($d['refunded'], 2, '.', ''), number_format($d['net'], 2, '.', ''),
                number_format($d['refunded_offline'], 2, '.', ''), number_format($d['invoiced_offline'], 2, '.', ''), number_format($d['canceled'], 2, '.', '')));
        }
        $t = $data['totals'];
        fputcsv($out, array('Total', number_format($t['captured'], 2, '.', ''), number_format($t['refunded'], 2, '.', ''), number_format($t['net'], 2, '.', ''),
            number_format($t['refunded_offline'], 2, '.', ''), number_format($t['invoiced_offline'], 2, '.', ''), number_format($t['canceled'], 2, '.', '')));
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $this->_prepareDownloadResponse('card-reconciliation-' . $f['from'] . '-to-' . $f['to'] . '.csv', $csv, 'text/csv');
    }

    /**
     * Check ACL permissions
     */
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('sales/salesdashboard');
    }
}
