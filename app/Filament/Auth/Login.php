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

        return new HtmlString(
            'حساب کاربری ندارید؟ <a href="'.route('register').'" class="fi-link">ثبت‌نام</a>'
        );
    }
}
