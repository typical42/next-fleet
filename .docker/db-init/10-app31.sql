-- SPDX-FileCopyrightText: 2026 Johannes Kolb
-- SPDX-License-Identifier: AGPL-3.0-or-later

-- The mariadb image creates the database named by MARIADB_DATABASE and no other, so the
-- second Nextcloud major gets its schema here. Two majors cannot share one install.
CREATE DATABASE IF NOT EXISTS nextcloud31
	CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON nextcloud31.* TO 'nextcloud'@'%';
