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

/**
 * "تغییر رمز عبور" — available to every logged-in role.
 *
 * A Filament Page is a standalone screen with no model behind it, unlike a
 * Resource (which is a list + create + edit around one table). There is no
 * policy on this page because it only ever acts on the person using it.
 */
class ChangePassword extends Page
{
    // The Blade file that draws the page: resources/views/filament/pages/.
    protected string $view = 'filament.pages.change-password';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'تغییر رمز عبور';

    protected static ?string $title = 'تغییر رمز عبور';

    /**
     * Pin this to the BOTTOM of the sidebar.
     *
     * Filament sorts navigation items by this number ascending, and the
     * three resources use 1 (مناقصات), 2 (کالاها) and 3 (کاربران). A page
     * that leaves the sort null is treated as 0 and jumps to the top, which
     * is how «تغییر رمز عبور» ended up above the actual work. 99 leaves
     * plenty of room for new resources to be inserted before it.
     */
    protected static ?int $navigationSort = 99;

    /**
     * @var array<string, mixed>
     */
    // Holds the form's live values — see ->statePath('data') below.
    public ?array $data = [];

    /** Runs once when the page opens; starts the form off empty. */
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
                    // ->confirmed() requires a matching field named
                    // <this field>_confirmation — declared just below.
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label('تکرار رمز عبور جدید')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            // Store every field's value under $this->data instead of as
            // separate public properties on the page.
            ->statePath('data');
    }

    public function save(): void
    {
        // getState() validates first and throws if anything is invalid, so
        // everything below can trust the values.
        $data = $this->form->getState();

        $user = Auth::user();

        // Requiring the current password is what stops someone who walks up
        // to an unattended, already-logged-in browser from taking the
        // account over. Hash::check compares a plain value to a stored hash.
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

        // Clear the fields so the new password isn't left sitting in the
        // form (and in the page state) after a successful change.
        $this->form->fill();
    }
}
