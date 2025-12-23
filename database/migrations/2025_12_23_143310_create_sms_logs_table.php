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
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('phone');
            $table->text('message');
            $table->string('type')->default('notification'); // notification, marketing, etc.
            $table->boolean('sent')->default(false);
            $table->string('provider')->default('turkey_sms');
            $table->string('provider_sms_id')->nullable();
            $table->integer('status_code')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'type']);
            $table->index(['customer_id', 'type']);
            $table->index(['sent', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
