<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('barcode.barcode_format', 'upca');
        $this->migrator->add('barcode.barcode_country_code', '869');
        $this->migrator->add('barcode.barcode_company_prefix', null);
        $this->migrator->add('barcode.barcode_auto_generate', false);
    }
};
