#!/bin/sh
# SPDX-FileCopyrightText: 2026 Johannes Kolb
# SPDX-License-Identifier: AGPL-3.0-or-later

# Renders every Mermaid block in docs/**/*.md and fails on the first that does not render, so a
# diagram GitHub would show as an error never reaches main (docs/design/README.md).
#
#   tools/check-diagrams.sh [<file.md> ...]
#
# A file is named relative to the repository's root; none means every Markdown file under docs/
# that holds a block. The renderer is the pinned mermaid-cli image, run in Docker; what it draws
# is thrown away with the container.

set -eu

image=minlag/mermaid-cli:11.4.2

cd "$(dirname "$0")/.."

if [ $# -eq 0 ]; then
	mkdir -p build
	# No pipe: sh has no pipefail. grep exits 1 when it finds nothing, which the check below
	# reports; anything worse has printed its own error by then.
	grep -rl --include='*.md' '^```mermaid' docs >build/diagram-files || true
	sort -o build/diagram-files build/diagram-files
	# Word splitting is the point: the list is one path per line, and no path under docs/ has a
	# space.
	# shellcheck disable=SC2046
	set -- $(cat build/diagram-files)
fi
if [ $# -eq 0 ]; then
	echo "no Mermaid block under docs/" >&2
	exit 1
fi

for file in "$@"; do
	echo "$file"
	docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/data "$image" \
		-q -i "/data/$file" -o /tmp/out.md
done
