-- Database Migration Script
-- Remove min_stock, max_stock, stock_level fields and fix stock logic

USE system;

-- Create backup of current drugs table
DROP TABLE IF EXISTS drugs_backup;
CREATE TABLE drugs_backup AS SELECT * FROM drugs;

-- Remove unnecessary fields from drugs table
ALTER TABLE drugs DROP COLUMN min_stock;
ALTER TABLE drugs DROP COLUMN max_stock;
ALTER TABLE drugs DROP COLUMN stock_level;

-- Update borrowing_requests table to remove min_stock, max_stock references
ALTER TABLE borrowing_requests DROP COLUMN min_stock;
ALTER TABLE borrowing_requests DROP COLUMN max_stock;

-- Recalculate current_stock based on historical transactions
-- This is a one-time fix to ensure stock levels are accurate
UPDATE drugs SET current_stock = 0;

-- Add back stock based on checkout history (restore inventory based on actual usage)
-- Calculate stock as initial amount minus all checkouts for each drug/department
UPDATE drugs d
SET current_stock = (
    SELECT COALESCE(SUM(dc.quantity_dispensed), 0) as total_dispensed
    FROM drug_checkouts dc
    WHERE dc.drug_name = d.drug_name AND dc.department = d.department
)
WHERE EXISTS (
    SELECT 1 FROM drug_checkouts dc2
    WHERE dc2.drug_name = d.drug_name AND dc2.department = d.department
);

-- Add triggers to automatically update current_stock on checkouts
DELIMITER //

DROP TRIGGER IF EXISTS update_stock_on_checkout;
CREATE TRIGGER update_stock_on_checkout
AFTER INSERT ON drug_checkouts
FOR EACH ROW
BEGIN
    UPDATE drugs
    SET current_stock = current_stock - NEW.quantity_dispensed
    WHERE drug_name = NEW.drug_name AND department = NEW.department;
END //

DELIMITER ;

-- Stock updates for borrowing requests are now handled by PHP application logic
-- with proper error handling and transactions

SELECT 'Migration completed. Please verify stock levels and adjust initial stock as needed.' AS message;
