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
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->json('region')->nullable();
            $table->integer('population')->unsigned()->nullable();
            $table->integer('area')->unsigned()->nullable()->comment('Area in km²');
            $table->integer('altitude')->unsigned()->nullable();
            $table->json('area_codes')->nullable();
            $table->boolean('is_coastal')->default(false);
            $table->boolean('is_metropolitan')->default(false);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('nuts1_code', 10)->nullable();
            $table->string('nuts2_code', 10)->nullable();
            $table->string('nuts3_code', 10)->nullable();
            $table->timestamps();

            $table->index('country_id');
            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provinces');
    }
};
