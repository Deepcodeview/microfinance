-- Accounts brought in from before KBSAVE (manual registers): started on a past date, with what was
-- already collected. They enter the books through one opening-balance entry, not as cash.

ALTER TABLE loans
    ADD COLUMN migrated_at DATETIME NULL AFTER disbursed_in_field,
    ADD COLUMN opening_collected DECIMAL(19,4) NULL AFTER migrated_at;

ALTER TABLE deposit_accounts
    ADD COLUMN migrated_at DATETIME NULL AFTER tenure_unit,
    ADD COLUMN opening_balance DECIMAL(19,4) NULL AFTER migrated_at;
