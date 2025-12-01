<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('variant_options', function (Blueprint $table) {
            $table->string('type')->nullable()->after('name')->comment('System type: color, size, or null for custom');
        });

        // Update existing records
        DB::table('variant_options')
            ->where('name', 'variant.color')
            ->update([
                'name' => 'Color',
                'type' => 'color',
            ]);

        DB::table('variant_options')
            ->where('name', 'variant.size')
            ->update([
                'name' => 'Size',
                'type' => 'size',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert the name changes
        DB::table('variant_options')
            ->where('type', 'color')
            ->update(['name' => 'variant.color']);

        DB::table('variant_options')
            ->where('type', 'size')
            ->update(['name' => 'variant.size']);

        Schema::table('variant_options', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
