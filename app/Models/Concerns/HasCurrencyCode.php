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
            if ($model->isDirty('currency_id') && $model->currency_id) {
                $currency = Currency::find($model->currency_id);
                if ($currency) {
                    $model->currency_code = $currency->code;
                }
            }
        });
    }
}
