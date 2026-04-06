<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('barcode.label_preset', '60x40');
        $this->migrator->add('barcode.label_width', 60);
        $this->migrator->add('barcode.label_height', 40);
    }
};
