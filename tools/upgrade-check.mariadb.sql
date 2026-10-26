-- SPDX-FileCopyrightText: 2026 Johannes Kolb
-- SPDX-License-Identifier: AGPL-3.0-or-later

-- The schemas tools/upgrade-check.compose.yml needs beyond the dev stack's two
-- (.docker/db-init/10-app31.sql): NC 35 fresh, and the install NC 34 sets up and NC 35 upgrades.
CREATE DATABASE IF NOT EXISTS nextcloud35 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE DATABASE IF NOT EXISTS nextcloud34to35 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON nextcloud35.* TO 'nextcloud'@'%';
GRANT ALL PRIVILEGES ON nextcloud34to35.* TO 'nextcloud'@'%';
