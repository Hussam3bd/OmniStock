# Product Mission

## Pitch
RevanStep is a unified e-commerce operations platform that helps Turkish e-commerce managers eliminate manual data entry and accounting headaches by automatically synchronizing inventory, calculating all costs, generating legal invoices, and managing orders across multiple sales channels from a single dashboard.

## Users

### Primary Customers
- **E-commerce Managers**: Business owners and operators managing inventory and sales across multiple Turkish marketplaces
- **Multi-Channel Sellers**: Turkish merchants selling on Shopify, Trendyol, Hepsiburada, and other local platforms
- **Growing E-commerce Businesses**: Sellers experiencing pain from manual processes as they scale across channels

### User Personas

**Ayse - Multi-Channel E-commerce Manager** (30-45)
- **Role:** Owner/Manager of growing online retail business
- **Context:** Manages 200-1000 SKUs across Shopify store, Trendyol, and planning expansion to Hepsiburada
- **Pain Points:** Spends hours manually updating inventory across platforms, struggles to track actual profitability due to hidden fees, manually creates e-invoices for each sale, constantly worried about overselling due to sync delays
- **Goals:** Automate repetitive tasks, get accurate profit margins in real-time, ensure legal compliance with Turkish e-invoicing, scale to more sales channels without hiring additional staff

**Mehmet - Operations Manager** (25-40)
- **Role:** Operations lead for mid-sized e-commerce company
- **Context:** Manages team handling 50-100 daily orders across multiple marketplaces
- **Pain Points:** Inventory discrepancies cause customer service issues, can't accurately forecast restocking needs, financial reporting takes days to compile manually, marketplace integrations break frequently
- **Goals:** Real-time inventory visibility across all channels, automated cost tracking for accurate margins, streamlined order fulfillment workflows, reliable marketplace integrations

## The Problem

### Fragmented Multi-Channel Management Creates Operational Chaos
Turkish e-commerce sellers face a unique challenge: to maximize revenue, they must sell across multiple local marketplaces (Trendyol, Hepsiburada, Pazarama) plus international platforms (Shopify), but each platform operates independently. Sellers waste 10-20 hours per week manually synchronizing inventory, risk overselling due to sync delays, and struggle to understand true profitability because costs (shipping, marketplace fees, payment gateway fees, product costs) are scattered across different systems.

**Our Solution:** RevanStep acts as a central nervous system, automatically synchronizing inventory in real-time across all sales channels, calculating all costs automatically (including marketplace-specific shipping rates and commission structures), and providing a single source of truth for stock levels, order status, and financial performance.

### Legal Compliance is Complex and Time-Consuming
Turkish law requires e-invoices (e-fatura) for B2B transactions and specific invoicing for marketplace sales. Manually generating these legal documents for each order is tedious, error-prone, and delays fulfillment. Mistakes can result in regulatory penalties.

**Our Solution:** Automated e-invoice generation integrated directly with Turkish revenue administration requirements and marketplace-specific invoicing rules, generating compliant invoices automatically when orders are paid and delivered.

### Hidden Costs Make Profitability Invisible
E-commerce sellers know their gross sales but struggle to calculate actual profit margins. Shipping costs vary by provider and destination, marketplace commissions differ by category, payment gateway fees vary by transaction type, and product costs change with suppliers. Without automated cost tracking, sellers make pricing decisions blind.

**Our Solution:** Comprehensive cost calculation engine that automatically tracks and attributes all costs (shipping, marketplace fees, gateway fees, product costs, premiums) to each order, providing real-time profit margin visibility per product, per channel, and per order.

## Differentiators

### Built Specifically for the Turkish E-commerce Ecosystem
Unlike generic international platforms, RevanStep is designed from the ground up for Turkish market requirements. We provide native integrations with Trendyol, Hepsiburada, Pazarama, and other local marketplaces, built-in support for Turkish e-invoicing regulations (e-fatura), and integration with Turkish payment gateways (Iyzico) and shipping providers (Basit Kargo). This results in faster implementation, fewer integration issues, and compliance confidence that generic platforms cannot match.

### Automatic Cost Calculation Across All Fee Types
Most platforms track orders but leave cost calculation to spreadsheets. RevanStep automatically calculates and tracks shipping costs (with provider-specific rate tables), marketplace commissions (including category-specific rates), payment gateway fees, product costs, and additional premiums. This results in real-time profitability insights without manual data entry or spreadsheet maintenance.

### Event-Driven Architecture Ensures Real-Time Accuracy
RevanStep uses an event-driven architecture (OrderPaid, OrderDelivered, InventoryUpdated events) to trigger automatic workflows. When an order is paid, payment transaction IDs are automatically synced; when delivered, invoices are auto-generated; when inventory changes, all channels update simultaneously. This results in faster fulfillment, fewer manual errors, and confidence that all systems reflect current reality.

### From Internal Tool to SaaS Platform
Currently serving as a battle-tested internal tool, RevanStep has been refined through real-world operational use. The roadmap includes transitioning to a SaaS platform to serve the broader Turkish e-commerce market, bringing enterprise-grade multi-channel management to growing businesses that can't afford custom solutions.

## Key Features

### Core Features
- **Unified Inventory Management:** Centralized stock tracking across all sales channels and warehouse locations with automatic synchronization to prevent overselling
- **Multi-Channel Order Management:** Consolidated view of all orders from Shopify, Trendyol, and other marketplaces with unified fulfillment workflows
- **Automated Cost Calculation:** Automatic tracking and attribution of all costs including shipping (provider-specific rates), marketplace fees, payment gateway fees, and product costs
- **Legal Invoice Automation:** Automatic e-invoice (e-fatura) generation for paid and delivered orders, ensuring Turkish regulatory compliance
- **Real-Time Financial Tracking:** Complete visibility into revenue, costs, and profit margins per order, per product, and per sales channel

### Sales Channel Features
- **Marketplace Integrations:** Native API integrations with Turkish marketplaces (Trendyol, Shopify, with Hepsiburada and Pazarama planned)
- **Product Publishing Automation:** Publish products to multiple sales channels via API without manual data entry on each platform
- **Customer Mapping:** Unified customer management with automatic mapping of customers across platforms
- **Multi-Currency Support:** Handle transactions in multiple currencies with automatic exchange rate tracking

### Operations Features
- **Shipping Provider Integration:** Direct integration with Turkish shipping providers (Basit Kargo) for automated fulfillment
- **Payment Gateway Integration:** Seamless connection with Turkish payment gateways (Iyzico) for transaction tracking
- **Event-Driven Workflows:** Automated workflows triggered by business events (payment received, order delivered) to eliminate manual processes
- **Multi-Location Warehouse Support:** Track inventory across multiple warehouse locations with location-specific stock levels

### Advanced Features
- **Activity Logging:** Complete audit trail of all system actions for compliance and troubleshooting
- **Media Management:** Centralized product media library with automatic distribution to sales channels
- **Settings Management:** Flexible configuration for business rules, pricing strategies, and channel-specific settings
