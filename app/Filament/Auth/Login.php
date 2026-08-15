<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * This app has no email column at all — accounts authenticate by mobile
 * number (see ARCHITECTURE.md's "Panel structure"). Everything else about
 * the base Filament login flow (rate limiting, multi-factor hook points,
 * timeboxed credential checks) is left untouched.
 */
class Login extends BaseLogin
{
    /**
     * Filament's base class calls this method "email" because that is what
     * most apps use. We keep the method name (so the parent's login flow
     * keeps working untouched) but return a mobile field instead.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('mobile')
            ->label('شماره موبایل')
            ->tel()
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    /**
     * What gets handed to Laravel's authentication attempt — 'mobile'
     * instead of the usual 'email'. The password is compared against the
     * stored hash by Laravel; it is never looked up as a plain value.
     *
     * #[SensitiveParameter] keeps the raw password out of stack traces.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'mobile' => $data['mobile'],
            'password' => $data['password'],
        ];
    }

    /**
     * The message shown when login fails.
     *
     * It deliberately does not say WHICH half was wrong. "No such number"
     * would let anyone check whether a given mobile has an account here.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.mobile' => 'شماره موبایل یا رمز عبور اشتباه است.',
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getSubheading();
        }

        // getRegistrationUrl(), not route('register'): registration is a panel
        // page now (see AppPanelProvider->registration()), so its route name
        // is Filament's own `filament.app.auth.register`.
        return new HtmlString(
            'حساب کاربری ندارید؟ <a href="'.filament()->getRegistrationUrl().'" class="fi-link">ثبت‌نام</a>'
        );
    }
}
