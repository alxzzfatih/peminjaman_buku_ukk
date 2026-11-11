<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Services\LoanService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLoan extends EditRecord
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(fn (Loan $record) => app(LoanService::class)->delete($record, auth()->user())),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(LoanService::class)->update($record, $data, auth()->user());
    }
}
