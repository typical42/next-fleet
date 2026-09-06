<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# End-to-end tests

Playwright, one project per supported Nextcloud major, against the dev stack in `.docker/`. The
stack has to be up and `js/` built. See [development](../../docs/development.md#local-dev-environment)
for the two commands and for what to run where Chromium's system libraries are missing.

`demo-fleet.spec.js` reads the fleet `occ nextfleet:seed admin` writes and writes nothing itself, so
seed both instances first. The other specs make their own vehicles, all of them plated `E2E-`, and
delete what they find under that prefix before they start — the demo fleet's `NF-` survives that, so
the two share an instance.
