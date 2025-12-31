<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained('integrations')->nullOnDelete();

            // Invoice details
            $table->string('invoice_type')->default('e-archive'); // e-archive, e-invoice
            $table->string('invoice_number')->unique();
            $table->string('external_id')->nullable()->unique(); // ID from Trendyol E-Fatura
            $table->string('invoice_uuid')->nullable(); // UUID from provider

            // Status
            $table->string('status')->default('draft'); // draft, pending, issued, cancelled, failed
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            // Invoice data
            $table->json('invoice_data')->nullable(); // Full invoice details
            $table->json('provider_response')->nullable(); // Response from Trendyol E-Fatura
            $table->text('error_message')->nullable();

            // PDF/Documents
            $table->string('pdf_url')->nullable();
            $table->string('html_url')->nullable();

            // Delivery
            $table->boolean('sent_to_customer')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->json('send_methods')->nullable(); // ['email', 'sms', 'print']

            $table->timestamps();

            // Indexes
            $table->index(['order_id', 'status']);
            $table->index('external_id');
            $table->index('invoice_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
