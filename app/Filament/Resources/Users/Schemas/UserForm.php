<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Models\User;
use App\Rules\IranianNationalId;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

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
                    ->label('سطح دسترسی')
                    /*
                     * All three roles, «مدیر سیستم» included: an admin may
                     * create another admin from here. The one thing that is
                     * NOT allowed is changing your OWN role — see isSelf()
                     * below for why.
                     */
                    ->options(array_combine(
                        array_map(fn (RoleName $role): string => $role->value, RoleName::cases()),
                        array_map(fn (RoleName $role): string => $role->label(), RoleName::cases()),
                    ))
                    ->required()
                    /*
                     * A disabled field is also not "dehydrated" (not sent
                     * back with the saved data) in Filament, which is
                     * exactly what we want here: the role of your own
                     * account cannot be changed even by a crafted request,
                     * because the value never reaches the save at all.
                     * EditUser::mutateFormDataBeforeSave() is written to
                     * expect the missing key.
                     */
                    ->disabled(fn (?User $record): bool => self::isSelf($record))
                    ->helperText(fn (?User $record): ?string => self::isSelf($record)
                        ? 'سطح دسترسی حساب خودتان قابل تغییر نیست. در صورت نیاز، مدیر دیگری آن را تغییر دهد.'
                        : null),
                Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true)
                    ->required()
                    /*
                     * Same reasoning as the role field: an inactive account
                     * cannot enter the panel at all (see
                     * User::canAccessPanel()), so letting an admin switch
                     * this off on their own row is another way of locking
                     * yourself out.
                     */
                    ->disabled(fn (?User $record): bool => self::isSelf($record))
                    ->helperText(fn (?User $record): ?string => self::isSelf($record)
                        ? 'حساب خودتان را نمی‌توانید غیرفعال کنید.'
                        : null),
            ]);
    }

    /**
     * Is the record being edited the logged-in admin's own account?
     *
     * `$record` is null on the create page (there is nothing to edit yet),
     * so every guard built on this is simply off while creating.
     *
     * WHY the two fields above are locked on your own row: together with
     * UserPolicy::delete() refusing your own account, this is what
     * guarantees the system can never be left without a reachable admin.
     * An admin may demote, deactivate or delete *another* admin — but never
     * the one account they are signed into, so at least one working admin
     * always survives whatever they do.
     */
    private static function isSelf(?User $record): bool
    {
        return $record !== null && Auth::user()?->is($record) === true;
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
