<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ChangePassword extends Page
{
    protected string $view = 'filament.pages.change-password';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'تغییر رمز عبور';

    protected static ?string $title = 'تغییر رمز عبور';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('current_password')
                    ->label('رمز عبور فعلی')
                    ->password()
                    ->revealable()
                    ->required(),
                TextInput::make('password')
                    ->label('رمز عبور جدید')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label('تکرار رمز عبور جدید')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = Auth::user();

        if (! Hash::check($data['current_password'], $user->password)) {
            Notification::make()
                ->title('رمز عبور فعلی اشتباه است.')
                ->danger()
                ->send();

            return;
        }

        $user->update(['password' => Hash::make($data['password'])]);

        Notification::make()
            ->title('رمز عبور با موفقیت تغییر یافت.')
            ->success()
            ->send();

        $this->form->fill();
    }
}
