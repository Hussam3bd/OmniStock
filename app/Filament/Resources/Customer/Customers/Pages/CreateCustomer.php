<?php

namespace App\Filament\Resources\Customer\Customers\Pages;

use App\Filament\Resources\Customer\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;
}
