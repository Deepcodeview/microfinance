<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;

/**
 * Turns a pending payment into a confirmed one (allocation + journal + receipt), and reverses confirmed ones.
 * Every method runs inside the caller's transaction.
 */
final class PaymentService
{
    public static function confirm(array $payment, int $userId): void
    {
        if ($payment['status'] !== 'pending') {
            throw HttpException::conflict("Payment {$payment['payment_number']} is already {$payment['status']}.");
        }
        $date = $payment['business_date'];
        $amount = $payment['amount'];
        $debitAccount = Ledger::receiptAccount($payment['method']);

        if ($payment['account_type'] === 'loan') {
            $loan = self::lockLoan((int) $payment['loan_id']);
            LoanService::assertActive($loan);
            if ($loan['status'] === 'written_off' && $payment['purpose'] === 'settlement') {
                throw HttpException::validation(['purpose' => 'Written-off loans take ordinary recovery payments, not settlements.']);
            }
            LoanService::applyLateFees($loan, $date);
            // Bring income recognition current before splitting the cash.
            LoanAccounting::accrue($loan, $date, $userId);
            self::assertLoanAmount($loan, $payment['purpose'], $amount, $date);

            $result = LoanService::allocate($loan, $amount, $date, $payment['purpose'] === 'settlement');
            foreach ($result['allocations'] as [$installmentId, $component, $value, $gl]) {
                Database::insert('payment_allocations', [
                    'payment_id' => $payment['id'], 'installment_id' => $installmentId, 'component' => $component, 'amount' => $value, 'gl_account' => $gl,
                ]);
            }
            $lines = LoanService::paymentLines($debitAccount, $amount, $result['allocations']);
            $loan = self::lockLoan((int) $loan['id']);
            LoanAccounting::classify($loan, $date, $userId);
            $description = "Repayment {$payment['payment_number']} on {$loan['loan_number']}";
        } else {
            Settings::requireDeposits();
            $account = DepositService::lock((int) $payment['deposit_account_id']);
            self::assertDepositAmount($account, $amount, $date);
            DepositService::accrue($account, (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'), $userId);
            Database::query('UPDATE deposit_accounts SET principal_balance = principal_balance + ? WHERE id = ?', [$amount, $account['id']]);
            Database::insert('payment_allocations', [
                'payment_id' => $payment['id'], 'installment_id' => null, 'component' => 'deposit', 'amount' => $amount, 'gl_account' => Ledger::CUSTOMER_DEPOSITS,
            ]);
            $lines = [
                [$debitAccount, $amount, '0'],
                [Ledger::CUSTOMER_DEPOSITS, '0', $amount],
            ];
            $description = "Contribution {$payment['payment_number']} to {$account['deposit_number']}";
        }

        $entryId = Ledger::post($date, (int) $payment['branch_id'], 'payment', (int) $payment['id'], $description, $lines, $userId);
        Database::query(
            "UPDATE payments SET status = 'confirmed', confirmed_by = ?, confirmed_at = UTC_TIMESTAMP(), journal_entry_id = ? WHERE id = ?",
            [$userId, $entryId, $payment['id']],
        );
        $receipt = self::issueReceipt((int) $payment['id']);
        $accountNumber = $payment['account_type'] === 'loan' ? $loan['loan_number'] : $account['deposit_number'];
        Notifier::customer((int) $payment['customer_id'], 'payment.confirmed', 'Payment received',
            'We received Rs ' . Money::fmt($amount) . " for {$accountNumber} on {$date}. Receipt {$receipt}.",
            "/payments/{$payment['id']}/receipt", "payment:{$payment['id']}");
    }

    /** Checks a reversal is possible now (used when it is requested and again when it is approved). */
    public static function assertReversible(array $payment): void
    {
        if ($payment['status'] !== 'confirmed') {
            throw HttpException::conflict('Only confirmed payments can be reversed.');
        }
        if ($payment['method'] === 'cash' && $payment['handover_id'] !== null
            && Database::value('SELECT status FROM cash_handovers WHERE id = ?', [$payment['handover_id']]) === 'submitted') {
            throw HttpException::conflict('This cash is in a handover awaiting verification. Verify the handover first.');
        }
        if ($payment['settlement_id'] !== null) {
            throw HttpException::conflict('This UPI payment has already been settled to the bank. Record a refund/adjustment through a manual journal instead.');
        }
    }

    public static function reverse(array $payment, string $reason, int $userId): void
    {
        self::assertReversible($payment);
        $today = Settings::today();

        // Cash already verified into the branch is reversed out of branch cash, not the agent's.
        $creditAccount = Ledger::receiptAccount($payment['method']);
        if ($payment['method'] === 'cash' && $payment['handover_id'] !== null) {
            $creditAccount = Ledger::BRANCH_CASH;
        }

        if ($payment['account_type'] === 'loan') {
            $loan = self::lockLoan((int) $payment['loan_id']);
            LoanAccounting::accrue($loan, $today, $userId);
            $lines = LoanService::reversalLines((int) $payment['id'], $creditAccount, $payment['amount'], $today);
            LoanService::unallocate($loan, (int) $payment['id']);
            $entryId = Ledger::post($today, (int) $payment['branch_id'], 'payment_reversal', (int) $payment['id'],
                "Reversal of {$payment['payment_number']}: {$reason}", $lines, $userId, (int) $payment['journal_entry_id']);
            LoanAccounting::classify(self::lockLoan((int) $loan['id']), $today, $userId);
        } else {
            $account = DepositService::lock((int) $payment['deposit_account_id']);
            if (!in_array($account['status'], ['active', 'matured'], true)) {
                throw HttpException::conflict("Deposit account is {$account['status']}; cancel the payout first.");
            }
            DepositService::accrue($account, (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'), $userId);
            if (Money::cmp($account['principal_balance'], $payment['amount']) < 0) {
                throw HttpException::conflict('Deposit balance is lower than this contribution.');
            }
            Database::query('UPDATE deposit_accounts SET principal_balance = principal_balance - ? WHERE id = ?', [$payment['amount'], $account['id']]);
            $remap = $creditAccount === Ledger::BRANCH_CASH ? [Ledger::AGENT_CASH => Ledger::BRANCH_CASH] : [];
            $entryId = Ledger::reverse((int) $payment['journal_entry_id'], $today, "Reversal of {$payment['payment_number']}: {$reason}", $userId, $remap);
        }

        Notifier::customer((int) $payment['customer_id'], 'payment.reversed', 'Payment reversed',
            "Payment {$payment['payment_number']} of Rs " . Money::fmt($payment['amount']) . " was reversed: {$reason}. Contact your branch with questions.",
            null, "payment-reversed:{$payment['id']}");
        Database::query(
            "UPDATE payments SET status = 'reversed', reversed_by = ?, reversed_at = UTC_TIMESTAMP(), status_reason = ?, reversal_entry_id = ? WHERE id = ?",
            [$userId, $reason, $entryId, $payment['id']],
        );
    }

    /** Validates an amount against the loan before anything is written. */
    public static function assertLoanAmount(array $loan, string $purpose, string $amount, string $date): void
    {
        if ($purpose === 'settlement') {
            $quote = LoanService::settlementQuote($loan, $date);
            if (!$quote['allowed']) {
                throw HttpException::validation(['purpose' => 'This product does not allow prepayment or foreclosure.']);
            }
            if (Money::cmp($amount, $quote['settlement_amount']) !== 0) {
                throw HttpException::validation(['amount' => "Settlement amount must be exactly {$quote['settlement_amount']}."]);
            }
            return;
        }
        $max = LoanService::maxPayable((int) $loan['id'], $date);
        if (Money::cmp($amount, $max) > 0) {
            throw HttpException::validation(['amount' => "Amount exceeds the total outstanding of {$max}."]);
        }
    }

    public static function assertDepositAmount(array $account, string $amount, string $date): void
    {
        if ($account['status'] !== 'active') {
            throw HttpException::conflict("Deposit account {$account['deposit_number']} is {$account['status']}.");
        }
        if ($date >= $account['maturity_date']) {
            throw HttpException::conflict('Contributions are not accepted on or after the maturity date.');
        }
        if ($account['scheme'] === 'fixed') {
            if (Money::isPositive($account['principal_balance'])) {
                throw HttpException::conflict('This fixed deposit is already funded.');
            }
            if (Money::cmp($amount, $account['deposit_amount']) !== 0) {
                throw HttpException::validation(['amount' => 'Fixed deposit must be funded with exactly ' . Money::fmt($account['deposit_amount']) . '.']);
            }
        }
    }

    public static function lockLoan(int $loanId): array
    {
        return Database::one('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$loanId])
            ?? throw HttpException::notFound('Loan not found.');
    }

    private static function issueReceipt(int $paymentId): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $number = Database::nextNumber('receipt');
        Database::insert('receipts', [
            'receipt_number' => $number,
            'payment_id' => $paymentId,
            'verification_code' => $code,
        ]);
        return $number;
    }
}
