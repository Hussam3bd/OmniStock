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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('addressable'); // Polymorphic relation (customer, order, etc)
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('province_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('neighborhood_id')->nullable()->constrained()->nullOnDelete();

            // Address details
            $table->string('type')->default('residential'); // residential, institutional
            $table->string('title')->nullable(); // Home, Office, etc
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();

            // Physical address
            $table->text('address_line1')->nullable();
            $table->text('address_line2')->nullable();
            $table->string('building_name')->nullable();
            $table->string('building_number', 50)->nullable();
            $table->string('floor', 50)->nullable();
            $table->string('apartment', 50)->nullable();
            $table->string('postal_code', 20)->nullable();

            // Institutional (Kurumsal) fields
            $table->string('tax_office')->nullable(); // Vergi Dairesi
            $table->string('tax_number')->nullable(); // Vergi Numarası
            $table->string('identity_number', 11)->nullable(); // TC Kimlik No

            // Geolocation
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Additional info
            $table->text('delivery_instructions')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_billing')->default(false);
            $table->boolean('is_shipping')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['country_id', 'province_id', 'district_id']);
            $table->index('is_default');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
