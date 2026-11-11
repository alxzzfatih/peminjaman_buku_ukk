<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanService
{
    private const ACTIVE = 'dipinjam';

    private const RETURNED = 'dikembalikan';

    private const MAX_ACTIVE_LOANS = 3;

    public function borrowFor(User $borrower, Book $book): Loan
    {
        abort_if($borrower->isAdmin(), 403);

        return $this->create([
            'book_id' => $book->getKey(),
        ], $borrower);
    }

    public function create(array $data, User $actor): Loan
    {
        if (! $actor->isAdmin()) {
            $data = [
                'user_id' => $actor->getKey(),
                'book_id' => $data['book_id'] ?? null,
                'loan_date' => today()->toDateString(),
                'due_date' => today()->addDays(7)->toDateString(),
                'returned_at' => null,
                'status' => self::ACTIVE,
                'fine' => 0,
            ];
        }

        return DB::transaction(function () use ($data): Loan {
            $user = User::query()->lockForUpdate()->findOrFail($data['user_id']);
            $this->ensureStudent($user);
            $book = Book::query()->lockForUpdate()->findOrFail($data['book_id']);
            $status = $data['status'] ?? self::ACTIVE;

            $this->validateStatus($status);

            if ($status === self::ACTIVE) {
                $this->ensureAvailable($book);
                $this->ensureLoanLimit($user->getKey());
                $book->decrement('stock');
            }

            $returnedAt = $status === self::RETURNED
                ? Carbon::parse($data['returned_at'] ?? today())->toDateString()
                : null;

            return Loan::create([
                'user_id' => $user->getKey(),
                'book_id' => $book->getKey(),
                'loan_date' => $data['loan_date'] ?? today()->toDateString(),
                'due_date' => $data['due_date'] ?? today()->addDays(7)->toDateString(),
                'returned_at' => $returnedAt,
                'status' => $status,
                'fine' => $status === self::RETURNED ? max(0, (int) ($data['fine'] ?? 0)) : 0,
            ]);
        });
    }

    public function update(Loan $loan, array $data, User $actor): Loan
    {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($loan, $data): Loan {
            $current = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
            $userId = (int) ($data['user_id'] ?? $current->user_id);
            $bookId = (int) ($data['book_id'] ?? $current->book_id);
            $status = $data['status'] ?? $current->status;

            $this->validateStatus($status);

            $userIds = collect([$current->user_id, $userId])->unique()->sort()->values();
            $users = User::query()->whereKey($userIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $users->get($userId);
            abort_unless($user, 404);
            $this->ensureStudent($user);

            $bookIds = collect([$current->book_id, $bookId])->unique()->sort()->values();
            $books = Book::query()->whereKey($bookIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $oldBook = $books->get($current->book_id);
            $newBook = $books->get($bookId);
            abort_unless($oldBook && $newBook, 404);

            $wasActive = $current->status === self::ACTIVE;
            $willBeActive = $status === self::ACTIVE;
            $bookChanged = (int) $current->book_id !== $bookId;

            if ($willBeActive) {
                $this->ensureLoanLimit($userId, $current->getKey());

                if (! $wasActive || $bookChanged) {
                    $this->ensureAvailable($newBook);
                }
            }

            if ($wasActive && (! $willBeActive || $bookChanged)) {
                $oldBook->increment('stock');
            }

            if ($willBeActive && (! $wasActive || $bookChanged)) {
                $newBook->decrement('stock');
            }

            $dueDate = $data['due_date'] ?? $current->due_date->toDateString();
            $returnedAt = $willBeActive
                ? null
                : Carbon::parse($data['returned_at'] ?? $current->returned_at ?? today())->toDateString();

            $current->update([
                'user_id' => $userId,
                'book_id' => $bookId,
                'loan_date' => $data['loan_date'] ?? $current->loan_date->toDateString(),
                'due_date' => $dueDate,
                'returned_at' => $returnedAt,
                'status' => $status,
                'fine' => $willBeActive ? 0 : max(0, (int) ($data['fine'] ?? $current->fine)),
            ]);

            return $current->refresh();
        });
    }

    public function return(Loan $loan, User $actor): Loan
    {
        return DB::transaction(function () use ($loan, $actor): Loan {
            $current = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
            $this->authorizeRecord($current, $actor);

            if ($current->status !== self::ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => 'Transaksi ini sudah dikembalikan.',
                ]);
            }

            $book = Book::query()->lockForUpdate()->findOrFail($current->book_id);
            $lateDays = max(0, (int) Carbon::parse($current->due_date)->diffInDays(now()->startOfDay(), false));

            $current->update([
                'returned_at' => today()->toDateString(),
                'status' => self::RETURNED,
                'fine' => $lateDays * 1000,
            ]);
            $book->increment('stock');

            return $current->refresh();
        });
    }

    public function delete(Loan $loan, User $actor): void
    {
        DB::transaction(function () use ($loan, $actor): void {
            $current = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
            $this->authorizeRecord($current, $actor);

            if ($current->status === self::ACTIVE) {
                Book::query()->whereKey($current->book_id)->lockForUpdate()->firstOrFail()->increment('stock');
            }

            $current->delete();
        });
    }

    private function authorizeRecord(Loan $loan, User $actor): void
    {
        abort_unless($actor->isAdmin() || (int) $loan->user_id === (int) $actor->getKey(), 403);
    }

    private function ensureAvailable(Book $book): void
    {
        if ($book->stock < 1) {
            throw ValidationException::withMessages([
                'book_id' => 'Stok buku tidak tersedia.',
            ]);
        }
    }

    private function ensureStudent(User $user): void
    {
        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'user_id' => 'Transaksi hanya dapat diberikan kepada anggota.',
            ]);
        }
    }

    private function ensureLoanLimit(int $userId, ?int $exceptLoanId = null): void
    {
        $activeLoans = Loan::query()
            ->where('user_id', $userId)
            ->where('status', self::ACTIVE)
            ->when($exceptLoanId, fn ($query) => $query->whereKeyNot($exceptLoanId))
            ->count();

        if ($activeLoans >= self::MAX_ACTIVE_LOANS) {
            throw ValidationException::withMessages([
                'book_id' => 'Maksimal pinjam 3 buku.',
            ]);
        }
    }

    private function validateStatus(string $status): void
    {
        if (! in_array($status, [self::ACTIVE, self::RETURNED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Status transaksi tidak valid.',
            ]);
        }
    }
}
