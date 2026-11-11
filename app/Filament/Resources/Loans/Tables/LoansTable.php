<?php

namespace App\Filament\Resources\Loans\Tables;

use App\Models\Loan;
use App\Services\LoanService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Anggota')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('book.title')
                    ->label('Buku')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('loan_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('returned_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'dipinjam' ? 'warning' : 'success'),
                TextColumn::make('fine')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'dipinjam' => 'Dipinjam',
                        'dikembalikan' => 'Dikembalikan',
                    ]),
                Filter::make('terlambat')
                    ->label('Pinjaman terlambat')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'dipinjam')
                        ->whereDate('due_date', '<', today())),
            ])
            ->recordActions([
                Action::make('kembalikan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (Loan $record) => $record->status === 'dipinjam')
                    ->action(function (Loan $record) {
                        app(LoanService::class)->return($record, auth()->user());
                        Notification::make()->title('Buku sudah dikembalikan')->success()->send();
                    }),
                EditAction::make()
                    ->visible(fn () => auth()->user()->isAdmin()),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(fn (Loan $record) => app(LoanService::class)->delete($record, auth()->user())),
            ]);
    }
}
