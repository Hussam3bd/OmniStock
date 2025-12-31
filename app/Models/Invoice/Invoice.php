<?php

namespace App\Models\Invoice;

use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Invoice\InvoiceType;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'order_id',
        'integration_id',
        'invoice_type',
        'invoice_number',
        'external_id',
        'invoice_uuid',
        'status',
        'issued_at',
        'cancelled_at',
        'cancel_reason',
        'invoice_data',
        'provider_response',
        'error_message',
        'pdf_url',
        'html_url',
        'sent_to_customer',
        'sent_at',
        'send_methods',
    ];

    protected function casts(): array
    {
        return [
            'invoice_type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'invoice_data' => 'array',
            'provider_response' => 'array',
            'send_methods' => 'array',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'sent_at' => 'datetime',
            'sent_to_customer' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'invoice_number', 'external_id', 'cancel_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Check if invoice can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled() && is_null($this->cancelled_at);
    }

    /**
     * Mark invoice as issued
     */
    public function markAsIssued(string $externalId, ?string $invoiceUuid = null, array $providerResponse = []): void
    {
        $data = [
            'status' => InvoiceStatus::ISSUED,
            'external_id' => $externalId,
            'provider_response' => $providerResponse,
            'issued_at' => now(),
        ];

        // Add invoice UUID if provided
        if ($invoiceUuid) {
            $data['invoice_uuid'] = $invoiceUuid;
        }

        $this->update($data);
    }

    /**
     * Mark invoice as cancelled
     */
    public function markAsCancelled(?string $reason = null): void
    {
        $this->update([
            'status' => InvoiceStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);
    }

    /**
     * Mark invoice as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => InvoiceStatus::FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Mark invoice as sent to customer
     */
    public function markAsSent(array $methods = []): void
    {
        $this->update([
            'sent_to_customer' => true,
            'sent_at' => now(),
            'send_methods' => $methods,
        ]);
    }
}
