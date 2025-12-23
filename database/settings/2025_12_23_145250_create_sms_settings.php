<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sms.awaiting_pickup_template', "Merhaba {{first_name}} Hanım,\nSiparişiniz adresinizde teslim edilemediği için şu an {{shipping_carrier}}/{{distribution_center_location}}/{{distribution_center_name}} kargo şubesinde beklemektedir.\nİade olmaması ve kargo ücretinin boşa gitmemesi adına, bugün ya da en geç yarın şubeden teslim almanızı rica ederiz.\n\nAnlayışınız için teşekkür ederiz.\nRevanStep");
    }
};
