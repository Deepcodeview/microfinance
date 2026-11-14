-- Which savings installments a contribution paid for (oldest unpaid first), e.g. a catch-up of 4 missed
-- days covers 26 Oct – 29 Oct. Shown on the receipt and the account's statement.
ALTER TABLE payments
    ADD COLUMN covers_from DATE NULL AFTER business_date,
    ADD COLUMN covers_to DATE NULL AFTER covers_from,
    ADD COLUMN covers_count SMALLINT UNSIGNED NULL AFTER covers_to;
