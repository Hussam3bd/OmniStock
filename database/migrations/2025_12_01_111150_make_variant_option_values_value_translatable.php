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
        // First, convert all existing string values to JSON format
        $values = \Illuminate\Support\Facades\DB::table('variant_option_values')->get();

        foreach ($values as $value) {
            if (! $this->isJson($value->value)) {
                // Convert string to JSON format with both locales
                $jsonValue = json_encode([
                    'en' => $value->value,
                    'tr' => $value->value,
                ]);

                \Illuminate\Support\Facades\DB::table('variant_option_values')
                    ->where('id', $value->id)
                    ->update(['value' => $jsonValue]);
            }
        }

        // Now change the column type to JSON
        Schema::table('variant_option_values', function (Blueprint $table) {
            $table->json('value')->change();
        });
    }

    protected function isJson(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variant_option_values', function (Blueprint $table) {
            $table->string('value')->change();
        });
    }
};
