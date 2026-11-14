<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use LogicException;

/**
 * The general ledger. Every financial event posts one balanced entry here inside the caller's transaction.
 * Account codes are fixed by the seed; the accountant must approve this mapping before go-live.
 */
final class Ledger
{
    public const BRANCH_CASH = '1000';
    public const AGENT_CASH = '1010';
    public const BANK = '1020';
    public const PROVIDER_CLEARING = '1030';
    public const LOAN_RECEIVABLE = '1100';
    public const INTEREST_RECEIVABLE = '1110';
    public const LOAN_LOSS_PROVISION = '1190';
    public const CUSTOMER_DEPOSITS = '2000';
    public const DEPOSIT_INTEREST_PAYABLE = '2010';
    public const GST_PAYABLE = '2100';
    public const CASH_OVERAGE_SUSPENSE = '2200';
    public const ADVANCE_INTEREST = '2300';
    public const CAPITAL = '3000';
    public const OPENING_BALANCES = '3100';
    public const INTEREST_INCOME = '4000';
    public const FEE_INCOME = '4010';
    public const LATE_FEE_INCOME = '4020';
    public const MISC_INCOME = '4030';
    public const BAD_DEBT_RECOVERY = '4040';
    public const DEPOSIT_INTEREST_EXPENSE = '5000';
    public const CASH_SHORTAGE_EXPENSE = '5100';
    public const PROVISION_EXPENSE = '5200';
    public const LOAN_WRITE_OFF_EXPENSE = '5300';
    public const PROVIDER_CHARGES = '5400';
    public const OPERATING_EXPENSES = '5500';

    /**
     * Control accounts mirror a subledger (loans, deposits, agent cash, clearing). Only the system posts
     * to them; manual journals are refused so GL and subledgers can never drift apart.
     */
    public const CONTROL_ACCOUNTS = [
        self::AGENT_CASH, self::PROVIDER_CLEARING, self::LOAN_RECEIVABLE, self::INTEREST_RECEIVABLE,
        self::LOAN_LOSS_PROVISION, self::CUSTOMER_DEPOSITS, self::DEPOSIT_INTEREST_PAYABLE, self::ADVANCE_INTEREST,
    ];

    /** Chart of accounts seeded on install: code => [name, type, normal balance]. */
    public const CHART = [
        self::BRANCH_CASH => ['Branch cash', 'asset', 'debit'],
        self::AGENT_CASH => ['Agent cash in hand', 'asset', 'debit'],
        self::BANK => ['Bank account', 'asset', 'debit'],
        self::PROVIDER_CLEARING => ['Payment provider clearing', 'asset', 'debit'],
        self::LOAN_RECEIVABLE => ['Loan receivable – principal', 'asset', 'debit'],
        self::INTEREST_RECEIVABLE => ['Interest receivable on loans', 'asset', 'debit'],
        self::LOAN_LOSS_PROVISION => ['Provision for loan losses (contra-asset)', 'asset', 'credit'],
        self::CUSTOMER_DEPOSITS => ['Customer deposits', 'liability', 'credit'],
        self::DEPOSIT_INTEREST_PAYABLE => ['Accrued interest payable on deposits', 'liability', 'credit'],
        self::GST_PAYABLE => ['GST payable', 'liability', 'credit'],
        self::CASH_OVERAGE_SUSPENSE => ['Cash overage suspense', 'liability', 'credit'],
        self::ADVANCE_INTEREST => ['Interest received in advance', 'liability', 'credit'],
        self::CAPITAL => ["Owner's capital", 'equity', 'credit'],
        self::OPENING_BALANCES => ['Opening balances (accounts brought in)', 'equity', 'credit'],
        self::INTEREST_INCOME => ['Interest income on loans', 'income', 'credit'],
        self::FEE_INCOME => ['Processing & prepayment fee income', 'income', 'credit'],
        self::LATE_FEE_INCOME => ['Late fee income', 'income', 'credit'],
        self::MISC_INCOME => ['Miscellaneous income', 'income', 'credit'],
        self::BAD_DEBT_RECOVERY => ['Recovery of loans written off', 'income', 'credit'],
        self::DEPOSIT_INTEREST_EXPENSE => ['Interest expense on deposits', 'expense', 'debit'],
        self::CASH_SHORTAGE_EXPENSE => ['Cash shortage written off', 'expense', 'debit'],
        self::PROVISION_EXPENSE => ['Provision for loan losses (expense)', 'expense', 'debit'],
        self::LOAN_WRITE_OFF_EXPENSE => ['Loans written off', 'expense', 'debit'],
        self::PROVIDER_CHARGES => ['Payment provider charges', 'expense', 'debit'],
        self::OPERATING_EXPENSES => ['Operating expenses', 'expense', 'debit'],
    ];

    /** @var array<string, int> */
    private static array $accountIds = [];

    /**
     * @param list<array{0: string, 1: string, 2: string}> $lines [account code, debit, credit]; zero lines are dropped.
     */
    public static function post(
        string $entryDate,
        int $branchId,
        string $sourceType,
        ?int $sourceId,
        string $description,
        array $lines,
        ?int $postedBy,
        ?int $reversesEntryId = null,
    ): int {
        if (!Database::pdo()->inTransaction()) {
            throw new LogicException('Journal entries must be posted inside a database transaction.');
        }
        $closedThrough = Settings::closedThrough();
        if ($closedThrough !== null && $entryDate <= $closedThrough) {
            throw new \App\Core\HttpException(409, 'PERIOD_CLOSED', "The books are closed through {$closedThrough}; nothing can be posted on {$entryDate}.");
        }

        $lines = array_values(array_filter($lines, fn ($l) => !Money::isZero($l[1]) || !Money::isZero($l[2])));
        $debits = '0';
        $credits = '0';
        foreach ($lines as [$code, $debit, $credit]) {
            if (Money::cmp($debit, '0') < 0 || Money::cmp($credit, '0') < 0) {
                throw new LogicException("Negative amount on account {$code}.");
            }
            $debits = Money::add($debits, $debit);
            $credits = Money::add($credits, $credit);
        }
        if (count($lines) < 2 || Money::cmp($debits, $credits) !== 0) {
            throw new LogicException("Unbalanced journal entry for {$sourceType}#{$sourceId}: Dr {$debits} / Cr {$credits}.");
        }

        $entryId = Database::insert('journal_entries', [
            'entry_number' => Database::nextNumber('journal'),
            'entry_date' => $entryDate,
            'branch_id' => $branchId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => mb_substr($description, 0, 255),
            'reverses_entry_id' => $reversesEntryId,
            'posted_by' => $postedBy,
        ]);
        foreach ($lines as [$code, $debit, $credit]) {
            Database::insert('journal_lines', [
                'journal_entry_id' => $entryId,
                'account_id' => self::accountId($code),
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }
        return $entryId;
    }

    /**
     * Posts the mirror image of an entry. $remap swaps account codes (e.g. agent cash already handed over to branch cash).
     *
     * @param array<string, string> $remap
     */
    public static function reverse(int $entryId, string $entryDate, string $description, ?int $postedBy, array $remap = []): int
    {
        $entry = Database::one('SELECT branch_id, source_type, source_id FROM journal_entries WHERE id = ?', [$entryId]);
        $lines = Database::all(
            'SELECT a.code, l.debit, l.credit FROM journal_lines l JOIN accounting_accounts a ON a.id = l.account_id WHERE l.journal_entry_id = ?',
            [$entryId],
        );
        $mirrored = array_map(fn ($l) => [$remap[$l['code']] ?? $l['code'], $l['credit'], $l['debit']], $lines);
        return self::post($entryDate, (int) $entry['branch_id'], $entry['source_type'] . '_reversal', (int) $entry['source_id'], $description, $mirrored, $postedBy, $entryId);
    }

    /** Balance of an account in its normal direction, optionally for one branch. */
    public static function balance(string $code, ?int $branchId = null): string
    {
        return (string) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN a.normal_balance = 'debit' THEN l.debit - l.credit ELSE l.credit - l.debit END), 0)
               FROM journal_lines l JOIN accounting_accounts a ON a.id = l.account_id JOIN journal_entries e ON e.id = l.journal_entry_id
              WHERE a.code = ?" . ($branchId === null ? '' : ' AND e.branch_id = ?'),
            $branchId === null ? [$code] : [$code, $branchId],
        );
    }

    /**
     * Treasury check before money leaves: a branch can't pay out more physical cash than its vault holds,
     * and the bank can't go overdrawn unless an overdraft facility is enabled in settings.
     */
    public static function assertFunds(string $method, string $amount, int $branchId): void
    {
        if ($method === 'cash') {
            $available = self::balance(self::BRANCH_CASH, $branchId);
            if (Money::cmp($amount, $available) > 0) {
                throw new \App\Core\HttpException(409, 'INSUFFICIENT_FUNDS', 'Branch cash is Rs ' . Money::fmt($available) . '; cannot pay out Rs ' . Money::fmt($amount) . ' in cash. Move money to the branch first (Accounting → Funds → Bank → branch cash) or pay by UPI.');
            }
            return;
        }
        if (Settings::get('allow_bank_overdraft') === '1') {
            return;
        }
        $available = self::balance(self::BANK);
        if (Money::cmp($amount, $available) > 0) {
            throw new \App\Core\HttpException(409, 'INSUFFICIENT_FUNDS', 'Bank balance is Rs ' . Money::fmt($available) . '; cannot pay out Rs ' . Money::fmt($amount) . '. Add money first: Accounting → Funds → Add capital.');
        }
    }

    /** Ledger account for how money moved: cash sits with the collector until handed over. */
    public static function receiptAccount(string $method): string
    {
        return match ($method) {
            'cash' => self::AGENT_CASH,
            'upi' => self::PROVIDER_CLEARING,
            default => self::BANK,
        };
    }

    public static function paymentAccount(string $method): string
    {
        return $method === 'cash' ? self::BRANCH_CASH : self::BANK;
    }

    private static function accountId(string $code): int
    {
        if (!isset(self::$accountIds[$code])) {
            $id = Database::value('SELECT id FROM accounting_accounts WHERE code = ? AND is_active = 1', [$code]);
            if ($id === null) {
                throw new LogicException("Ledger account {$code} is missing. Run the seed script.");
            }
            self::$accountIds[$code] = (int) $id;
        }
        return self::$accountIds[$code];
    }
}
