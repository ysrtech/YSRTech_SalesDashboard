<?php
/**
 * The numbers behind the Sales Dashboard.
 *
 * Two bases, kept apart on purpose:
 *  - Sales: orders PLACED in the period (order statuses from config), filtered by
 *    customer type and channel.
 *  - Returns & credits: credit memos CREATED in the period, for orders matching the
 *    same customer type and channel filters (any order status).
 *
 * Card reconciliation is a cash view for matching gateway deposits: every card
 * capture and refund that went through the payment gateway in the period, by the
 * store's local date, for all customers and channels.
 *
 * All amounts are in base currency. Websites with different base currencies are
 * never added up: the website filter is one website or all websites of one base
 * currency. Dates given as Y-m-d are the store's local dates
 * (general/locale/timezone) and are converted to UTC for the queries.
 *
 * @category   YSRTech
 * @package    YSRTech_SalesDashboard
 */
class YSRTech_SalesDashboard_Model_Report
{
    const XML_PATH_STATUSES         = 'ysrtech_salesdashboard/general/order_statuses';
    const XML_PATH_CARD_METHODS     = 'ysrtech_salesdashboard/reconciliation/card_methods';
    const XML_PATH_WHOLESALE_GROUPS = 'ysrtech_salesdashboard/general/wholesale_groups';
    const XML_PATH_RETAIL_GROUPS    = 'ysrtech_salesdashboard/general/retail_groups';

    const CHANNEL_ALL     = 'all';
    const CHANNEL_ONLINE  = 'online';
    const CHANNEL_MANUAL  = 'manual';
    const CHANNEL_MARKETPLACE = 'marketplace';
    /** @deprecated old name of the marketplace channel (Shopify imports) */
    const CHANNEL_SHOPIFY = 'marketplace';

    const GROUP_ALL       = 'all';
    const GROUP_WHOLESALE = 'wholesale';
    const GROUP_RETAIL    = 'retail';

    /** @var Varien_Db_Adapter_Interface */
    protected $_read;

    /** @var array table => [column => true] */
    protected $_columns = array();

    /** @var array normalised filters */
    protected $_filters;

    /**
     * @param array $filters from, to (Y-m-d local), website, group, channel
     */
    public function __construct(array $filters = array())
    {
        $this->_read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $this->_filters = $this->normaliseFilters($filters);
    }

    // ---- filters ---------------------------------------------------------------------

    /**
     * @param  array $f
     * @return array
     */
    public function normaliseFilters(array $f)
    {
        $date = function ($v, $fallback) {
            $v = trim((string) $v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $fallback;
        };
        $today = $this->localNow()->format('Y-m-d');
        $from = $date(isset($f['from']) ? $f['from'] : '', $this->localNow()->modify('-30 days')->format('Y-m-d'));
        $to   = $date(isset($f['to']) ? $f['to'] : '', $today);
        if ($from > $to) {
            list($from, $to) = array($to, $from);
        }
        $group = isset($f['group']) ? (string) $f['group'] : self::GROUP_ALL;
        if (!in_array($group, array(self::GROUP_ALL, self::GROUP_WHOLESALE, self::GROUP_RETAIL), true) && !ctype_digit($group)) {
            $group = self::GROUP_ALL;
        }
        $channel = isset($f['channel']) ? (string) $f['channel'] : self::CHANNEL_ALL;
        if ($channel === 'shopify') {
            $channel = self::CHANNEL_MARKETPLACE;
        }
        if (!in_array($channel, array(self::CHANNEL_ALL, self::CHANNEL_ONLINE, self::CHANNEL_MANUAL, self::CHANNEL_MARKETPLACE), true)) {
            $channel = self::CHANNEL_ALL;
        }
        $website = isset($f['website']) ? (string) $f['website'] : '';
        if (!array_key_exists($website, $this->getWebsiteOptions())) {
            $website = $this->getDefaultWebsiteFilter();
        }
        return array('from' => $from, 'to' => $to, 'website' => $website, 'group' => $group, 'channel' => $channel);
    }

    // ---- websites and currencies --------------------------------------------------------

    /** @return array currency code => [website id => name] */
    public function getWebsitesByCurrency()
    {
        $out = array();
        foreach (Mage::app()->getWebsites() as $website) {
            $out[(string) $website->getBaseCurrencyCode()][(int) $website->getId()] = $website->getName();
        }
        ksort($out);
        return $out;
    }

    /**
     * Website filter values: "cur:CAD" (all websites in that base currency) and each website id.
     *
     * @return array value => label
     */
    public function getWebsiteOptions()
    {
        $byCurrency = $this->getWebsitesByCurrency();
        $out = array();
        foreach ($byCurrency as $code => $websites) {
            $out['cur:' . $code] = count($byCurrency) > 1
                ? Mage::helper('ysrtech_salesdashboard')->__('All %s websites', $code)
                : Mage::helper('ysrtech_salesdashboard')->__('All websites');
        }
        foreach ($byCurrency as $code => $websites) {
            foreach ($websites as $id => $name) {
                $out[(string) $id] = count($byCurrency) > 1 ? $name . ' (' . $code . ')' : $name;
            }
        }
        return $out;
    }

    /** @return string all websites in the default base currency */
    public function getDefaultWebsiteFilter()
    {
        $code = (string) Mage::app()->getStore(Mage_Core_Model_App::ADMIN_STORE_ID)->getBaseCurrencyCode();
        $byCurrency = $this->getWebsitesByCurrency();
        if (!isset($byCurrency[$code]) && $byCurrency) {
            $code = key($byCurrency);
        }
        return 'cur:' . $code;
    }

    /** @return string base currency code of the amounts shown */
    public function getCurrencyCode()
    {
        $w = $this->_filters['website'];
        if (strpos($w, 'cur:') === 0) {
            return substr($w, 4);
        }
        return (string) Mage::app()->getWebsite((int) $w)->getBaseCurrencyCode();
    }

    /**
     * Restrict a select on orders aliased $o to the website filter.
     *
     * @param  Varien_Db_Select $select
     * @param  string $o
     * @return Varien_Db_Select
     */
    protected function _applyWebsiteFilter(Varien_Db_Select $select, $o = 'o')
    {
        $w = $this->_filters['website'];
        if (strpos($w, 'cur:') === 0) {
            $select->where("$o.base_currency_code = ?", substr($w, 4));
        } else {
            $select->where("$o.store_id IN (?)", array_merge(array(-1), Mage::app()->getWebsite((int) $w)->getStoreIds()));
        }
        return $select;
    }

    /** @return array */
    public function getFilters()
    {
        return $this->_filters;
    }

    /** @return DateTimeZone */
    public function getTimezone()
    {
        $tz = (string) Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE);
        try {
            return new DateTimeZone($tz !== '' ? $tz : 'UTC');
        } catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }

    /** @return DateTime */
    public function localNow()
    {
        return new DateTime('now', $this->getTimezone());
    }

    /**
     * UTC bounds for the local from/to dates (whole days).
     *
     * @return array [from 'Y-m-d H:i:s', to 'Y-m-d H:i:s'] in UTC
     */
    public function getUtcRange()
    {
        $tz = $this->getTimezone();
        $utc = new DateTimeZone('UTC');
        $from = new DateTime($this->_filters['from'] . ' 00:00:00', $tz);
        $to   = new DateTime($this->_filters['to'] . ' 23:59:59', $tz);
        return array($from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->setTimezone($utc)->format('Y-m-d H:i:s'));
    }

    /**
     * Store-local date of a UTC timestamp.
     *
     * @param  string $utc 'Y-m-d H:i:s'
     * @param  string $format
     * @return string
     */
    public function localDate($utc, $format = 'Y-m-d')
    {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        return $d->setTimezone($this->getTimezone())->format($format);
    }

    /**
     * Customer group ids treated as wholesale: the configured wholesale groups, or, when
     * retail groups are configured instead, every group that is not one of them (nor
     * NOT LOGGED IN).
     *
     * @return int[]
     */
    public function getWholesaleGroups()
    {
        $retail = array();
        foreach (explode(',', (string) Mage::getStoreConfig(self::XML_PATH_RETAIL_GROUPS)) as $id) {
            if (trim($id) !== '' && ctype_digit(trim($id))) {
                $retail[] = (int) trim($id);
            }
        }
        if ($retail) {
            $retail[] = Mage_Customer_Model_Group::NOT_LOGGED_IN_ID;   // guests are always retail
            return array_values(array_diff(array_keys($this->getCustomerGroups()), $retail));
        }
        $ids = array();
        foreach (explode(',', (string) Mage::getStoreConfig(self::XML_PATH_WHOLESALE_GROUPS)) as $id) {
            if (trim($id) !== '' && ctype_digit(trim($id))) {
                $ids[] = (int) trim($id);
            }
        }
        return $ids ? $ids : array(2);
    }

    /** @return string[] payment method codes that charge a card through the gateway */
    public function getCardMethods()
    {
        $codes = array();
        foreach (explode(',', (string) Mage::getStoreConfig(self::XML_PATH_CARD_METHODS)) as $code) {
            if (trim($code) !== '') {
                $codes[] = trim($code);
            }
        }
        return $codes ? $codes : array('authorizenet', 'solapayments');
    }

    /** @return string[] order statuses counted as sales */
    public function getStatuses()
    {
        $out = array();
        foreach (explode(',', (string) Mage::getStoreConfig(self::XML_PATH_STATUSES)) as $s) {
            if (trim($s) !== '') {
                $out[] = trim($s);
            }
        }
        return $out ? $out : array('complete');
    }

    /**
     * SQL condition for the channel of order alias $o.
     *
     * Marketplace imports (Shopify, Faire…) carry ext_order_id; storefront checkouts
     * carry the shopper's remote_ip; orders entered in the admin (or by scripts) have
     * neither.
     *
     * @param  string $channel
     * @param  string $o table alias
     * @return string|null
     */
    public function channelCondition($channel, $o = 'o')
    {
        $market  = "(IFNULL($o.ext_order_id, '') <> '')";
        $hasIp   = "(IFNULL($o.remote_ip, '') <> '')";
        switch ($channel) {
            case self::CHANNEL_MARKETPLACE:
                return $market;
            case self::CHANNEL_ONLINE:
                return "(NOT $market AND $hasIp)";
            case self::CHANNEL_MANUAL:
                return "(NOT $market AND NOT $hasIp)";
        }
        return null;
    }

    /**
     * Apply website, customer type and channel filters to a select on orders aliased $o.
     *
     * @param  Varien_Db_Select $select
     * @param  string $o
     * @return Varien_Db_Select
     */
    protected function _applyOrderFilters(Varien_Db_Select $select, $o = 'o')
    {
        $this->_applyWebsiteFilter($select, $o);
        $group = $this->_filters['group'];
        if ($group === self::GROUP_WHOLESALE) {
            $select->where("$o.customer_group_id IN (?)", $this->getWholesaleGroups());
        } elseif ($group === self::GROUP_RETAIL) {
            $select->where("IFNULL($o.customer_group_id, 0) NOT IN (?)", $this->getWholesaleGroups());
        } elseif (ctype_digit($group)) {
            $select->where("$o.customer_group_id = ?", (int) $group);
        }
        $cond = $this->channelCondition($this->_filters['channel'], $o);
        if ($cond) {
            $select->where($cond);
        }
        return $select;
    }

    // ---- helpers -----------------------------------------------------------------------

    /**
     * @param  string $entity e.g. 'sales/order'
     * @return string
     */
    protected function _table($entity)
    {
        return Mage::getSingleton('core/resource')->getTableName($entity);
    }

    /**
     * Column expression that falls back to 0 when the column does not exist
     * (store credit and fee columns of other extensions are optional).
     *
     * @param  string $entity
     * @param  string $alias
     * @param  string $column
     * @return string
     */
    protected function _col($entity, $alias, $column)
    {
        $table = $this->_table($entity);
        if (!isset($this->_columns[$table])) {
            $this->_columns[$table] = array_fill_keys(array_keys($this->_read->describeTable($table)), true);
        }
        return isset($this->_columns[$table][$column]) ? "IFNULL($alias.$column, 0)" : '0';
    }

    /**
     * @return Varien_Db_Select orders placed in the period, sales statuses, filters applied
     */
    protected function _salesOrders()
    {
        list($from, $to) = $this->getUtcRange();
        $select = $this->_read->select()
            ->from(array('o' => $this->_table('sales/order')), array())
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to)
            ->where('o.status IN (?)', $this->getStatuses());
        return $this->_applyOrderFilters($select);
    }

    // ---- sales ---------------------------------------------------------------------------

    /**
     * Orders placed in the period.
     *
     * order_total is what the order came to before store credit; it is paid by
     * internal credit (store credit) and by the payment method (grand total).
     *
     * @return array
     */
    public function getSalesSummary()
    {
        $credit = $this->_col('sales/order', 'o', 'base_customer_credit_amount');
        $fee    = $this->_col('sales/order', 'o', 'iwd_om_fee_base_amount');
        $select = $this->_salesOrders()->columns(array(
            'orders'      => 'COUNT(*)',
            'qty_ordered' => 'SUM(IFNULL(o.total_qty_ordered, 0))',
            'gross'       => 'SUM(IFNULL(o.base_subtotal, 0))',
            'discount'    => 'SUM(IFNULL(o.base_discount_amount, 0))',
            'shipping'    => 'SUM(IFNULL(o.base_shipping_amount, 0))',
            'tax'         => 'SUM(IFNULL(o.base_tax_amount, 0))',
            'fees'        => "SUM($fee)",
            'credit'      => "SUM($credit)",
            'paid'        => 'SUM(IFNULL(o.base_grand_total, 0))',
        ));
        $r = array_map('floatval', (array) $this->_read->fetchRow($select));
        $r += array('orders' => 0, 'qty_ordered' => 0, 'gross' => 0, 'discount' => 0, 'shipping' => 0, 'tax' => 0, 'fees' => 0, 'credit' => 0, 'paid' => 0);
        $r['discount'] = -abs($r['discount']);
        $r['order_total'] = round($r['paid'] + $r['credit'], 2);
        // Whatever the parts do not explain (imported totals, hidden tax, rounding).
        $r['other'] = round($r['order_total'] - ($r['gross'] + $r['discount'] + $r['shipping'] + $r['tax'] + $r['fees']), 2);
        $r['orders'] = (int) $r['orders'];

        $items = $this->_salesOrders()
            ->join(array('i' => $this->_table('sales/order_item')), 'i.order_id = o.entity_id', array(
                'shipped' => 'SUM(IFNULL(i.qty_shipped, 0))',
            ))
            ->where('i.parent_item_id IS NULL');
        $r['qty_shipped'] = (float) $this->_read->fetchOne($items);
        return $r;
    }

    /**
     * Orders placed in the period by payment method.
     *
     * @return array list of ['method', 'title', 'orders', 'paid', 'credit']
     */
    public function getSalesByPaymentMethod()
    {
        $credit = $this->_col('sales/order', 'o', 'base_customer_credit_amount');
        $select = $this->_salesOrders()
            ->join(array('p' => $this->_table('sales/order_payment')), 'p.parent_id = o.entity_id', array('method'))
            ->columns(array(
                'orders' => 'COUNT(*)',
                'paid'   => 'SUM(IFNULL(o.base_grand_total, 0))',
                'credit' => "SUM($credit)",
            ))
            ->group('p.method')
            ->order('paid DESC');
        $out = array();
        foreach ($this->_read->fetchAll($select) as $row) {
            $out[] = array(
                'method' => $row['method'],
                'title'  => $this->methodTitle($row['method']),
                'orders' => (int) $row['orders'],
                'paid'   => (float) $row['paid'],
                'credit' => (float) $row['credit'],
            );
        }
        return $out;
    }

    /**
     * Orders placed in the period by channel and by customer type, ignoring the
     * filter of that same kind so the split is always visible.
     *
     * @return array ['channels' => [code => [orders, total]], 'groups' => [label => [orders, total]]]
     */
    public function getSplits()
    {
        $credit = $this->_col('sales/order', 'o', 'base_customer_credit_amount');
        $total  = "SUM(IFNULL(o.base_grand_total, 0) + $credit)";
        list($from, $to) = $this->getUtcRange();
        $base = function () use ($from, $to) {
            return $this->_read->select()
                ->from(array('o' => $this->_table('sales/order')), array())
                ->where('o.created_at >= ?', $from)
                ->where('o.created_at <= ?', $to)
                ->where('o.status IN (?)', $this->getStatuses());
        };
        $saved = $this->_filters;

        $channels = array();
        $this->_filters['channel'] = self::CHANNEL_ALL;
        foreach (array(self::CHANNEL_ONLINE, self::CHANNEL_MANUAL, self::CHANNEL_MARKETPLACE) as $ch) {
            $select = $this->_applyOrderFilters($base())->where($this->channelCondition($ch))->columns(array('n' => 'COUNT(*)', 't' => $total));
            $row = $this->_read->fetchRow($select);
            $channels[$ch] = array('orders' => (int) $row['n'], 'total' => (float) $row['t']);
        }
        $this->_filters = $saved;

        $groups = array();
        $this->_filters['group'] = self::GROUP_ALL;
        $select = $this->_applyOrderFilters($base())
            ->columns(array('g' => 'IFNULL(o.customer_group_id, 0)', 'n' => 'COUNT(*)', 't' => $total))
            ->group('g');
        $names = $this->getCustomerGroups();
        $wholesale = $this->getWholesaleGroups();
        foreach ($this->_read->fetchAll($select) as $row) {
            $id = (int) $row['g'];
            $groups[] = array(
                'id'        => $id,
                'name'      => isset($names[$id]) ? $names[$id] : ('#' . $id),
                'wholesale' => in_array($id, $wholesale, true),
                'orders'    => (int) $row['n'],
                'total'     => (float) $row['t'],
            );
        }
        $this->_filters = $saved;
        return array('channels' => $channels, 'groups' => $groups);
    }

    /**
     * Tax on orders placed in the period, by rate code.
     *
     * @return array code => amount
     */
    public function getTaxByRate()
    {
        $select = $this->_salesOrders()
            ->join(array('t' => $this->_table('sales/order_tax')), 't.order_id = o.entity_id', array(
                'code',
                'amount' => 'SUM(IFNULL(t.base_amount, t.amount))',
            ))
            ->group('t.code')
            ->order('t.code');
        $out = array();
        foreach ($this->_read->fetchAll($select) as $row) {
            if ((float) $row['amount'] != 0) {
                $out[$row['code']] = (float) $row['amount'];
            }
        }
        return $out;
    }

    /**
     * Products sold in the period grouped by a product attribute.
     *
     * @param  string $attributeCode
     * @return array label => ['qty' => float, 'amount' => float]
     */
    public function getProductsByAttribute($attributeCode)
    {
        if (!$attributeCode) {
            return array();
        }
        $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $attributeCode);
        if (!$attribute || !$attribute->getId() || $attribute->getBackendType() === 'static') {
            return array();
        }
        $select = $this->_salesOrders()
            ->join(array('i' => $this->_table('sales/order_item')), 'i.order_id = o.entity_id', array(
                'qty'    => 'SUM(IFNULL(i.qty_ordered, 0) - IFNULL(i.qty_canceled, 0))',
                'amount' => 'SUM(IFNULL(i.base_row_total, 0) - IFNULL(i.base_discount_amount, 0))',
            ))
            ->where('i.parent_item_id IS NULL')
            ->joinLeft(
                array('ea' => $attribute->getBackendTable()),
                $this->_read->quoteInto('ea.entity_id = i.product_id AND ea.store_id = 0 AND ea.attribute_id = ?', (int) $attribute->getId()),
                array()
            );
        if (in_array($attribute->getFrontendInput(), array('select', 'multiselect'), true)) {
            $select->joinLeft(
                array('ov' => $this->_table('eav/attribute_option_value')),
                'ov.option_id = ea.value AND ov.store_id = 0',
                array('label' => "IFNULL(ov.value, 'Unassigned')")
            )->group('ov.value');
        } else {
            $select->columns(array('label' => "IFNULL(ea.value, 'Unassigned')"))->group('ea.value');
        }
        $out = array();
        foreach ($this->_read->fetchAll($select) as $row) {
            $label = (string) $row['label'] !== '' ? (string) $row['label'] : 'Unassigned';
            $out[$label] = array('qty' => (float) $row['qty'], 'amount' => (float) $row['amount']);
        }
        ksort($out);
        return $out;
    }

    // ---- returns & credits ----------------------------------------------------------------

    /**
     * Credit memos created in the period, for orders matching the filters.
     *
     * to_payment is what went back to the payment method (card, cheque…);
     * to_credit is what went back as store credit.
     *
     * @return array
     */
    public function getRefundSummary()
    {
        list($from, $to) = $this->getUtcRange();
        $select = $this->_read->select()
            ->from(array('c' => $this->_table('sales/creditmemo')), array(
                'memos'          => 'COUNT(*)',
                'items'          => 'SUM(IFNULL(c.base_subtotal, 0))',
                'discount'       => 'SUM(IFNULL(c.base_discount_amount, 0))',
                'shipping'       => 'SUM(IFNULL(c.base_shipping_amount, 0))',
                'tax'            => 'SUM(IFNULL(c.base_tax_amount, 0))',
                'adj_refund'     => 'SUM(IFNULL(c.base_adjustment_positive, 0))',
                'adj_fee'        => 'SUM(IFNULL(c.base_adjustment_negative, 0))',
                'return_fee'     => 'SUM(' . $this->_col('sales/creditmemo', 'c', 'base_return_shipping_fee') . ')',
                'restocking_fee' => 'SUM(' . $this->_col('sales/creditmemo', 'c', 'base_restocking_fee') . ')',
                'to_payment'     => 'SUM(IFNULL(c.base_grand_total, 0))',
                'to_credit'      => 'SUM(' . $this->_col('sales/creditmemo', 'c', 'base_customer_credit_amount') . ')',
            ))
            ->join(array('o' => $this->_table('sales/order')), 'o.entity_id = c.order_id', array())
            ->where('c.created_at >= ?', $from)
            ->where('c.created_at <= ?', $to)
            ->where('c.state <> ?', Mage_Sales_Model_Order_Creditmemo::STATE_CANCELED);
        $this->_applyOrderFilters($select);
        $r = array_map('floatval', (array) $this->_read->fetchRow($select));
        $r['memos'] = (int) $r['memos'];
        $r['discount'] = -abs($r['discount']);
        $r['total'] = round($r['to_payment'] + $r['to_credit'], 2);
        return $r;
    }

    // ---- card reconciliation --------------------------------------------------------------

    /**
     * Every card capture and refund in the period on the filtered websites, all
     * customers and channels.
     *
     * A capture is an invoice on a card-method order that carries the gateway's
     * transaction id; a refund is a credit memo on such an order with a transaction
     * id ("Refund", not "Refund Offline"). Invoices and memos on card orders WITHOUT
     * a transaction id did not go through the gateway and are listed apart, as are
     * canceled invoices, so differences with the deposits can be traced.
     *
     * @return array ['days' => [date => [...]], 'totals' => [...], 'rows' => [...], 'methods' => [...]]
     */
    public function getCardReconciliation()
    {
        list($from, $to) = $this->getUtcRange();
        $methods = $this->getCardMethods();
        $rows = array();

        $invoices = $this->_read->select()
            ->from(array('i' => $this->_table('sales/invoice')), array(
                'id' => 'i.entity_id', 'doc' => 'i.increment_id', 'created_at', 'amount' => 'i.base_grand_total',
                'txn' => 'i.transaction_id', 'state' => 'i.state',
            ))
            ->join(array('o' => $this->_table('sales/order')), 'o.entity_id = i.order_id', array('order_no' => 'o.increment_id', 'order_id' => 'o.entity_id'))
            ->join(array('p' => $this->_table('sales/order_payment')), 'p.parent_id = o.entity_id', array('method'))
            ->where('p.method IN (?)', $methods)
            ->where('i.created_at >= ?', $from)
            ->where('i.created_at <= ?', $to);
        $this->_applyWebsiteFilter($invoices);
        foreach ($this->_read->fetchAll($invoices) as $r) {
            $online = trim((string) $r['txn']) !== '';
            if ((int) $r['state'] === Mage_Sales_Model_Order_Invoice::STATE_CANCELED) {
                $kind = $online ? 'capture_canceled' : 'invoice_canceled';
            } else {
                $kind = $online ? 'capture' : 'invoice_offline';
            }
            $rows[] = $this->_row($r, $kind);
        }

        $memos = $this->_read->select()
            ->from(array('c' => $this->_table('sales/creditmemo')), array(
                'id' => 'c.entity_id', 'doc' => 'c.increment_id', 'created_at', 'amount' => 'c.base_grand_total',
                'txn' => 'c.transaction_id', 'state' => 'c.state',
            ))
            ->join(array('o' => $this->_table('sales/order')), 'o.entity_id = c.order_id', array('order_no' => 'o.increment_id', 'order_id' => 'o.entity_id'))
            ->join(array('p' => $this->_table('sales/order_payment')), 'p.parent_id = o.entity_id', array('method'))
            ->where('p.method IN (?)', $methods)
            ->where('c.created_at >= ?', $from)
            ->where('c.created_at <= ?', $to)
            ->where('c.state <> ?', Mage_Sales_Model_Order_Creditmemo::STATE_CANCELED);
        $this->_applyWebsiteFilter($memos);
        foreach ($this->_read->fetchAll($memos) as $r) {
            $rows[] = $this->_row($r, trim((string) $r['txn']) !== '' ? 'refund' : 'refund_offline');
        }

        usort($rows, function ($a, $b) {
            return strcmp($a['created_at'], $b['created_at']);
        });

        $blank = array('captured' => 0.0, 'captures' => 0, 'refunded' => 0.0, 'refunds' => 0, 'net' => 0.0,
            'refunded_offline' => 0.0, 'invoiced_offline' => 0.0, 'canceled' => 0.0);
        $days = array();
        $totals = $blank;
        foreach ($rows as $r) {
            $day = $r['date'];
            if (!isset($days[$day])) {
                $days[$day] = $blank;
            }
            $this->_addToBucket($days[$day], $r);
            $this->_addToBucket($totals, $r);
        }
        ksort($days);
        return array('days' => $days, 'totals' => $totals, 'rows' => $rows, 'methods' => $methods);
    }

    /**
     * @param array $bucket running day or period totals
     * @param array $r      one row from _row()
     */
    protected function _addToBucket(array &$bucket, array $r)
    {
        switch ($r['kind']) {
            case 'capture':
                $bucket['captured'] += $r['amount'];
                $bucket['captures']++;
                break;
            case 'refund':
                $bucket['refunded'] += $r['amount'];
                $bucket['refunds']++;
                break;
            case 'refund_offline':
                $bucket['refunded_offline'] += $r['amount'];
                break;
            case 'invoice_offline':
                $bucket['invoiced_offline'] += $r['amount'];
                break;
            default:
                $bucket['canceled'] += $r['amount'];
        }
        $bucket['net'] = round($bucket['captured'] - $bucket['refunded'], 2);
    }

    /**
     * @param  array  $r
     * @param  string $kind
     * @return array
     */
    protected function _row(array $r, $kind)
    {
        return array(
            'kind'       => $kind,
            'created_at' => $r['created_at'],
            'date'       => $this->localDate($r['created_at']),
            'time'       => $this->localDate($r['created_at'], 'H:i'),
            'order_no'   => $r['order_no'],
            'order_id'   => (int) $r['order_id'],
            'doc'        => $r['doc'],
            'doc_id'     => (int) $r['id'],
            'txn'        => (string) $r['txn'],
            'method'     => $r['method'],
            'amount'     => round((float) $r['amount'], 2),
        );
    }

    // ---- labels ---------------------------------------------------------------------------

    /**
     * @param  string $code
     * @return string
     */
    public function methodTitle($code)
    {
        $title = trim((string) Mage::getStoreConfig('payment/' . $code . '/title'));
        return $title !== '' ? $title . ' (' . $code . ')' : $code;
    }

    /** @return array id => name */
    public function getCustomerGroups()
    {
        $out = array();
        foreach (Mage::getResourceModel('customer/group_collection') as $group) {
            $out[(int) $group->getId()] = $group->getCustomerGroupCode();
        }
        return $out;
    }
}
