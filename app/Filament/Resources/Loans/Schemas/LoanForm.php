<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Models\Book;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Anggota')
                    ->options(fn () => User::query()
                        ->where('role', 'siswa')
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required(fn () => auth()->user()->isAdmin())
                    ->visible(fn () => auth()->user()->isAdmin()),
                Select::make('book_id')
                    ->label('Buku')
                    ->options(fn () => Book::query()
                        ->when(! auth()->user()->isAdmin(), fn ($query) => $query->where('stock', '>', 0))
                        ->orderBy('title')
                        ->pluck('title', 'id'))
                    ->searchable()
                    ->required()
                    ->validationAttribute('buku'),
                DatePicker::make('loan_date')
                    ->required(fn () => auth()->user()->isAdmin())
                    ->visible(fn () => auth()->user()->isAdmin()),
                DatePicker::make('due_date')
                    ->required(fn () => auth()->user()->isAdmin())
                    ->visible(fn () => auth()->user()->isAdmin()),
                DatePicker::make('returned_at')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Select::make('status')
                    ->options([
                        'dipinjam' => 'Dipinjam',
                        'dikembalikan' => 'Dikembalikan',
                    ])
                    ->required(fn () => auth()->user()->isAdmin())
                    ->default('dipinjam')
                    ->visible(fn () => auth()->user()->isAdmin()),
                TextInput::make('fine')
                    ->required(fn () => auth()->user()->isAdmin())
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->visible(fn () => auth()->user()->isAdmin()),
            ]);
    }
}
