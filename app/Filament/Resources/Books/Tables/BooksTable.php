<?php

namespace App\Filament\Resources\Books\Tables;

use App\Models\Book;
use App\Services\LoanService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('author')
                    ->searchable(),
                TextColumn::make('publisher')
                    ->searchable(),
                TextColumn::make('year')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('stock')
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
                Filter::make('tersedia')
                    ->label('Buku tersedia')
                    ->query(fn (Builder $query): Builder => $query->where('stock', '>', 0)),
            ])
            ->recordActions([
                Action::make('pinjam')
                    ->icon('heroicon-o-book-open')
                    ->requiresConfirmation()
                    ->visible(fn () => ! auth()->user()->isAdmin())
                    ->disabled(fn (Book $record) => $record->stock < 1)
                    ->action(function (Book $record) {
                        try {
                            app(LoanService::class)->borrowFor(auth()->user(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($exception->validator->errors()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Buku berhasil dipinjam')->success()->send();
                    }),
                EditAction::make()
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin()),
                ])
                    ->visible(fn () => auth()->user()->isAdmin()),
            ]);
    }
}
