<?php

namespace App\Services\SMS;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TurkeySmsService
{
    const API_BASE_URL = 'https://turkeysms.com.tr/api/v3';

    const API_URL_SEND_SMS = '/gonder/add-content';

    const API_URL_GET_SMS = '/sms-durumu/sms_durumu.php';

    const LANGUAGE_ENGLISH = 0;

    const LANGUAGE_TURKISH = 1;

    const LANGUAGE_ARABIC = 2;

    protected string $apiKey;

    protected int $language;

    protected array $senderIds;

    public function __construct()
    {
        $this->apiKey = config('services.turkey_sms.api_key');
        $this->language = config('services.turkey_sms.language', self::LANGUAGE_TURKISH);
        $this->senderIds = config('services.turkey_sms.sender_ids', [
            'turkey' => 'DEFAULT',
            'default' => 'DEFAULT',
        ]);
    }

    /**
     * Send SMS message
     *
     * @param  string  $phone  Phone number in format: 905XXXXXXXXX
     * @param  string  $message  SMS message content
     * @return array Response from Turkey SMS API
     */
    public function sendSms(string $phone, string $message): array
    {
        try {
            $response = $this->request('post', self::API_URL_SEND_SMS, [
                'sentto' => $phone,
                'text' => $message,
            ]);

            $responseData = $response->json();

            if ($responseData['result'] == false) {
                Log::error('Turkey SMS API Error', [
                    'response' => $responseData,
                    'phone' => $phone,
                    'result_code' => $responseData['result_code'] ?? 'unknown',
                ]);

                return [
                    'success' => false,
                    'status_code' => $response->status(),
                    'response' => $responseData,
                    'sms_id' => null,
                ];
            }

            Log::info('Turkey SMS sent successfully', [
                'phone' => $phone,
                'sms_id' => $responseData['data']['sms_id'] ?? null,
            ]);

            return [
                'success' => true,
                'status_code' => $response->status(),
                'response' => $responseData,
                'sms_id' => $responseData['data']['sms_id'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Turkey SMS Exception', [
                'message' => $e->getMessage(),
                'phone' => $phone,
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'response' => [
                    'result' => false,
                    'message' => $e->getMessage(),
                ],
                'sms_id' => null,
            ];
        }
    }

    /**
     * Get SMS status by ID
     */
    public function getSmsStatus(string|int $smsId): array
    {
        try {
            $response = $this->request('get', self::API_URL_GET_SMS, [
                'sms_id' => $smsId,
            ]);

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'response' => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('Turkey SMS Status Check Exception', [
                'message' => $e->getMessage(),
                'sms_id' => $smsId,
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'response' => [
                    'result' => false,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Make request to Turkey SMS API
     */
    protected function request(string $method, string $url, array $params): \Illuminate\Http\Client\Response
    {
        $payload = [
            'api_key' => $this->apiKey,
            'response_type' => 'json',
        ];

        if ($to = $params['sentto'] ?? null) {
            $payload['title'] = $this->getSenderId($to);
        }

        if ($url === self::API_URL_SEND_SMS) {
            $payload['sms_lang'] = $this->language;
            $payload['report'] = 1;
        }

        return Http::baseUrl(self::API_BASE_URL)
            ->asJson()
            ->$method($url, array_merge($params, $payload));
    }

    /**
     * Get sender ID based on phone number
     */
    protected function getSenderId(string $to): string
    {
        if (Str::startsWith($to, '90')) {
            return $this->senderIds['turkey'];
        }

        return $this->senderIds['default'];
    }
}
