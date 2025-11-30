<?php

namespace Database\Seeders;

use App\Enums\Accounting\AccountType;
use App\Models\Accounting\Account;
use App\Models\Currency;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tryId = Currency::where('code', 'TRY')->first()?->id;
        $usdId = Currency::where('code', 'USD')->first()?->id;
        $eurId = Currency::where('code', 'EUR')->first()?->id;

        $defaultAccounts = [
            [
                'name' => 'Main Bank Account (TRY)',
                'type' => AccountType::BANK,
                'currency_id' => $tryId,
                'balance' => 0,
                'description' => 'Primary Turkish Lira bank account',
            ],
            [
                'name' => 'Cash Register',
                'type' => AccountType::CASH,
                'currency_id' => $tryId,
                'balance' => 0,
                'description' => 'Physical cash on hand',
            ],
            [
                'name' => 'USD Bank Account',
                'type' => AccountType::BANK,
                'currency_id' => $usdId,
                'balance' => 0,
                'description' => 'US Dollar bank account',
            ],
            [
                'name' => 'EUR Bank Account',
                'type' => AccountType::BANK,
                'currency_id' => $eurId,
                'balance' => 0,
                'description' => 'Euro bank account',
            ],
            [
                'name' => 'Business Credit Card',
                'type' => AccountType::CREDIT_CARD,
                'currency_id' => $tryId,
                'balance' => 0,
                'description' => 'Corporate credit card',
            ],
        ];

        foreach ($defaultAccounts as $account) {
            Account::firstOrCreate(
                ['name' => $account['name']],
                $account
            );
        }
    }
}
