<?php

namespace App\Providers;

use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderItemCreated;
use App\Events\Order\OrderReturnCompleted;
use App\Listeners\Inventory\DeductInventoryForOrderItem;
use App\Listeners\Inventory\RestoreInventoryForCancellation;
use App\Listeners\Inventory\RestoreInventoryForReturn;
use App\Models\Accounting\Transaction;
use App\Models\Address\Address;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderReturn;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseOrderItem;
use App\Observers\AddressObserver;
use App\Observers\CustomerObserver;
use App\Observers\OrderItemObserver;
use App\Observers\OrderObserver;
use App\Observers\OrderReturnObserver;
use App\Observers\PurchaseOrderItemObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\TransactionObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observers
        Address::observe(AddressObserver::class);
        Customer::observe(CustomerObserver::class);
        Order::observe(OrderObserver::class);
        OrderItem::observe(OrderItemObserver::class);
        OrderReturn::observe(OrderReturnObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        PurchaseOrderItem::observe(PurchaseOrderItemObserver::class);
        Transaction::observe(TransactionObserver::class);

        // Register queued event listeners for inventory tracking
        Event::listen(OrderItemCreated::class, DeductInventoryForOrderItem::class);
        Event::listen(OrderCancelled::class, RestoreInventoryForCancellation::class);
        Event::listen(OrderReturnCompleted::class, RestoreInventoryForReturn::class);

        LogViewer::auth(function ($request) {
            return !!$request?->user();
        });
    }
}
