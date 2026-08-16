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
    /**
     * Keep the page (it owns '/'), but keep it OUT of the sidebar.
     *
     * A «داشبورد» menu item whose only behaviour is to bounce you to
     * مناقصات — which is the item directly below it — is a rung on the
     * ladder that goes nowhere. The route still has to exist, because
     * Filament sends people to the panel root after logging in and that
     * root is this page.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        redirect()->to(BidResource::getUrl());
    }
}
