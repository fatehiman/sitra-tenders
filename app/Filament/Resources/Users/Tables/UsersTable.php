<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\RoleName;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Morilog\Jalali\Jalalian;

/** The کاربران list table: columns, filters and the edit button. */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // display_name is computed in PHP (company name, or person's
                // name), so there is no column to search. The explicit list
                // tells the table which real columns to search instead.
                TextColumn::make('display_name')
                    ->label('نام / نام شرکت')
                    ->searchable(['first_name', 'last_name', 'company_name']),
                TextColumn::make('mobile')
                    ->label('موبایل')
                    ->searchable(),
                TextColumn::make('national_id')
                    ->label('کد ملی')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('person_type')
                    ->label('نوع شخص')
                    ->badge(),
                // Roles live in their own table (spatie/laravel-permission),
                // so this follows the relationship and translates the stored
                // English name into its Persian label. tryFrom() returns null
                // for an unknown value rather than throwing, and the ?? then
                // falls back to showing the raw name.
                TextColumn::make('roles.name')
                    ->label('نقش')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (RoleName::tryFrom($state)?->label() ?? $state) : '-'),
                // mobile_verified_at holds a date or null; ->boolean() wants
                // true/false, so getStateUsing converts "has a date" into a
                // tick or a cross.
                IconColumn::make('mobile_verified_at')
                    ->label('موبایل تاییدشده')
                    ->boolean()
                    ->getStateUsing(fn ($record): bool => (bool) $record->mobile_verified_at),
                IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('تاریخ ثبت')
                    ->formatStateUsing(fn ($state) => $state ? Jalalian::fromDateTime($state)->format('Y/m/d H:i') : '-')
                    ->sortable(),
            ])
            // Dropdown filters shown above the table.
            ->filters([
                SelectFilter::make('roles')
                    ->label('نقش')
                    ->relationship('roles', 'name')
                    // Builds ['admin' => 'مدیر سیستم', ...] straight from the
                    // enum, so adding a role never needs editing this file.
                    ->options(array_combine(
                        array_map(fn (RoleName $r) => $r->value, RoleName::cases()),
                        array_map(fn (RoleName $r) => $r->label(), RoleName::cases()),
                    )),
                SelectFilter::make('is_active')
                    ->label('وضعیت')
                    ->options(['1' => 'فعال', '0' => 'غیرفعال']),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
