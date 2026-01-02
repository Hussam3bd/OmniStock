<?php

namespace App\Listeners;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\PaymentStatus;
use App\Events\Order\OrderDelivered;
use App\Events\Order\OrderPaid;
use App\Jobs\AutoGenerateInvoice;
use Illuminate\Events\Dispatcher;

class AutoGenerateInvoiceListener
{
    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPaid::class => 'handleOrderStatusChange',
            OrderDelivered::class => 'handleOrderStatusChange',
        ];
    }

    /**
     * Handle order paid or delivered events.
     */
    public function handleOrderStatusChange(OrderPaid|OrderDelivered $event): void
    {
        $order = $event->order;

        // Only dispatch invoice generation if order is BOTH paid AND delivered
        if ($order->payment_status === PaymentStatus::PAID &&
            $order->fulfillment_status === FulfillmentStatus::DELIVERED) {
            AutoGenerateInvoice::dispatch($order);
        }
    }
}
