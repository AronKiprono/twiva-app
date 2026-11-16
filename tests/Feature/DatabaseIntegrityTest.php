<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_persist_business_fields_and_resolve_relationships(): void
    {
        $payment = $this->createPayment();
        $wallet = $payment->wallet;
        $user = $wallet->user;
        $transaction = WalletTransaction::create($this->ledgerAttributes($payment));

        $this->assertTrue($user->wallet->is($wallet));
        $this->assertTrue($wallet->payments->sole()->is($payment));
        $this->assertTrue($wallet->transactions->sole()->is($transaction));
        $this->assertTrue($payment->transactions->sole()->is($transaction));
        $this->assertTrue($transaction->wallet->is($wallet));
        $this->assertTrue($transaction->payment->is($payment));
        $this->assertSame(0, $wallet->balance_minor);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('credit', $transaction->type);
    }

    public function test_database_rejects_a_second_wallet_for_the_same_user(): void
    {
        $wallet = $this->createPayment()->wallet;

        $this->assertIntegrityViolation(fn () => DB::table('wallets')->insert([
            'user_id' => $wallet->user_id,
        ]));
    }

    public function test_database_rejects_duplicate_payment_references(): void
    {
        $payment = $this->createPayment();

        $this->assertIntegrityViolation(fn () => DB::table('payments')->insert([
            'wallet_id' => $payment->wallet_id,
            'reference' => $payment->reference,
            'amount_minor' => 100,
            'status' => 'pending',
        ]));
    }

    #[DataProvider('ledgerTypes')]
    public function test_database_rejects_duplicate_ledger_entries_of_the_same_type(string $type): void
    {
        $attributes = $this->ledgerAttributes($this->createPayment(), $type);
        DB::table('wallet_transactions')->insert($attributes);

        $this->assertIntegrityViolation(fn () => DB::table('wallet_transactions')->insert($attributes));
    }

    public static function ledgerTypes(): array
    {
        return [
            'credit' => ['credit'],
            'debit' => ['debit'],
        ];
    }

    public function test_one_credit_and_one_debit_are_allowed_for_a_payment(): void
    {
        $payment = $this->createPayment();
        DB::table('wallet_transactions')->insert([
            $this->ledgerAttributes($payment, 'credit'),
            $this->ledgerAttributes($payment, 'debit'),
        ]);

        $this->assertSame(2, $payment->transactions()->count());
    }

    public function test_different_payments_can_each_have_a_credit(): void
    {
        $payment = $this->createPayment();
        $otherPayment = $this->createPayment($payment->wallet);
        DB::table('wallet_transactions')->insert([
            $this->ledgerAttributes($payment),
            $this->ledgerAttributes($otherPayment),
        ]);

        $this->assertSame(2, $payment->wallet->transactions()->count());
    }

    #[DataProvider('invalidParentReferences')]
    public function test_database_rejects_missing_or_null_parent_references(
        string $table,
        string $column,
        ?int $invalidId,
    ): void {
        $payment = $this->createPayment();
        $attributes = match ($table) {
            'wallets' => ['user_id' => $payment->wallet->user_id],
            'payments' => [
                'wallet_id' => $payment->wallet_id,
                'reference' => 'invalid-parent',
                'amount_minor' => 100,
                'status' => 'pending',
            ],
            'wallet_transactions' => $this->ledgerAttributes($payment),
        };
        $attributes[$column] = $invalidId;

        $this->assertIntegrityViolation(fn () => DB::table($table)->insert($attributes));
    }

    public static function invalidParentReferences(): array
    {
        return [
            'wallet missing user' => ['wallets', 'user_id', 999999999],
            'wallet null user' => ['wallets', 'user_id', null],
            'payment missing wallet' => ['payments', 'wallet_id', 999999999],
            'payment null wallet' => ['payments', 'wallet_id', null],
            'ledger missing wallet' => ['wallet_transactions', 'wallet_id', 999999999],
            'ledger null wallet' => ['wallet_transactions', 'wallet_id', null],
            'ledger missing payment' => ['wallet_transactions', 'payment_id', 999999999],
            'ledger null payment' => ['wallet_transactions', 'payment_id', null],
        ];
    }

    public function test_ledger_wallet_must_match_the_payments_wallet(): void
    {
        $payment = $this->createPayment();
        $otherWallet = $this->createPayment()->wallet;
        $attributes = $this->ledgerAttributes($payment);
        $attributes['wallet_id'] = $otherWallet->id;

        $this->assertIntegrityViolation(fn () => DB::table('wallet_transactions')->insert($attributes));
    }

    public function test_payment_cannot_change_wallet_after_a_ledger_entry_exists(): void
    {
        $payment = $this->createPayment();
        $otherWallet = $this->createPayment()->wallet;
        DB::table('wallet_transactions')->insert($this->ledgerAttributes($payment));

        $this->assertIntegrityViolation(fn () => DB::table('payments')
            ->where('id', $payment->id)
            ->update(['wallet_id' => $otherWallet->id]));
    }

    #[DataProvider('financialParents')]
    public function test_database_blocks_deletion_of_parents_with_financial_dependents(string $table): void
    {
        $payment = $this->createPayment();
        DB::table('wallet_transactions')->insert($this->ledgerAttributes($payment));
        $id = match ($table) {
            'users' => $payment->wallet->user_id,
            'wallets' => $payment->wallet_id,
            'payments' => $payment->id,
        };

        $this->assertIntegrityViolation(fn () => DB::table($table)->where('id', $id)->delete());
    }

    public static function financialParents(): array
    {
        return [
            'user with wallet' => ['users'],
            'wallet with payments and ledger entries' => ['wallets'],
            'payment with ledger entries' => ['payments'],
        ];
    }

    public function test_database_allows_explicit_deletion_in_dependency_order(): void
    {
        $payment = $this->createPayment();
        $wallet = $payment->wallet;
        $user = $wallet->user;
        $transaction = WalletTransaction::create($this->ledgerAttributes($payment));

        $transaction->delete();
        $payment->delete();
        $wallet->delete();
        $user->delete();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_money_round_trips_as_integers_beyond_the_32_bit_range(): void
    {
        $amount = 5_000_000_000;
        $payment = $this->createPayment();
        $payment->update(['amount_minor' => $amount]);
        $payment->wallet->update(['balance_minor' => -$amount]);
        $transaction = WalletTransaction::create([
            ...$this->ledgerAttributes($payment),
            'amount_minor' => $amount,
        ]);

        $this->assertSame($amount, $payment->fresh()->amount_minor);
        $this->assertSame(-$amount, $payment->wallet->fresh()->balance_minor);
        $this->assertSame($amount, $transaction->fresh()->amount_minor);
    }

    private function createPayment(?Wallet $wallet = null): Payment
    {
        $wallet ??= Wallet::create(['user_id' => User::factory()->create()->id]);

        return Payment::create([
            'wallet_id' => $wallet->id,
            'reference' => (string) Str::uuid(),
            'amount_minor' => 100,
            'status' => 'pending',
        ]);
    }

    private function ledgerAttributes(Payment $payment, string $type = 'credit'): array
    {
        return [
            'wallet_id' => $payment->wallet_id,
            'payment_id' => $payment->id,
            'amount_minor' => $payment->amount_minor,
            'type' => $type,
        ];
    }

    private function assertIntegrityViolation(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('23', (string) $exception->errorInfo[0]);

            return;
        }

        $this->fail('The database accepted a write that should violate an integrity constraint.');
    }
}
