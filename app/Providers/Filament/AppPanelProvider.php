<?php

namespace App\Providers\Filament;

use App\Filament\Auth\ForgotPassword;
use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Configures THE Filament panel for this app.
 *
 * There is exactly one panel — admins, staff and regular users all log into
 * the same UI, and what each of them may see is decided by policies and
 * roles (see app/Policies/*), not by giving each role its own panel.
 *
 * A "panel provider" is just a Laravel service provider whose panel() method
 * Filament calls once at boot to learn how the panel should look and behave.
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // ->default() marks this as the panel Filament uses when code
            // asks for "the current panel" without naming one.
            ->default()
            // Internal identifier — shows up in route names (filament.app.*).
            ->id('app')
            // Mounted at the domain root, NOT at /admin: registration, login
            // and the tender list are the whole product here, so there is no
            // separate public site the panel needs to sit behind.
            ->path('')
            // Our own login page subclass — this app authenticates by mobile
            // number rather than by email (there is no email column at all).
            ->login(Login::class)
            /*
             * Public sign-up, as a panel page rather than a standalone
             * Blade/Livewire route.
             *
             * ->registration() gives us the /register route (the panel is
             * mounted at '', so the URL is unchanged from before), keeps it
             * outside the panel's auth middleware, and renders it in the same
             * shell as /login — same RTL, same Vazirmatn, same dark mode.
             * The three-step OTP wizard lives in App\Filament\Auth\Register.
             */
            ->registration(Register::class)
            /*
             * «رمز عبور خود را فراموش کرده‌اید؟» on the login page.
             *
             * Filament's own password reset is an email-link flow, and this
             * app has no email column at all — so BOTH route actions point
             * at our own App\Filament\Auth\ForgotPassword, a three-step
             * mobile+OTP wizard that resets the password and logs the person
             * straight in. The second argument is the `/password-reset/reset`
             * route, which in Filament's flow is the page a signed email link
             * lands on: nothing ever links to it here, and pointing it at the
             * same wizard means somebody who reaches it by hand gets a
             * working screen instead of Filament's email-only page (which
             * would fail on this schema).
             *
             * Calling ->passwordReset() is also what makes Filament render
             * the "forgot password" link under the login form's password
             * field — see Filament\Auth\Pages\Login::getPasswordFormComponent().
             */
            ->passwordReset(ForgotPassword::class, ForgotPassword::class)
            /*
             * Two different names for two different places.
             *
             * APP_NAME (.env) is the full legal title — «سامانه الکترونیکی
             * مدیریت استعلام پیشنهادات تامین کنندگان» — and Filament uses it
             * for the browser tab title and the login/register page heading,
             * where there is room for 55 characters.
             *
             * ->brandName() is the sidebar/topbar brand, which is 250px wide
             * (see ->sidebarWidth() below): the full title would wrap onto
             * three lines there, so the brand is the short form.
             */
            ->brandName('سامانه مدیریت استعلام')
            ->colors([
                'primary' => Color::Amber,
            ])
            /*
             * Layout width. Both of these are panel-level settings that
             * Filament turns into CSS custom properties on its own layout —
             * there is no stylesheet of ours involved, which is exactly why
             * they are here and not in public/css (see also the "Panel CSS
             * has no Tailwind utilities" note in ARCHITECTURE.md).
             *
             * sidebarWidth: Filament's default is 20rem (320px). The nav has
             * only five short Persian labels, so that left a wide empty
             * gutter — 15.625rem is 250px, i.e. 70px narrower.
             *
             * maxContentWidth: by default Filament caps a page's content at
             * 7xl (80rem) and centres it, which on a wide monitor wastes most
             * of the screen on tables like مناقصات and کالاها. Width::Full
             * removes the cap so the page uses the whole viewport.
             */
            ->sidebarWidth('15.625rem')
            ->maxContentWidth(Width::Full)
            /*
             * Removes the search box Filament puts in the topbar next to the
             * user menu. That box is GLOBAL search: it only ever finds
             * records of resources that declare
             * getGloballySearchableAttributes(), and no resource in this app
             * declares any — so it was a permanently empty search field, and
             * a control that never returns a result reads as a broken app.
             * Each table keeps its own search box, which is the one people
             * actually use.
             */
            ->globalSearch(false)
            /*
             * Typography: Vazirmatn, served from our own public/ folder.
             *
             * By default Filament would fetch its font from Bunny Fonts (an
             * external CDN). That is not acceptable here — the app must load
             * no third-party resources at all — so we pass LocalFontProvider,
             * which simply emits a plain <link> to the URL we give it.
             *
             * - family:   the CSS font-family name, injected by Filament into
             *             its `--font-family` custom property.
             * - url:      our hand-written stylesheet holding the @font-face
             *             rules (see public/css/vazirmatn.css).
             * - provider: LocalFontProvider == "just link this URL", no CDN.
             * - preload:  tells the browser to start downloading the .woff2
             *             immediately instead of waiting until it has parsed
             *             the CSS, which removes a visible flash of fallback
             *             text on first load.
             *
             * asset() builds the URL from APP_URL, so this keeps working
             * behind the production domain as well as on localhost.
             */
            ->font(
                'Vazirmatn',
                url: asset('css/vazirmatn.css'),
                provider: LocalFontProvider::class,
                preload: [asset('fonts/vazirmatn/Vazirmatn-Variable.woff2')],
            )
            // Auto-register everything under these folders, so adding a new
            // resource/page/widget file is all it takes to wire it up.
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Middleware run on every panel request (logged in or not).
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Middleware run only on requests that require a logged-in user.
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
