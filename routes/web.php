<?php

/*
 * This file is intentionally almost empty.
 *
 * The Filament panel is mounted at the domain root ('/') — see
 * AppPanelProvider — so it registers every route this app has, including the
 * pre-auth ones (/login and /register). There is no separate public site.
 *
 * /register used to be declared here, pointing at a standalone Livewire
 * component. It is now a panel page (App\Filament\Auth\Register, wired up via
 * the panel's ->registration()), so declaring it here as well would register
 * two routes for the same URL. Link to it with filament()->getRegistrationUrl()
 * rather than route('register') — the route name is now
 * `filament.app.auth.register`.
 */
