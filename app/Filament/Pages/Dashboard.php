<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bids\BidResource;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The مناقصات (Bid) list is the home page for every role, not a widget
 * dashboard — this page only exists to own the panel's root route ('/')
 * and immediately hand off to BidResource's index.
 */
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        redirect()->to(BidResource::getUrl());
    }
}
