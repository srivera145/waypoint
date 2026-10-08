-- A user's saved vehicles. Dimensions are stored metric (metres, kilograms) and are NULL
-- where the type has none (bicycle, walking) or the field does not apply to the type.
-- Exactly one row per user has is_active = 1; VehicleService keeps that true in a transaction.
CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('car', 'suv', 'pickup', 'rv', 'truck', 'truck_trailer', 'motorcycle', 'bicycle', 'walking') NOT NULL DEFAULT 'car',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    length_m DECIMAL(6,3) NULL,
    width_m DECIMAL(5,3) NULL,
    height_m DECIMAL(5,3) NULL,
    weight_kg DECIMAL(8,2) NULL,
    axle_count TINYINT UNSIGNED NULL,
    axle_load_kg DECIMAL(8,2) NULL,
    has_trailer TINYINT(1) NOT NULL DEFAULT 0,
    trailer_length_m DECIMAL(6,3) NULL,
    trailer_weight_kg DECIMAL(8,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
