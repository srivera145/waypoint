-- Routing results for an identical request, kept for ROUTING_CACHE_TTL_MINUTES (10 by default)
-- so a repeat does not hit the routing engine. cache_key is a SHA-256 of the provider and the
-- request. uniq_user_key also serves as the user_id index.
CREATE TABLE IF NOT EXISTS route_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    cache_key CHAR(64) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_key (user_id, cache_key),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
