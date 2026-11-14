-- UPI QR collection: proof-of-payment screenshot and the QR's transaction reference on each payment.
ALTER TABLE payments
    ADD COLUMN upi_tr VARCHAR(35) NULL AFTER reference,
    ADD COLUMN proof_stored_name CHAR(64) NULL AFTER upi_tr,
    ADD COLUMN proof_mime VARCHAR(50) NULL AFTER proof_stored_name,
    ADD COLUMN proof_sha256 CHAR(64) NULL AFTER proof_mime;
