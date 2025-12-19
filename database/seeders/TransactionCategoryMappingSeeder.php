<?php

namespace Database\Seeders;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\TransactionCategoryMapping;
use Illuminate\Database\Seeder;

class TransactionCategoryMappingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $mappings = [
            // Marketing - Meta (Facebook/Instagram)
            [
                'pattern' => 'FACEBK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::MARKETING_META->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'FACEBOOK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::MARKETING_META->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'META',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::MARKETING_META->value,
                'priority' => 10,
            ],

            // Marketing - Google
            [
                'pattern' => 'GOOGLE ADS',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::MARKETING_GOOGLE->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'GOOGLEADWORDS',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::MARKETING_GOOGLE->value,
                'priority' => 10,
            ],

            // Marketing - TikTok
            [
                'pattern' => 'TIKTOK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::MARKETING_TIKTOK->value,
                'priority' => 10,
            ],

            // Software & Tools
            [
                'pattern' => 'APPLE.COM',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'GOOGLE STORAGE',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'GOOGLE CLOUD',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'ADOBE',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'MICROSOFT',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'ZOOM',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'TURHOST',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'DIGITALOCEAN',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'AWS',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'GODADDY',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'BIZIM HESAP',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'BIZIMHESAP',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'PARASUT',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SOFTWARE_TOOLS->value,
                'priority' => 10,
            ],

            // Shipping & Logistics
            [
                'pattern' => 'BASITKARGO',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'ARAS KARGO',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'YURTICI KARGO',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'MNG KARGO',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'PTT KARGO',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'TRYOTO',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 5,
            ],
            [
                'pattern' => 'SHIPINK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 5,
            ],
            [
                'pattern' => 'CARGOPANEL',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 5,
            ],
            [
                'pattern' => 'KARGO HİZMET',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::SHIPPING_LOGISTICS->value,
                'priority' => 5,
            ],

            // Packaging Materials
            [
                'pattern' => 'PAKET',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PACKAGING_MATERIALS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'AMBALAJ',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PACKAGING_MATERIALS->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'BIDOLUBASKI',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PACKAGING_MATERIALS->value,
                'priority' => 10,
            ],

            // Rent & Utilities
            [
                'pattern' => 'ELEKTRIK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'SU',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'DOGALGAZ',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'INTERNET',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'TURK TELEKOM',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'VODAFONE',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'TURKCELL',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'KIRA',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::RENT_UTILITIES->value,
                'priority' => 10,
            ],

            // Office Supplies
            [
                'pattern' => 'KIRTASIYE',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::OFFICE_SUPPLIES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'D&R',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::OFFICE_SUPPLIES->value,
                'priority' => 10,
            ],

            // Bank Fees
            [
                'pattern' => 'KESİNTİ VE EKLERİ',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::BANK_FEES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'BANKA MASRAF',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::BANK_FEES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'HESAP ISLETIM',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::BANK_FEES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'EFT MASRAF',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::BANK_FEES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'HAVALE MASRAF',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::BANK_FEES->value,
                'priority' => 10,
            ],

            // Professional Services
            [
                'pattern' => 'AVUKAT',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PROFESSIONAL_SERVICES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'MUHASEBE',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PROFESSIONAL_SERVICES->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'DANISMANLIK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PROFESSIONAL_SERVICES->value,
                'priority' => 10,
            ],

            // Photography & Content
            [
                'pattern' => 'FOTOGRAF',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PHOTOGRAPHY_CONTENT->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'CEKIM',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PHOTOGRAPHY_CONTENT->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'BIONLUK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PHOTOGRAPHY_CONTENT->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'FIVERR',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PHOTOGRAPHY_CONTENT->value,
                'priority' => 10,
            ],
            [
                'pattern' => 'UPWORK',
                'type' => TransactionType::EXPENSE,
                'category' => ExpenseCategory::PHOTOGRAPHY_CONTENT->value,
                'priority' => 10,
            ],

            // Income - Shopify Sales
            [
                'pattern' => 'IYZICO',
                'type' => TransactionType::INCOME,
                'category' => IncomeCategory::SALES_SHOPIFY->value,
                'priority' => 5,
            ],
            [
                'pattern' => 'SHOPIFY',
                'type' => TransactionType::INCOME,
                'category' => IncomeCategory::SALES_SHOPIFY->value,
                'priority' => 5,
            ],

            // Income - Trendyol Sales
            [
                'pattern' => 'TRENDYOL',
                'type' => TransactionType::INCOME,
                'category' => IncomeCategory::SALES_TRENDYOL->value,
                'priority' => 5,
            ],

            // Income - Direct Sales
            [
                'pattern' => 'HAVALE',
                'type' => TransactionType::INCOME,
                'category' => IncomeCategory::SALES_DIRECT->value,
                'priority' => 5,
            ],
            [
                'pattern' => 'EFT',
                'type' => TransactionType::INCOME,
                'category' => IncomeCategory::SALES_DIRECT->value,
                'priority' => 5,
            ],
        ];

        foreach ($mappings as $mapping) {
            TransactionCategoryMapping::updateOrCreate(
                [
                    'pattern' => $mapping['pattern'],
                    'type' => $mapping['type'],
                ],
                [
                    'category' => $mapping['category'],
                    'account_id' => null, // Global mapping
                    'is_active' => true,
                    'priority' => $mapping['priority'],
                ]
            );
        }

        $this->command->info('Created '.count($mappings).' default transaction category mappings.');
    }
}
