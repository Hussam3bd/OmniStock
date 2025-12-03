<?php

use App\Enums\Shipping\ShippingCarrier;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert orders shipping_carrier from raw names to enum values
        DB::table('orders')
            ->whereNotNull('shipping_carrier')
            ->orderBy('id')
            ->chunk(100, function ($orders) {
                foreach ($orders as $order) {
                    $carrier = ShippingCarrier::fromString($order->shipping_carrier);
                    if ($carrier) {
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['shipping_carrier' => $carrier->value]);
                    } else {
                        // If carrier couldn't be parsed, set to null
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['shipping_carrier' => null]);
                    }
                }
            });

        // Convert returns return_shipping_carrier from raw names to enum values
        DB::table('returns')
            ->whereNotNull('return_shipping_carrier')
            ->orderBy('id')
            ->chunk(100, function ($returns) {
                foreach ($returns as $return) {
                    $carrier = ShippingCarrier::fromString($return->return_shipping_carrier);
                    if ($carrier) {
                        DB::table('returns')
                            ->where('id', $return->id)
                            ->update(['return_shipping_carrier' => $carrier->value]);
                    } else {
                        // If carrier couldn't be parsed, set to null
                        DB::table('returns')
                            ->where('id', $return->id)
                            ->update(['return_shipping_carrier' => null]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert orders shipping_carrier from enum values back to display names
        DB::table('orders')
            ->whereNotNull('shipping_carrier')
            ->orderBy('id')
            ->chunk(100, function ($orders) {
                foreach ($orders as $order) {
                    try {
                        $carrier = ShippingCarrier::from($order->shipping_carrier);
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['shipping_carrier' => $carrier->getLabel()]);
                    } catch (\ValueError $e) {
                        // Skip invalid values
                    }
                }
            });

        // Convert returns return_shipping_carrier from enum values back to display names
        DB::table('returns')
            ->whereNotNull('return_shipping_carrier')
            ->orderBy('id')
            ->chunk(100, function ($returns) {
                foreach ($returns as $return) {
                    try {
                        $carrier = ShippingCarrier::from($return->return_shipping_carrier);
                        DB::table('returns')
                            ->where('id', $return->id)
                            ->update(['return_shipping_carrier' => $carrier->getLabel()]);
                    } catch (\ValueError $e) {
                        // Skip invalid values
                    }
                }
            });
    }
};
