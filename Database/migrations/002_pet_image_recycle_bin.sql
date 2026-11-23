-- One-time upgrade for an existing pet_grooming_db created from schema.sql or migration 001.
-- Adds a per-pet image column and soft-delete (recycle bin) columns to the pets table.
-- Existing pets keep NULL in the new columns and remain visible in the pet table.
USE pet_grooming_db;

ALTER TABLE pets
    ADD COLUMN pet_image VARCHAR(255) NULL COMMENT 'Path to the uploaded image showing the pet issue.',
    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL COMMENT 'Soft-delete timestamp; NULL means the pet is active.',
    ADD COLUMN deleted_by INT NULL COMMENT 'User who soft-deleted the pet.',
    ADD KEY idx_pets_deleted_at (deleted_at),
    ADD CONSTRAINT fk_pets_deleted_by FOREIGN KEY (deleted_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE;
