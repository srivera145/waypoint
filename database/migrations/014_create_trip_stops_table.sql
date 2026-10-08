-- Ordered stops on a trip. Ownership comes from the trip, so every query joins trips on
-- user_id. idx_trip_position is not unique: reordering stops would otherwise need a
-- temporary position to step around the collision.
CREATE TABLE IF NOT EXISTS trip_stops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trip_id INT NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    address VARCHAR(500) NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    place_ref VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    INDEX idx_trip_position (trip_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
