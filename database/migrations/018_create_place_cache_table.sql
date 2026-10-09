-- Place search and reverse lookups, kept for PLACES_CACHE_TTL_MINUTES (5 by default) so an
-- identical search does not hit the geocoder again. Per user, like route_cache. cache_key is
-- a SHA-256 of the provider and the normalised request. uniq_user_key also serves as the
-- user_id index.
CREATE TABLE IF NOT EXISTS place_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    cache_key CHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_key (user_id, cache_key),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
