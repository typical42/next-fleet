<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# End-to-end tests

Playwright, one project per supported Nextcloud major, against the dev stack in `.docker/`. The
stack has to be up and `js/` built. See [development](../../docs/development.md#local-dev-environment)
for the two commands and for what to run where Chromium's system libraries are missing.

`demo-fleet.spec.js` reads the fleet `occ nextfleet:seed admin` writes and writes nothing itself, so
seed both instances first. The other specs make their own vehicles under a plate prefix each of them
owns — `E2E-` for `m1-slice.spec.js`, `M2-E2E-` for `m2-slice.spec.js` — and delete what they find
under it before they start. The prefixes are disjoint on purpose: the files run at once. The demo
fleet's `NF-` survives all of them, so they share an instance.

`app.js` holds what more than one file needs: signing in, the app's API from inside the page, and
the few locators every file reaches for.
