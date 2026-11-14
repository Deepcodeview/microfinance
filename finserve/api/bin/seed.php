<?php

declare(strict_types=1);

// Seeds roles, permissions, chart of accounts, number sequences, settings, a head-office branch,
// a Super Admin and default loan/deposit products. Safe to run more than once.
// With --demo it also creates a branch with one user per staff role for local testing.
//
// Usage: php bin/seed.php [--demo]
// Passwords: SEED_PASSWORD env var, otherwise a random one is generated and printed once.
// The first Super Admin: SEED_ADMIN_EMAIL / SEED_ADMIN_NAME (default admin@finserve.local), created only
// while no Super Admin exists — re-running never adds a second owner account.

use App\Core\Database;
use App\Services\DepositTerms;
use App\Services\Ledger;
use App\Services\LoanTerms;

require dirname(__DIR__) . '/src/bootstrap.php';

$demo = in_array('--demo', $argv ?? [], true);
// The web installer passes the owner as $seedOwner (name, email, password) instead of environment variables.
$seedOwner ??= [];
$password = ($seedOwner['password'] ?? '') ?: (getenv('SEED_PASSWORD') ?: bin2hex(random_bytes(6)));

$roles = [
    'super_admin' => 'Owner / Super Admin',
    'admin' => 'Admin',
    'branch_manager' => 'Branch Manager',
    'collection_supervisor' => 'Collection Supervisor',
    'collection_agent' => 'Collection Agent',
    'loan_officer' => 'Loan Officer',
    'finance' => 'Finance / Accountant',
    'auditor' => 'Auditor / Compliance',
    'customer' => 'Customer',
];

$everyone = array_keys($roles);
// Loan officers onboard and apply; money is taken by field staff, branch heads and finance (bank/cheque receipts).
$collectors = ['super_admin', 'admin', 'branch_manager', 'collection_supervisor', 'collection_agent', 'finance'];

// permission => [description, roles]
$permissions = [
    'dashboard.view' => ['View the dashboard', $everyone],
    'customers.view' => ['View customers within scope', $everyone],
    'customers.create' => ['Create customers', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'collection_agent']],
    'customers.update' => ['Edit customer profiles and portal access', ['super_admin', 'admin', 'branch_manager', 'loan_officer']],
    'customers.assign' => ['Assign customers to agents', ['super_admin', 'admin', 'branch_manager', 'collection_supervisor']],
    'customers.scope.all' => ['Customer scope: all branches', ['super_admin', 'admin', 'finance', 'auditor']],
    'customers.scope.branch' => ['Customer scope: own branch', ['branch_manager', 'collection_supervisor', 'loan_officer']],
    'customers.scope.assigned' => ['Customer scope: assigned customers only', ['collection_agent']],
    'customers.scope.own' => ['Customer scope: own profile only', ['customer']],
    'customers.approve' => ['Approve customers who registered in the customer app', ['super_admin', 'admin', 'branch_manager']],
    'applications.review' => ['Accept or reject loan and savings applications from the customer app', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'collection_agent']],
    'applications.submit' => ['Apply for loans and savings in the customer app', ['customer']],
    'payments.self' => ['Pay own loan / savings installments by UPI from the customer app', ['customer']],
    'cash.float' => ['Give agents a cash float to carry (e.g. for paying out loans in the field)', ['super_admin', 'admin', 'branch_manager', 'finance']],
    'customers.locate' => ['Pin a customer\'s location for the collection map', ['super_admin', 'admin', 'branch_manager', 'collection_supervisor', 'collection_agent', 'loan_officer']],
    'migration.import' => ['Bring in loans and savings from before KBSAVE (past start date, amount already collected)', ['super_admin', 'admin']],
    'loans.disburse_field' => ['Pay out approved loans in cash in the field from the agent\'s cash in hand', ['collection_agent']],
    'loan_products.view' => ['View loan products and terms', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'finance', 'auditor', 'collection_agent']],
    'loan_products.manage' => ['Create loan products and change terms', ['super_admin', 'admin']],
    'loans.view' => ['View loans within scope', $everyone],
    'loans.apply' => ['Submit loan applications', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'collection_agent']],
    'loans.approve' => ['Approve or reject loan applications', ['super_admin', 'admin', 'branch_manager']],
    'loans.disburse' => ['Disburse approved loans', ['super_admin', 'admin', 'finance']],
    'payments.view' => ['View payments and receipts', $everyone],
    'payments.create' => ['Record collections', $collectors],
    'payments.confirm' => ['Confirm or fail digital payments', ['super_admin', 'admin', 'finance']],
    'payments.reverse' => ['Reverse confirmed payments', ['super_admin', 'finance']],
    'collections.view' => ['View the daily collection list', ['super_admin', 'admin', 'branch_manager', 'collection_supervisor', 'collection_agent']],
    'visits.create' => ['Record visit outcomes', ['collection_supervisor', 'collection_agent', 'branch_manager']],
    'cash.handover' => ['Submit day-end cash handover', $collectors],
    'cash.verify' => ['Verify agent cash handovers', ['super_admin', 'admin', 'branch_manager', 'collection_supervisor', 'finance']],
    'cash.resolve' => ['Resolve cash discrepancies', ['super_admin', 'finance']],
    'deposits.view' => ['View deposit products and accounts', $everyone],
    'deposit_products.manage' => ['Configure deposit products', ['super_admin', 'admin']],
    'products.delete' => ['Delete unused loan and savings plans', ['super_admin']],
    'deposits.open' => ['Open deposit accounts', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'collection_agent']],
    'payouts.request' => ['Request deposit payouts', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'customer']],
    'payouts.approve' => ['Approve deposit payouts', ['super_admin', 'admin', 'branch_manager']],
    'payouts.pay' => ['Execute approved payouts', ['super_admin', 'finance']],
    'reports.view' => ['View operational reports', ['super_admin', 'admin', 'branch_manager', 'collection_supervisor', 'finance', 'auditor']],
    'accounting.view' => ['View ledger, journal and trial balance', ['super_admin', 'admin', 'finance', 'auditor']],
    'users.manage' => ['Manage staff users and roles', ['super_admin', 'admin']],
    'audit.view' => ['View audit logs', ['super_admin', 'admin', 'auditor']],
    'settings.manage' => ['Change system settings and branches', ['super_admin']],
    'documents.view' => ['View KYC documents within scope', $everyone],
    'documents.upload' => ['Upload KYC documents', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'collection_agent', 'customer']],
    'documents.review' => ['Verify or reject KYC documents', ['super_admin', 'admin', 'branch_manager', 'loan_officer']],
    'support.view' => ['Raise and view service requests', $everyone],
    'support.manage' => ['Handle service requests', ['super_admin', 'admin', 'branch_manager', 'loan_officer', 'collection_supervisor']],
    'holidays.manage' => ['Maintain the holiday calendar', ['super_admin', 'admin']],
    'loans.approve_unlimited' => ['Approve loans above the delegated limit', ['super_admin', 'admin']],
    'loans.write_off' => ['Request write-off of NPA loans', ['super_admin', 'admin', 'finance', 'branch_manager']],
    'approvals.view' => ['View the approvals inbox', ['super_admin', 'admin', 'finance', 'branch_manager', 'auditor']],
    'approvals.financial' => ['Approve reversals and manual journals', ['super_admin', 'admin', 'finance']],
    'approvals.write_off' => ['Approve loan write-offs', ['super_admin', 'admin']],
    'accounting.journal' => ['Request manual journal entries', ['super_admin', 'finance']],
    'accounting.reconcile' => ['Reconcile settlements and run provisioning', ['super_admin', 'finance']],
    'accounting.close' => ['Close the business day', ['super_admin', 'finance']],
    'accounting.funds' => ['Add capital and move money between bank and branch cash', ['super_admin', 'finance']],
];

$sequences = [
    ['customer', 'CUS', 6],
    ['loan', 'LN', 8],
    ['deposit', 'DP', 8],
    ['payment', 'PAY', 10],
    ['receipt', 'RCP', 10],
    ['journal', 'JE', 10],
    ['payout', 'PO', 8],
    ['handover', 'CH', 8],
    ['service_request', 'SR', 8],
    ['approval', 'AP', 8],
    ['settlement', 'ST', 8],
    ['application', 'APP', 8],
    ['cash_movement', 'CM', 8],
];

Database::transaction(function () use ($roles, $permissions, $sequences, $password, $demo, $seedOwner) {
    foreach ($roles as $key => $name) {
        Database::query('INSERT INTO roles (role_key, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [$key, $name]);
    }
    foreach ($permissions as $key => [$description, $roleKeys]) {
        // The owner can do everything (customer-scope flags aside: they already see all customers).
        if (!str_starts_with($key, 'customers.scope.') && !in_array('super_admin', $roleKeys, true)) {
            $roleKeys[] = 'super_admin';
        }
        Database::query(
            'INSERT INTO permissions (permission_key, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)',
            [$key, $description],
        );
        // Re-sync the role mapping so permission changes here take effect on re-seed.
        Database::query('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.permission_key = ?', [$key]);
        foreach ($roleKeys as $roleKey) {
            Database::query(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT r.id, p.id FROM roles r, permissions p WHERE r.role_key = ? AND p.permission_key = ?',
                [$roleKey, $key],
            );
        }
    }
    foreach ($sequences as [$name, $prefix, $pad]) {
        Database::query('INSERT IGNORE INTO number_sequences (name, prefix, pad_length) VALUES (?, ?, ?)', [$name, $prefix, $pad]);
    }
    foreach (Ledger::CHART as $code => [$name, $type, $normal]) {
        Database::query(
            'INSERT INTO accounting_accounts (code, name, type, normal_balance) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), normal_balance = VALUES(normal_balance)',
            [$code, $name, $type, $normal],
        );
    }
    foreach (\App\Services\Settings::DEFAULTS as $key => $value) {
        Database::query('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
    }

    Database::query("INSERT IGNORE INTO branches (code, name) VALUES ('HO', 'Head Office')");
    $headOffice = (int) Database::value("SELECT id FROM branches WHERE code = 'HO'");

    $createUser = function (string $name, string $email, string $role, ?int $branchId) use ($password): void {
        if (Database::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            echo "  exists   {$email}\n";
            return;
        }
        $id = Database::insert('users', [
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'branch_id' => $branchId,
        ]);
        Database::query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE role_key = ?', [$id, $role]);
        echo "  created  {$email} ({$role})\n";
    };

    echo "Users:\n";
    $superAdminSql = "SELECT MIN(ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_key = 'super_admin'";
    if (Database::value($superAdminSql) === null) {
        $createUser(($seedOwner['name'] ?? '') ?: (getenv('SEED_ADMIN_NAME') ?: 'Super Admin'), ($seedOwner['email'] ?? '') ?: (getenv('SEED_ADMIN_EMAIL') ?: 'admin@finserve.local'), 'super_admin', $headOffice);
    } else {
        echo "  exists   Super Admin\n";
    }

    if ($demo) {
        Database::query("INSERT IGNORE INTO branches (code, name) VALUES ('BR01', 'City Branch')");
        $branch = (int) Database::value("SELECT id FROM branches WHERE code = 'BR01'");
        $createUser('Branch Manager', 'manager@finserve.local', 'branch_manager', $branch);
        $createUser('Ravi Agent', 'agent1@finserve.local', 'collection_agent', $branch);
        $createUser('Sita Agent', 'agent2@finserve.local', 'collection_agent', $branch);
        $createUser('Lata Loan Officer', 'officer@finserve.local', 'loan_officer', $branch);
        $createUser('Sunil Supervisor', 'supervisor@finserve.local', 'collection_supervisor', $branch);
        $createUser('Farah Finance', 'finance@finserve.local', 'finance', $headOffice);
        $createUser('Arjun Auditor', 'auditor@finserve.local', 'auditor', $headOffice);
    }

    $adminId = (int) Database::value($superAdminSql);

    $seedProducts = function (string $table, string $versionTable, array $products, callable $validate) use ($adminId): void {
        foreach ($products as [$code, $name, $description, $terms]) {
            if (Database::value("SELECT id FROM {$table} WHERE code = ?", [$code]) !== null) {
                echo "  exists   {$code}\n";
                continue;
            }
            $productId = Database::insert($table, [
                'code' => $code, 'name' => $name, 'description' => $description, 'created_by' => $adminId,
            ]);
            $versionId = Database::insert($versionTable, [
                'product_id' => $productId,
                'version' => 1,
                'terms_json' => json_encode($validate($terms)),
                'effective_from' => gmdate('Y-m-d H:i:s'),
                'change_reason' => 'Initial terms (seed)',
                'created_by' => $adminId,
            ]);
            Database::query("UPDATE {$table} SET current_version_id = ? WHERE id = ?", [$versionId, $productId]);
            echo "  created  {$code}\n";
        }
    };

    echo "Loan products:\n";
    $seedProducts('loan_products', 'loan_product_versions', [
        ['PL-MONTHLY', 'Personal Loan (Monthly EMI)', 'Reducing-balance EMI loan repaid monthly.', LoanTerms::defaults()],
        ['DCL-DAILY', 'Daily Collection Loan', 'Flat-rate loan collected daily by field agents.', array_merge(LoanTerms::defaults(), [
            'interest_method' => 'flat',
            'annual_interest_rate' => '24.0000',
            'repayment_frequency' => 'daily',
            'min_amount' => '5000.0000',
            'max_amount' => '100000.0000',
            'min_tenure' => 50,
            'max_tenure' => 300,
            'processing_fee_percent' => '1.0000',
            'late_fee_value' => '20.0000',
            'grace_period_days' => 1,
        ])],
        ['WL-WEEKLY', 'Weekly Loan', 'Flat-rate loan collected once a week by field agents.', array_merge(LoanTerms::defaults(), [
            'interest_method' => 'flat',
            'annual_interest_rate' => '22.0000',
            'repayment_frequency' => 'weekly',
            'min_amount' => '10000.0000',
            'max_amount' => '200000.0000',
            'min_tenure' => 12,
            'max_tenure' => 104,
            'processing_fee_percent' => '1.0000',
            'late_fee_value' => '100.0000',
            'grace_period_days' => 2,
        ])],
        ['YL-YEARLY', 'Yearly Loan (Annual Installment)', 'Reducing-balance loan repaid once a year, e.g. after harvest.', array_merge(LoanTerms::defaults(), [
            'annual_interest_rate' => '14.0000',
            'repayment_frequency' => 'yearly',
            'min_amount' => '25000.0000',
            'max_amount' => '500000.0000',
            'min_tenure' => 1,
            'max_tenure' => 5,
            'late_fee_value' => '1000.0000',
            'grace_period_days' => 15,
        ])],
    ], [LoanTerms::class, 'validate']);

    if ($demo && (int) Database::value("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'opening_capital'") === 0) {
        // Demo funding so the books start solvent: owner's capital into the bank, and a cash float per branch.
        foreach (Ledger::CHART as $code => [$name, $type, $normal]) {
            Database::query('INSERT IGNORE INTO accounting_accounts (code, name, type, normal_balance) VALUES (?, ?, ?, ?)', [$code, $name, $type, $normal]);
        }
        Ledger::post(\App\Services\Settings::today(), $headOffice, 'opening_capital', null, "Owner's capital introduced (demo)", [
            [Ledger::BANK, '5000000.00', '0'], [Ledger::CAPITAL, '0', '5000000.00'],
        ], $adminId);
        foreach (Database::all('SELECT id FROM branches') as $b) {
            Ledger::post(\App\Services\Settings::today(), (int) $b['id'], 'opening_capital', null, 'Branch cash float (demo)', [
                [Ledger::BRANCH_CASH, '200000.00', '0'], [Ledger::BANK, '0', '200000.00'],
            ], $adminId);
        }
        echo "Demo funding: Rs 50,00,000 capital; Rs 2,00,000 cash float per branch\n";
    }

    echo "Deposit products (inactive until compliance approval):\n";
    $seedProducts('deposit_products', 'deposit_product_versions', [
        ['RD-DAILY', 'Daily Savings Deposit', 'Recurring deposit collected daily by agents.', DepositTerms::defaults()],
        ['RD-WEEKLY', 'Weekly Savings Deposit', 'Recurring deposit collected once a week.', array_merge(DepositTerms::defaults(), [
            'contribution_frequency' => 'weekly',
            'min_installment' => '200.0000',
            'annual_interest_rate' => '6.5000',
        ])],
        ['RD-MONTHLY', 'Monthly Savings Deposit', 'Recurring deposit paid once a month.', array_merge(DepositTerms::defaults(), [
            'contribution_frequency' => 'monthly',
            'min_installment' => '500.0000',
            'annual_interest_rate' => '7.0000',
            'tenure_options' => [['value' => 1, 'unit' => 'years'], ['value' => 2, 'unit' => 'years'], ['value' => 3, 'unit' => 'years']],
        ])],
        ['FD-12M', 'Fixed Deposit', 'Lump-sum fixed deposit for the tenure you choose.', array_merge(DepositTerms::defaults(), [
            'scheme' => 'fixed',
            'annual_interest_rate' => '7.0000',
            'lock_in_days' => 7,
            'tenure_options' => [['value' => 45, 'unit' => 'days'], ['value' => 3, 'unit' => 'months'], ['value' => 6, 'unit' => 'months'], ['value' => 1, 'unit' => 'years'], ['value' => 2, 'unit' => 'years']],
        ])],
    ], [DepositTerms::class, 'validate']);
});

echo "\nPassword for newly created users: {$password}\n";
echo "Change it after first login.\n";
