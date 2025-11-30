<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

class UpdateExchangeRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exchange-rates:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update exchange rates from external API';

    /**
     * Execute the console command.
     */
    public function handle(ExchangeRateService $exchangeRateService): int
    {
        $this->info('Updating exchange rates...');

        $results = $exchangeRateService->updateAllRates();

        if ($results['success'] > 0) {
            $this->info("Successfully updated rates for {$results['success']} currencies: ".implode(', ', $results['currencies']));
        }

        if ($results['failed'] > 0) {
            $this->warn("Failed to update rates for {$results['failed']} currencies");
        }

        if ($results['success'] === 0) {
            $this->error('No exchange rates were updated');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
