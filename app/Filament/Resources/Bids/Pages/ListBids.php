<?php

namespace App\Filament\Resources\Bids\Pages;

use App\Filament\Resources\Bids\BidResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * The مناقصات list — the first screen every role lands on after logging in.
 *
 * There is almost nothing here because the interesting parts live elsewhere:
 * the columns and row buttons are in BidsTable, and which tenders a given
 * role may see is decided by that same class's query filter.
 */
class ListBids extends ListRecords
{
    protected static string $resource = BidResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Filament hides this automatically for anyone whose BidPolicy
            // create() returns false — i.e. regular users.
            CreateAction::make(),
        ];
    }
}
