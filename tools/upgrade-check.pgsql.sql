-- SPDX-FileCopyrightText: 2026 Johannes Kolb
-- SPDX-License-Identifier: AGPL-3.0-or-later

-- Given a role that may create roles, Nextcloud makes one of its own per install, and the two
-- majors installing at once race for the same name. An ordinary role that owns a database per
-- major is used as given.
CREATE ROLE nextcloud LOGIN PASSWORD 'nextcloud';
CREATE DATABASE nextcloud OWNER nextcloud;
CREATE DATABASE nextcloud31 OWNER nextcloud;
