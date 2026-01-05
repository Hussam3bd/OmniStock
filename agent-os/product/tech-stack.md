# Tech Stack

## Framework & Runtime
- **Application Framework:** Laravel 12
- **Language/Runtime:** PHP 8.3.29
- **Package Manager:** Composer
- **Local Development:** Laravel Herd (automatic HTTPS serving at revanstep.test)

## Frontend
- **JavaScript Framework:** Livewire 3 (full-stack reactive framework)
- **JavaScript Library:** Alpine.js (bundled with Livewire 3)
- **CSS Framework:** Tailwind CSS 4 (CSS-first configuration with @theme directive)
- **Admin Panel:** Filament 4 (admin interface framework built on Livewire)
- **UI Patterns:** Blade components, Livewire components, Filament resources and pages

## Database & Storage
- **Database:** PostgreSQL (or MySQL)
- **ORM/Query Builder:** Eloquent ORM (Laravel's native ORM)
- **Migrations:** Laravel migrations with modify column support
- **Seeders:** Database seeders for development and testing data
- **Caching:** Laravel cache (Redis recommended for production)
- **Queue Backend:** Laravel queue system (database/Redis driver)

## Architecture Patterns
- **Event-Driven Architecture:** Laravel events and listeners for business logic workflows
  - Example events: OrderPaid, OrderDelivered, InventoryUpdated
  - Automated workflows triggered by events (invoice generation, payment syncing)
- **Queue-Based Processing:** Background job processing for time-consuming operations
- **Service Layer Pattern:** Business logic encapsulated in service classes
- **Repository Pattern:** Data access abstraction where needed
- **Observer Pattern:** Model observers for automatic actions on model changes

## Key Laravel Packages

### Core Infrastructure
- **spatie/laravel-media-library:** Media management and file uploads
- **spatie/laravel-activitylog:** Comprehensive audit trail and activity logging
- **spatie/laravel-translatable:** Multi-language support for models
- **spatie/laravel-settings:** Application and tenant-specific settings management

### Financial & Localization
- **moneyphp/money:** Money and currency handling with precision
- **Exchange Rate Package:** Multi-currency support with rate tracking

### Development & Quality
- **laravel/pint:** PHP code formatter (PSR-12 style enforcement)
- **laravel/prompts:** Beautiful CLI prompts for artisan commands
- **laravel/mcp:** Model Context Protocol integration

## Testing & Quality

### Test Framework
- **Pest 4:** Modern PHP testing framework with browser testing support
- **PHPUnit 12:** Underlying test runner
- **Test Types:**
  - Feature tests (primary - most business logic tests)
  - Unit tests (isolated component testing)
  - Browser tests (Pest 4 browser testing for UI workflows)

### Testing Practices
- **Database Testing:** RefreshDatabase trait for clean test state
- **Factories:** Model factories for test data generation
- **Mocking:** Laravel mocks and Pest mocking utilities
- **Datasets:** Pest datasets for parameterized testing (especially validation rules)

### Code Quality
- **Linting/Formatting:** Laravel Pint (auto-format with `vendor/bin/pint --dirty`)
- **Standards:** PSR-12 coding standards via Pint configuration
- **Pre-commit Hooks:** Automated formatting and test execution

## Deployment & Infrastructure

### Local Development
- **Environment:** Laravel Herd (HTTPS enabled by default)
- **Asset Building:** Vite for frontend asset compilation
- **Hot Reload:** Vite HMR for development (`npm run dev` or `composer run dev`)

### Production Readiness
- **Queue Workers:** Background job processing for async operations
- **Scheduler:** Laravel task scheduler for cron jobs
- **Logging:** Laravel logging with configurable channels
- **Error Tracking:** Laravel exception handling (ready for Sentry integration)

### CI/CD
- **Version Control:** Git with feature branch workflow
- **Automated Testing:** Run tests before merge (`php artisan test`)
- **Code Formatting:** Automated Pint formatting in CI pipeline

## Current Third-Party Integrations

### Turkish Marketplaces & Sales Channels
- **Shopify:** E-commerce platform integration
  - Order synchronization
  - Inventory management
  - Product publishing
  - Payment transaction syncing
- **Trendyol:** Turkey's leading marketplace
  - Order management
  - Automated e-invoice generation (e-fatura)
  - Shipping rate management
  - Inventory synchronization

### Payment Gateways
- **Iyzico:** Turkish payment gateway
  - Payment transaction processing
  - Transaction ID syncing
  - Fee calculation and tracking

### Shipping Providers
- **Basit Kargo:** Turkish shipping provider
  - Shipping label generation
  - Rate calculation
  - Shipment tracking

### Invoicing & Legal Compliance
- **Trendyol E-Fatura:** Turkish e-invoicing system
  - Automated legal invoice generation
  - Compliance with Turkish revenue administration
  - Event-driven invoice creation (on OrderPaid, OrderDelivered)

## Planned Integrations

### Phase 1: Turkish Marketplaces
- **Hepsiburada:** Turkey's largest marketplace
  - Full order and inventory management
  - Product publishing API
  - Marketplace fee tracking
- **Pazarama:** Growing Turkish marketplace
  - Order synchronization
  - Inventory management
  - Commission calculation

### Future Integrations
- **N11, Çiçeksepeti, GittiGidiyor:** Additional Turkish marketplaces
- **Amazon Turkey, eBay, Etsy:** International platform expansion
- **Additional Shipping Providers:** Multiple Turkish cargo companies
- **Email/SMS Marketing:** Customer retention and campaign tools
- **Accounting Software:** Export to Turkish accounting systems

## Database Schema Patterns

### Key Models
- **Orders:** Multi-channel order management with platform mappings
- **Products:** Inventory with multi-location tracking
- **Customers:** Unified customer records across platforms
- **Invoices:** Legal invoice records with e-fatura data
- **Transactions:** Financial transaction tracking
- **ShippingRates:** Provider and marketplace-specific shipping costs
- **Locations/Warehouses:** Multi-location inventory support

### Relationships
- Polymorphic relationships for platform-agnostic integrations
- Many-to-many relationships with pivot tables for cross-channel data
- Event sourcing patterns for audit trail and state reconstruction

## Security & Compliance
- **Authentication:** Laravel Sanctum (API tokens) / Laravel Breeze (web auth)
- **Authorization:** Laravel gates and policies
- **Environment Variables:** All secrets in .env files (never committed)
- **Data Validation:** Form Request validation classes
- **CSRF Protection:** Laravel CSRF protection enabled
- **SQL Injection Prevention:** Eloquent ORM and query builder parameterization

## Performance Optimization
- **Eager Loading:** Prevent N+1 queries with relationship eager loading
- **Query Optimization:** Database indexing and query builder efficiency
- **Caching:** Laravel cache for expensive operations
- **Queue Optimization:** Background processing for heavy operations
- **Asset Optimization:** Vite production builds with minification and tree-shaking

## Development Standards Alignment
- **Coding Style:** PSR-12 via Laravel Pint, enforced on all commits
- **Naming Conventions:** Laravel conventions (StudlyCase models, snake_case tables, camelCase methods)
- **File Organization:** Laravel 12 streamlined structure (no separate Kernel files)
- **Command Naming:** All commands end with "Command" suffix (e.g., SetupSamlFromMetadataCommand)
- **Testing Requirements:** All features require passing tests before merge
- **Type Safety:** Explicit return types and parameter type hints required
