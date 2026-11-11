<?php

namespace Tests\Feature;

use App\Filament\Resources\Books\Pages\ListBooks;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanService;
use Carbon\Carbon;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_student_can_register_borrow_and_return_a_book_and_admin_can_review_the_loan(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');

        $this->get('/admin/register')->assertOk();

        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'Member Test',
                'email' => 'member@example.test',
                'password' => 'password',
                'passwordConfirmation' => 'password',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $student = User::where('email', 'member@example.test')->firstOrFail();
        $this->assertSame('siswa', $student->role);
        $this->assertNull($student->kelas);

        $book = Book::create([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'publisher' => 'Test Publisher',
            'year' => 2026,
            'stock' => 1,
        ]);

        $this->actingAs($student);

        Livewire::test(ListBooks::class)
            ->callTableAction('pinjam', $book);

        $loan = Loan::where('user_id', $student->id)->firstOrFail();
        $this->assertSame('dipinjam', $loan->status);
        $this->assertSame('2026-11-08', $loan->due_date->toDateString());
        $this->assertSame(0, $book->fresh()->stock);

        Carbon::setTestNow('2026-11-10 10:00:00');

        Livewire::test(ListLoans::class)
            ->callTableAction('kembalikan', $loan);

        $loan->refresh();
        $this->assertSame('dikembalikan', $loan->status);
        $this->assertSame(2000, (int) $loan->fine);
        $this->assertNotNull($loan->returned_at);
        $this->assertSame(1, $book->fresh()->stock);

        $admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListLoans::class)
            ->assertSee($student->name)
            ->assertSee($book->title);
    }

    public function test_student_is_blocked_from_admin_book_and_member_routes(): void
    {
        $student = User::create([
            'name' => 'Member Test',
            'email' => 'member@example.test',
            'password' => 'password',
            'role' => 'siswa',
        ]);
        $book = Book::create([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'publisher' => 'Test Publisher',
            'year' => 2026,
            'stock' => 1,
        ]);

        $this->actingAs($student)
            ->get('/admin/books/create')
            ->assertForbidden();

        $this->get("/admin/books/{$book->getKey()}/edit")
            ->assertForbidden();

        $this->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_transaction_changes_preserve_book_stock(): void
    {
        $admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $student = User::create([
            'name' => 'Member Test',
            'email' => 'member@example.test',
            'password' => 'password',
            'role' => 'siswa',
        ]);
        $firstBook = Book::create([
            'title' => 'First Book',
            'author' => 'Test Author',
            'publisher' => 'Test Publisher',
            'year' => 2026,
            'stock' => 2,
        ]);
        $secondBook = Book::create([
            'title' => 'Second Book',
            'author' => 'Test Author',
            'publisher' => 'Test Publisher',
            'year' => 2026,
            'stock' => 1,
        ]);
        $service = app(LoanService::class);
        $loan = $service->create([
            'user_id' => $student->getKey(),
            'book_id' => $firstBook->getKey(),
            'loan_date' => today()->toDateString(),
            'due_date' => today()->addDays(7)->toDateString(),
            'status' => 'dipinjam',
        ], $admin);

        $this->assertSame(1, $firstBook->fresh()->stock);

        $updatedLoan = $service->update($loan, [
            'user_id' => $student->getKey(),
            'book_id' => $secondBook->getKey(),
            'loan_date' => today()->toDateString(),
            'due_date' => today()->addDays(7)->toDateString(),
            'status' => 'dipinjam',
        ], $admin);

        $this->assertSame($secondBook->getKey(), $updatedLoan->book_id);
        $this->assertSame(2, $firstBook->fresh()->stock);
        $this->assertSame(0, $secondBook->fresh()->stock);

        $service->delete($updatedLoan, $admin);

        $this->assertSame(1, $secondBook->fresh()->stock);
    }
}
