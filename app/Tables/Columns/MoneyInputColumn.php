<?php

namespace App\Tables\Columns;

use App\Models\Currency;
use Cknow\Money\Money;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Database\Eloquent\Model;

class MoneyInputColumn extends TextInputColumn
{
    public string $currency = '';

    public ?string $currencyField = null;

    /**
     * Set the currency field to watch for changes
     */
    public function currencyField(?string $field): static
    {
        $this->currencyField = $field;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = config('money.defaultCurrency');

        // Get the Money value and convert to decimal for display in the input
        $this->getStateUsing(function (MoneyInputColumn $column, Model $record): string {
            // Update currency from currency field if specified
            if ($column->currencyField) {
                $column->updateCurrencyFromField($record);
            }

            $state = data_get($record, $column->getName());

            // Handle Money object
            if ($state instanceof Money) {
                if (! $column->currencyField) {
                    $column->currency = $state->getCurrency();
                }
                $column->updatePrefix();

                return $state->divide(100)->getAmount();
            }

            // Handle null or empty
            return '';
        });

        // Save decimal input as Money
        $this->updateStateUsing(function (MoneyInputColumn $column, $state, Model $record) {
            if ($state === null || $state === '') {
                $record->setAttribute($column->getName(), null);
                $record->save();

                return null;
            }

            // Update currency from currency field before saving
            if ($column->currencyField) {
                $column->updateCurrencyFromField($record);
            }

            $money = Money::parse($state, $column->currency)->multiply(100);

            $record->setAttribute($column->getName(), $money);
            $record->save();

            return $state;
        });

        $this
            ->step(0.01)
            ->prefix(str($this->currency)->upper())
            ->rules(['nullable', 'numeric', 'min:0']);
    }

    /**
     * Update currency from the watched currency field
     */
    protected function updateCurrencyFromField(Model $record): void
    {
        if (! $this->currencyField) {
            return;
        }

        try {
            $currencyId = data_get($record, $this->currencyField);

            if ($currencyId) {
                $currency = Currency::find($currencyId);
                if ($currency) {
                    $this->currency = $currency->code;
                    $this->updatePrefix();

                    return;
                }
            }
        } catch (\Exception $e) {
            // Fallback to default if we can't get the currency
        }

        // Fallback to default currency
        $defaultCurrency = Currency::getDefault();
        if ($defaultCurrency) {
            $this->currency = $defaultCurrency->code;
            $this->updatePrefix();
        }
    }

    /**
     * Update the input prefix with current currency
     */
    protected function updatePrefix(): void
    {
        $this->prefix(str($this->currency)->upper());
    }
}
