-- How a user reads and types dimensions. Storage is always metric; this only changes display
-- and the unit a bare number is read in.
ALTER TABLE users ADD COLUMN unit_system ENUM('imperial', 'metric') NOT NULL DEFAULT 'imperial';
