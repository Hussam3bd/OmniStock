<?php

namespace App\Forms\Components;

use Cknow\Money\Money;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

class MoneyInput extends TextInput
{
    public string $currency = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = config('money.defaultCurrency');

        $this->formatStateUsing(function (MoneyInput $component, mixed $state, ?Model $record = null): string {
            // Handle Money object
            if ($state instanceof Money) {
                $this->currency = $state->getCurrency();
                $this->prefix($state->getCurrency());

                return $state->divide(100)->getAmount();
            }

            // Handle serialized Money (Livewire converts Money objects to arrays)
            if (is_array($state) && isset($state['amount'], $state['currency'])) {
                $money = money($state['amount'], $state['currency']);
                $this->currency = $money->getCurrency();
                $this->prefix($money->getCurrency());

                return $money->divide(100)->getAmount();
            }

            // Fallback: try to get from record
            if ($record) {
                $originalState = $record->getAttribute(
                    str($this->getStatePath())->after('data.')->toString()
                );

                if ($originalState instanceof Money) {
                    $this->currency = $originalState->getCurrency();
                    $this->prefix($originalState->getCurrency());

                    return $originalState->divide(100)->getAmount();
                }
            }

            return '';
        });

        $this->dehydrateStateUsing(function (MoneyInput $component, null|int|string $state) {
            if (! $state) {
                return null;
            }

            return Money::parse($state, $this->currency)->multiply(100);
        });

        $this
            ->minValue(0)
            ->step(0.1)
            ->prefix(str($this->currency)->upper());
    }
}
