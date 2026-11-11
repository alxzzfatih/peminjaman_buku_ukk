<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Services\LoanService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! auth()->user()->isAdmin()) {
            $data['user_id'] = auth()->id();
            $data['loan_date'] = today()->toDateString();
            $data['due_date'] = today()->addDays(7)->toDateString();
            $data['returned_at'] = null;
            $data['status'] = 'dipinjam';
            $data['fine'] = 0;
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(LoanService::class)->create($data, auth()->user());
    }
}
