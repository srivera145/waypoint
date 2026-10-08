-- Routing preferences. The row with vehicle_id NULL is the user's default; a row with a
-- vehicle_id overrides it for that vehicle. uniq_user_vehicle also serves as the user_id index.
-- MySQL lets a unique key hold many NULLs, so RoutePreference upserts the default row under a
-- lock on the user rather than relying on this key.
CREATE TABLE IF NOT EXISTS route_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    vehicle_id INT NULL,
    personality ENUM('fastest', 'economical', 'scenic', 'easy', 'flat', 'rv') NOT NULL DEFAULT 'fastest',
    avoid_tolls TINYINT(1) NOT NULL DEFAULT 0,
    avoid_highways TINYINT(1) NOT NULL DEFAULT 0,
    avoid_ferries TINYINT(1) NOT NULL DEFAULT 0,
    avoid_mountains TINYINT(1) NOT NULL DEFAULT 0,
    max_grade_pct DECIMAL(4,1) NULL,
    no_driving_after TIME NULL,
    stop_interval_min SMALLINT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_vehicle (user_id, vehicle_id),
    INDEX idx_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
