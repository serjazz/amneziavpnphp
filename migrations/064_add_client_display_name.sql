-- Human-readable label in panel; technical identifier stays in `name` (e.g. tg_…)

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vpn_clients' AND COLUMN_NAME = 'display_name');
SET @sql := IF(@exist = 0,
  'ALTER TABLE vpn_clients ADD COLUMN display_name VARCHAR(255) NULL DEFAULT NULL COMMENT ''Optional label for UI; connection id remains in name'' AFTER name',
  'SELECT "Column display_name exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO translations (`locale`, `category`, `key_name`, `translation`) VALUES
('en', 'clients', 'display_name', 'Display name'),
('ru', 'clients', 'display_name', 'Отображаемое имя'),
('es', 'clients', 'display_name', 'Nombre visible'),
('de', 'clients', 'display_name', 'Anzeigename'),
('fr', 'clients', 'display_name', 'Nom affiché'),
('zh', 'clients', 'display_name', '显示名称'),
('en', 'clients', 'technical_login', 'Connection ID'),
('ru', 'clients', 'technical_login', 'Идентификатор (логин)'),
('es', 'clients', 'technical_login', 'ID de conexión'),
('de', 'clients', 'technical_login', 'Verbindungs-ID'),
('fr', 'clients', 'technical_login', 'Identifiant'),
('zh', 'clients', 'technical_login', '连接标识'),
('en', 'clients', 'display_name_hint', 'Arbitrary label for your reference. Does not change VPN keys.'),
('ru', 'clients', 'display_name_hint', 'Произвольная подпись для удобства. Не меняет ключи и имя подключения в VPN.'),
('es', 'clients', 'display_name_hint', 'Etiqueta opcional. No cambia las claves VPN.'),
('de', 'clients', 'display_name_hint', 'Frei wählbar; ändert keine VPN-Schlüssel.'),
('fr', 'clients', 'display_name_hint', 'Libellé facultatif ; ne modifie pas les clés VPN.'),
('zh', 'clients', 'display_name_hint', '可选备注，不会改变 VPN 密钥。'),
('en', 'clients', 'display_name_saved', 'Display name saved'),
('ru', 'clients', 'display_name_saved', 'Имя сохранено'),
('es', 'clients', 'display_name_saved', 'Nombre guardado'),
('de', 'clients', 'display_name_saved', 'Gespeichert'),
('fr', 'clients', 'display_name_saved', 'Enregistré'),
('zh', 'clients', 'display_name_saved', '已保存')
ON DUPLICATE KEY UPDATE `translation` = VALUES(`translation`);
