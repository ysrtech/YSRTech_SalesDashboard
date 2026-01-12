# YSRTech Sales Dashboard

A custom sales reporting module for OpenMage/Magento 1 that provides comprehensive sales analytics in one convenient dashboard.

## What It Does

Displays real-time sales data including:
- Total sales, shipping, taxes, and refunds
- Tax breakdown by rate with automatic federal tax subtotal (excludes QST/TVQ taxes)
- Product sales grouped by any attribute (category, brand, etc.)
- Quick date range selection (current/last month/year, previous 2-3 years)

## Installation

1. Copy the contents of the `app` directory to your OpenMage/Magento root directory
2. Clear cache: `System → Cache Management` or delete `var/cache`
3. Log out and log back into the admin panel

## How to Access

Navigate to **Sales → Sales Dashboard** in your admin panel.

## Configuration

Go to **System → Configuration → Sales → Sales Dashboard** to set:
- **Order Statuses to Include**: Choose which order statuses count towards reports (default: Complete)
- **Product Grouping Attribute**: Select how to group products in the report (default: Category)

## Key Benefits

- Fast performance even with large date ranges using optimized SQL queries
- Automatic federal vs provincial tax separation for Canadian reporting
- Flexible date range selection with quick presets
- Real-time data refresh without page reload

## Requirements

- OpenMage LTS 19.x or 20.x
- Magento 1.7.x - 1.9.x
- PHP 7.0+

## License

MIT License - see [LICENSE](LICENSE) file for details
