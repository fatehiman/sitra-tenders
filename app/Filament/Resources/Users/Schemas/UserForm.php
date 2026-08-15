<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Rules\IranianNationalId;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The admin-facing create/edit form for user accounts.
 *
 * This is the *other* way an account can come into existence. The public
 * route is App\Livewire\Register (mobile + SMS OTP); this one is for an
 * admin creating a user or a staff member directly, and deliberately skips
 * OTP entirely — the requirement is explicit that admin-created accounts
 * need no mobile confirmation.
 *
 * A Filament "schema" class is just a place to describe the fields. Filament
 * renders them, validates them, and reads/writes the model for us — this
 * file never touches the database itself.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('نام')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->label('نام خانوادگی')
                    ->required()
                    ->maxLength(255),
                TextInput::make('mobile')
                    ->label('شماره موبایل')
                    // ->tel() only changes the keyboard/input type in the
                    // browser; the regex below is the real enforcement.
                    ->tel()
                    ->required()
                    ->rule('regex:/^09\d{9}$/')
                    // ignoreRecord: true = "unique among all OTHER rows",
                    // otherwise editing a user would clash with itself.
                    ->unique(ignoreRecord: true),
                TextInput::make('national_id')
                    ->label('کد ملی')
                    ->required()
                    // کد ملی is genuinely checksum-validated, unlike شناسه
                    // ملی below — the individual algorithm is reliable.
                    ->rules([new IranianNationalId])
                    ->unique(ignoreRecord: true),
                Select::make('person_type')
                    ->label('نوع شخص')
                    // Options come straight from the enum's getLabel().
                    ->options(PersonType::class)
                    ->default(PersonType::Individual)
                    // ->live() re-renders the form on every change, which is
                    // what makes the two company fields appear/disappear.
                    ->live()
                    ->required(),
                TextInput::make('company_name')
                    ->label('نام شرکت')
                    ->maxLength(255)
                    // Both visibility and requiredness are closures reading
                    // the current person_type via Get — same condition twice
                    // because a hidden field must not also be required.
                    ->visible(fn (Get $get): bool => self::isCompany($get('person_type')))
                    ->required(fn (Get $get): bool => self::isCompany($get('person_type'))),
                TextInput::make('company_national_id')
                    ->label('شناسه ملی')
                    /*
                     * Format check only — 11 digits, nothing else. There is
                     * intentionally no checksum rule here (it used to be
                     * App\Rules\IranianCompanyNationalId, now deleted): the
                     * commonly-published شناسه ملی checksum rejects real,
                     * currently-issued IDs, so it blocked legitimate
                     * companies. Uniqueness is still enforced.
                     */
                    ->rules(['digits:11'])
                    ->validationMessages([
                        'digits' => 'شناسه ملی باید یک عدد ۱۱ رقمی باشد.',
                    ])
                    ->unique(ignoreRecord: true)
                    ->visible(fn (Get $get): bool => self::isCompany($get('person_type')))
                    ->required(fn (Get $get): bool => self::isCompany($get('person_type'))),
                TextInput::make('password')
                    ->label('رمز عبور')
                    // ->password() masks it; ->revealable() adds the eye icon.
                    ->password()
                    ->revealable()
                    // Required when creating, optional when editing.
                    ->required(fn (string $operation): bool => $operation === 'create')
                    // dehydrated() decides whether the value is included in
                    // the data handed to the model. Returning false for an
                    // empty field is what stops an untouched edit form from
                    // overwriting the existing password with "".
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->helperText('در ویرایش، فقط در صورت نیاز به تغییر رمز عبور تکمیل شود.'),
                Select::make('role')
                    ->label('نقش')
                    // `admin` is intentionally absent: admins are created by
                    // the seeder, not through the UI.
                    ->options([
                        RoleName::Staff->value => RoleName::Staff->label(),
                        RoleName::User->value => RoleName::User->label(),
                    ])
                    ->required(),
                Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true)
                    ->required(),
            ]);
    }

    /**
     * Is the person_type currently selected in the form «حقوقی» (a company)?
     *
     * WHY THIS IS NOT JUST `$state === PersonType::Company->value`:
     * `Select::make('person_type')->options(PersonType::class)` makes
     * Filament attach an *enum state cast* to the field (see
     * Filament\Forms\Components\Select::getEnumDefaultStateCast()), so
     * `$get('person_type')` hands back a PersonType **object**, not the
     * string 'company'. Comparing an object with a string is never true, so
     * the two حقوقی fields stayed hidden no matter what was selected —
     * that was the bug this method fixes.
     *
     * The string branch is kept because the same helper should stay correct
     * if this field is ever switched to plain string options (which is what
     * the public registration form uses — Radio with string keys — and why
     * that form never had the bug).
     */
    private static function isCompany(mixed $state): bool
    {
        if ($state instanceof PersonType) {
            return $state === PersonType::Company;
        }

        return $state === PersonType::Company->value;
    }
}
