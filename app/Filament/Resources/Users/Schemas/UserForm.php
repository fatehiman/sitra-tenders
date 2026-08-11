<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Rules\IranianCompanyNationalId;
use App\Rules\IranianNationalId;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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
                    ->tel()
                    ->required()
                    ->rule('regex:/^09\d{9}$/')
                    ->unique(ignoreRecord: true),
                TextInput::make('national_id')
                    ->label('کد ملی')
                    ->required()
                    ->rules([new IranianNationalId])
                    ->unique(ignoreRecord: true),
                Select::make('person_type')
                    ->label('نوع شخص')
                    ->options(PersonType::class)
                    ->default(PersonType::Individual)
                    ->live()
                    ->required(),
                TextInput::make('company_name')
                    ->label('نام شرکت')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('person_type') === PersonType::Company->value)
                    ->required(fn (Get $get): bool => $get('person_type') === PersonType::Company->value),
                TextInput::make('company_national_id')
                    ->label('شناسه ملی')
                    ->rules([new IranianCompanyNationalId])
                    ->unique(ignoreRecord: true)
                    ->visible(fn (Get $get): bool => $get('person_type') === PersonType::Company->value)
                    ->required(fn (Get $get): bool => $get('person_type') === PersonType::Company->value),
                TextInput::make('password')
                    ->label('رمز عبور')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->helperText('در ویرایش، فقط در صورت نیاز به تغییر رمز عبور تکمیل شود.'),
                Select::make('role')
                    ->label('نقش')
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
}
