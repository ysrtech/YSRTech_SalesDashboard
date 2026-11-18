# YSRTech Sales Dashboard

One-page sales dashboard for OpenMage (Sales → Sales Dashboard).

- **Sales**: orders placed in the period (statuses from the config): gross, discounts, shipping,
  tax, other adjustments, store credit, items shipped.
- **Returns & credits**: credit memos created in the period.
- **By channel** (online checkout, manual/admin, marketplace import = orders with an external order
  id such as Shopify or Faire) and **by customer group / type** (wholesale vs retail).
- **By payment method**, **tax by rate**, **products sold by attribute**.
- **Card reconciliation**: every capture and refund that went through the card gateway, per store
  day, to match the deposits; CSV export.

Filters: dates (store time zone), website, customer type, channel. Websites with different base
currencies are never added up: the website filter is one website or all websites of one currency.

## Install

```bash
composer require ysrtech/sales-dashboard
```

(or copy `app/` into the OpenMage root), then clear the cache.

## Configuration

System → Configuration → Sales → Sales Dashboard:

- **Order Statuses to Include** (default: complete)
- **Product Grouping Attribute** (default: category)
- **Wholesale Customer Groups**, or **Retail Customer Groups** for stores with many wholesale
  groups (then every other group except guests is wholesale)
- **Marketplace Channel Label** (e.g. Shopify, Faire)
- **Card Payment Methods** for the reconciliation

Requires OpenMage 20 / PHP 8.
