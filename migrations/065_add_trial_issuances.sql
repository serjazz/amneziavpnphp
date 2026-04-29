-- Trial keys from public landing

CREATE TABLE IF NOT EXISTS trial_issuances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token VARCHAR(64) NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_trial_token (token),
  INDEX idx_trial_ip_created (ip_hash, created_at),
  INDEX idx_trial_client (client_id),
  CONSTRAINT fk_trial_client FOREIGN KEY (client_id) REFERENCES vpn_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
