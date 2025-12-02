<?php

namespace App\Console\Commands;

use App\Models\Address\Country;
use App\Models\Address\District;
use App\Models\Address\Neighborhood;
use App\Models\Address\Province;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\progress;

class SeedTurkishAddresses extends Command
{
    protected $signature = 'address:seed-turkish';

    protected $description = 'Seed Turkish address data from TurkiyeAPI (completes in ~30 seconds)';

    private const API_BASE = 'https://api.turkiyeapi.dev/v1';

    public function handle(): int
    {
        $this->info('Starting Turkish address data seeding...');

        // Step 1: Create Turkey country
        $this->info('Creating Turkey country...');
        $turkey = $this->createTurkeyCountry();

        // Step 2: Fetch all data from API
        $this->info('Fetching provinces from TurkiyeAPI...');
        $provinces = $this->fetchProvinces();

        $this->info('Fetching all neighborhoods from TurkiyeAPI...');
        $neighborhoods = $this->fetchAllNeighborhoods();

        // Step 3: Seed provinces and districts
        $this->info('Seeding '.count($provinces).' provinces and districts...');
        $this->newLine();

        progress(
            label: 'Seeding provinces & districts',
            steps: $provinces,
            callback: function ($provinceData) use ($turkey) {
                $province = $this->createProvince($turkey, $provinceData);

                // Create districts directly from province data
                if (! empty($provinceData['districts'])) {
                    foreach ($provinceData['districts'] as $districtData) {
                        $this->createDistrict($province, $districtData);
                    }
                }
            }
        );

        // Step 4: Seed neighborhoods
        $this->newLine();
        $this->info('Seeding '.count($neighborhoods).' neighborhoods...');
        $this->newLine();

        progress(
            label: 'Seeding neighborhoods',
            steps: $neighborhoods,
            callback: fn ($neighborhoodData) => $this->createNeighborhood($neighborhoodData)
        );

        $this->newLine();
        $this->info('✓ Turkish address data seeded successfully!');
        $this->showStats();

        return self::SUCCESS;
    }

    private function showStats(): void
    {
        $this->table(
            ['Entity', 'Count'],
            [
                ['Countries', Country::count()],
                ['Provinces', Province::count()],
                ['Districts', District::count()],
                ['Neighborhoods', Neighborhood::count()],
            ]
        );
    }

    private function createTurkeyCountry(): Country
    {
        return Country::updateOrCreate(
            ['iso2' => 'TR'],
            [
                'name' => [
                    'en' => 'Turkey',
                    'tr' => 'Türkiye',
                ],
                'iso3' => 'TUR',
                'phone_code' => '+90',
                'capital' => 'Ankara',
                'currency' => 'TRY',
                'is_active' => true,
            ]
        );
    }

    private function fetchProvinces(): array
    {
        $response = Http::get(self::API_BASE.'/provinces');

        if ($response->failed()) {
            $this->error('Failed to fetch provinces from API');
            exit(1);
        }

        return $response->json('data') ?? [];
    }

    private function fetchAllNeighborhoods(): array
    {
        $allNeighborhoods = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = Http::get(self::API_BASE.'/neighborhoods', [
                'offset' => $offset,
                'limit' => $limit,
            ]);

            if ($response->failed()) {
                $this->error('Failed to fetch neighborhoods from API');
                exit(1);
            }

            $neighborhoods = $response->json('data') ?? [];
            $allNeighborhoods = array_merge($allNeighborhoods, $neighborhoods);

            $this->info('Fetched '.count($neighborhoods).' neighborhoods (total: '.count($allNeighborhoods).')');

            $offset += $limit;
        } while (count($neighborhoods) === $limit);

        return $allNeighborhoods;
    }

    private function createProvince(Country $country, array $data): Province
    {
        $province = Province::firstOrNew(['id' => $data['id']]);

        $province->id = $data['id'];
        $province->country_id = $country->id;
        $province->name = [
            'en' => $data['name'],
            'tr' => $data['name'],
        ];
        $province->region = [
            'en' => $data['region']['en'] ?? null,
            'tr' => $data['region']['tr'] ?? null,
        ];
        $province->population = $data['population'] ?? null;
        $province->area = $data['area'] ?? null;
        $province->altitude = $data['altitude'] ?? null;
        $province->area_codes = $data['areaCode'] ?? null;
        $province->is_coastal = $data['isCoastal'] ?? false;
        $province->is_metropolitan = $data['isMetropolitan'] ?? false;
        $province->latitude = $data['coordinates']['latitude'] ?? null;
        $province->longitude = $data['coordinates']['longitude'] ?? null;
        $province->nuts1_code = $data['nuts']['nuts1']['code'] ?? null;
        $province->nuts2_code = $data['nuts']['nuts2']['code'] ?? null;
        $province->nuts3_code = $data['nuts']['nuts3'] ?? null;

        $province->save();

        return $province;
    }

    private function createDistrict(Province $province, array $districtData): District
    {
        $district = District::firstOrNew(['id' => $districtData['id']]);

        $district->id = $districtData['id'];
        $district->province_id = $province->id;
        $district->name = [
            'en' => $districtData['name'],
            'tr' => $districtData['name'],
        ];
        $district->population = $districtData['population'] ?? null;
        $district->area = $districtData['area'] ?? null;

        $district->save();

        return $district;
    }

    private function createNeighborhood(array $neighborhoodData): void
    {
        $neighborhood = Neighborhood::firstOrNew(['id' => $neighborhoodData['id']]);

        $neighborhood->id = $neighborhoodData['id'];
        $neighborhood->district_id = $neighborhoodData['districtId'];
        $neighborhood->name = [
            'en' => $neighborhoodData['name'],
            'tr' => $neighborhoodData['name'],
        ];
        $neighborhood->population = $neighborhoodData['population'] ?? null;

        $neighborhood->save();
    }
}
