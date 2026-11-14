-- Where a customer lives / is collected from, for the agents' map. Set by an agent on the customer's page,
-- or automatically from the first collection or visit recorded there with the phone's GPS.
ALTER TABLE customers
    ADD COLUMN latitude DECIMAL(9,6) NULL AFTER monthly_income,
    ADD COLUMN longitude DECIMAL(9,6) NULL AFTER latitude,
    ADD COLUMN location_accuracy_m INT UNSIGNED NULL AFTER longitude,
    ADD COLUMN location_set_at DATETIME NULL AFTER location_accuracy_m,
    ADD COLUMN location_set_by BIGINT UNSIGNED NULL AFTER location_set_at,
    ADD CONSTRAINT fk_customers_location_set_by FOREIGN KEY (location_set_by) REFERENCES users(id);
