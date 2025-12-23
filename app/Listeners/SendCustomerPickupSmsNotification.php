<?php

namespace App\Listeners;

use App\Events\Order\OrderAwaitingCustomerPickup;
use App\Models\SMS\SmsLog;
use App\Services\PhoneNumberService;
use App\Services\SMS\SmsTemplateService;
use App\Services\SMS\TurkeySmsService;
use App\Settings\SmsSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendCustomerPickupSmsNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct(
        protected TurkeySmsService $smsService,
        protected SmsTemplateService $templateService,
        protected PhoneNumberService $phoneService,
        protected SmsSettings $smsSettings
    ) {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderAwaitingCustomerPickup $event): void
    {
        $order = $event->order;
        $customer = $order->customer;

        // Skip if no customer or phone
        if (! $customer || ! $customer->phone) {
            Log::warning('Cannot send pickup SMS - no customer phone', [
                'order_id' => $order->id,
            ]);

            return;
        }

        // Check if test phone number is set in environment
        $testPhone = config('services.turkey_sms.test_phone');
        $phoneToUse = $testPhone ?: $customer->phone;

        // Normalize phone number using PhoneNumberService
        $normalizedPhone = $this->phoneService->normalize($phoneToUse, 'TR');

        if (! $normalizedPhone) {
            Log::warning('Cannot send pickup SMS - invalid phone number', [
                'order_id' => $order->id,
                'phone' => $phoneToUse,
            ]);

            return;
        }

        // Convert from E.164 format (+905530230411) to Turkey SMS format (905530230411)
        $phone = ltrim($normalizedPhone, '+');

        // Log if using test phone
        if ($testPhone) {
            Log::info('Using test phone number for SMS', [
                'order_id' => $order->id,
                'customer_phone' => $customer->phone,
                'test_phone' => $phone,
            ]);
        }

        // Get SMS template from settings
        $template = $this->smsSettings->awaiting_pickup_template;

        // Render template with order data
        $message = $this->templateService->render(
            $template,
            $order,
            $event->distributionCenterName,
            $event->distributionCenterLocation
        );

        // Send SMS
        $result = $this->smsService->sendSms($phone, $message);

        // Log SMS send
        SmsLog::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'phone' => $phone,
            'message' => $message,
            'type' => 'distribution_center_pickup',
            'sent' => $result['success'],
            'provider' => 'turkey_sms',
            'provider_sms_id' => $result['sms_id'] ?? null,
            'status_code' => $result['status_code'] ?? null,
            'provider_response' => $result['response'] ?? null,
            'sent_at' => $result['success'] ? now() : null,
        ]);

        if ($result['success']) {
            Log::info('Distribution center pickup SMS sent', [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'phone' => $phone,
                'sms_id' => $result['sms_id'] ?? null,
            ]);
        } else {
            Log::error('Failed to send distribution center pickup SMS', [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'phone' => $phone,
                'result' => $result,
            ]);
        }
    }
}
