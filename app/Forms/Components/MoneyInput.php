<?php

namespace App\Forms\Components;

use App\Models\Currency;
use Cknow\Money\Money;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

class MoneyInput extends TextInput
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

        // Make reactive if currency field is specified
        if ($this->currencyField) {
            $this->reactive();
            $this->afterStateUpdated(null);
        }

        $this->formatStateUsing(function (MoneyInput $component, mixed $state, ?Model $record = null): string {
            // Update currency from currency field if specified
            if ($this->currencyField) {
                $this->updateCurrencyFromField();
            }

            // Handle Money object
            if ($state instanceof Money) {
                if (! $this->currencyField) {
                    $this->currency = $state->getCurrency();
                }
                $this->updatePrefix();

                return $state->divide(100)->getAmount();
            }

            // Handle serialized Money (Livewire converts Money objects to arrays)
            if (is_array($state) && isset($state['amount'], $state['currency'])) {
                $money = money($state['amount'], $state['currency']);
                if (! $this->currencyField) {
                    $this->currency = $money->getCurrency();
                }
                $this->updatePrefix();

                return $money->divide(100)->getAmount();
            }

            // Fallback: try to get from record
            if ($record && ! $this->currencyField) {
                $originalState = $record->getAttribute(
                    str($this->getStatePath())->after('data.')->toString()
                );

                if ($originalState instanceof Money) {
                    $this->currency = $originalState->getCurrency();
                    $this->updatePrefix();

                    return $originalState->divide(100)->getAmount();
                }
            }

            return '';
        });

        $this->dehydrateStateUsing(function (MoneyInput $component, null|int|string $state) {
            if (! $state) {
                return null;
            }

            // Update currency from currency field before dehydrating
            if ($this->currencyField) {
                $this->updateCurrencyFromField();
            }

            return Money::parse($state, $this->currency)->multiply(100);
        });

        $this
            ->minValue(0)
            ->step(0.01)
            ->prefix(str($this->currency)->upper());
    }

    /**
     * Update currency from the watched currency field
     */
    protected function updateCurrencyFromField(): void
    {
        if (! $this->currencyField) {
            return;
        }

        try {
            $livewire = $this->getContainer()->getLivewire();
            $currencyId = data_get($livewire, $this->currencyField);

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
