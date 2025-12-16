<?php

namespace App\Models\Concerns;

use App\Models\Currency;

trait HasCurrencyCode
{
    /**
     * Boot the trait and automatically sync currency_code when currency_id changes
     */
    protected static function bootHasCurrencyCode(): void
    {
        static::saving(function ($model) {
            // Sync currency_code when currency_id is set or changed
            if ($model->currency_id && $model->isDirty('currency_id')) {
                $currency = Currency::find($model->currency_id);
                if ($currency) {
                    $model->currency_code = $currency->code;
                }
            }
        });
    }
}
