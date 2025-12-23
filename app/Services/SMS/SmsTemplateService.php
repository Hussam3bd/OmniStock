<?php

namespace App\Services\SMS;

use App\Models\Order\Order;

class SmsTemplateService
{
    /**
     * Render SMS template with order data
     */
    public function render(string $template, Order $order, ?string $distributionCenterName = null, ?string $distributionCenterLocation = null): string
    {
        // Build the replacements map
        $replacements = $this->buildReplacements($order, $distributionCenterName, $distributionCenterLocation);

        // Replace all variables in the template
        return $this->replaceVariables($template, $replacements);
    }

    /**
     * Build replacements map from order data
     */
    protected function buildReplacements(Order $order, ?string $distributionCenterName, ?string $distributionCenterLocation): array
    {
        $customer = $order->customer;
        $shippingAddress = $order->shippingAddress;

        return [
            // Customer variables
            'first_name' => $customer->first_name ?? '',
            'last_name' => $customer->last_name ?? '',
            'full_name' => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
            'email' => $customer->email ?? '',
            'phone' => $customer->phone ?? '',

            // Order variables
            'order_number' => $order->order_number ?? '',
            'total_amount' => $order->total_amount ? money($order->total_amount, $order->currency)->format() : '',
            'currency' => $order->currency ?? 'TRY',

            // Shipping variables
            'shipping_carrier' => $order->shipping_carrier?->getLabel() ?? '',
            'tracking_number' => $order->shipping_tracking_number ?? '',
            'distribution_center_name' => $distributionCenterName ?? '',
            'distribution_center_location' => $distributionCenterLocation ?? '',

            // Address variables
            'city' => $shippingAddress?->city ?? '',
            'district' => $shippingAddress?->state ?? '',
            'address' => $shippingAddress?->address1 ?? '',
        ];
    }

    /**
     * Replace variables in template using {{variable}} syntax
     */
    protected function replaceVariables(string $template, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            // Convert value to string (handles null, enums, etc.)
            $stringValue = (string) $value;

            // Replace {{variable}} syntax
            $template = str_replace('{{'.$key.'}}', $stringValue, $template);

            // Also support {{ variable }} with spaces
            $template = str_replace('{{ '.$key.' }}', $stringValue, $template);
        }

        return $template;
    }

    /**
     * Get default template for awaiting pickup notification
     */
    public static function getDefaultAwaitingPickupTemplate(): string
    {
        return "Merhaba {{first_name}} Hanım,\nSiparişiniz adresinizde teslim edilemediği için şu an {{shipping_carrier}}/{{distribution_center_location}}/{{distribution_center_name}} kargo şubesinde beklemektedir.\nİade olmaması ve kargo ücretinin boşa gitmemesi adına, bugün ya da en geç yarın şubeden teslim almanızı rica ederiz.\n\nAnlayışınız için teşekkür ederiz.\nRevanStep";
    }
}
