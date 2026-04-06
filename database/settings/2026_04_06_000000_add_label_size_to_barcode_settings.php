<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('barcode.label_preset', '76x25');
        $this->migrator->add('barcode.label_width', 76);
        $this->migrator->add('barcode.label_height', 25.4);
    }
};
