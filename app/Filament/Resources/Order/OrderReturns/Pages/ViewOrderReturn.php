<?php

namespace App\Filament\Resources\Order\OrderReturns\Pages;

use App\Filament\Actions\Returns\ApproveReturnFilamentAction;
use App\Filament\Actions\Returns\CompleteReturnFilamentAction;
use App\Filament\Actions\Returns\GenerateReturnLabelFilamentAction;
use App\Filament\Actions\Returns\MarkAsReceivedFilamentAction;
use App\Filament\Actions\Returns\RejectReturnFilamentAction;
use App\Filament\Actions\Returns\StartInspectionFilamentAction;
use App\Filament\Resources\Order\OrderReturns\Infolists\OrderReturnInfolist;
use App\Filament\Resources\Order\OrderReturns\OrderReturnResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewOrderReturn extends ViewRecord
{
    protected static string $resource = OrderReturnResource::class;

    public function infolist(Schema $schema): Schema
    {
        return OrderReturnInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            ApproveReturnFilamentAction::make(),
            RejectReturnFilamentAction::make(),
            GenerateReturnLabelFilamentAction::make(),
            MarkAsReceivedFilamentAction::make(),
            StartInspectionFilamentAction::make(),
            CompleteReturnFilamentAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            \App\Filament\Resources\Order\OrderReturns\RelationManagers\RefundsRelationManager::class,
        ];
    }
}
