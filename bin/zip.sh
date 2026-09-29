#!/usr/bin/env bash
# Builds dist/videooptimizer.zip from the working tree, honouring .distignore.
# Expects a production build in build/ (just zip runs `npm run build` first).
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f build/frontend/index.js ] || { echo "build/ is missing – run: just build" >&2; exit 1; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/videooptimizer" dist

excludes=()
while IFS= read -r line; do
	[[ -z "$line" || "$line" == \#* ]] && continue
	if [[ "$line" == /* ]]; then
		excludes+=( "--exclude=./${line#/}" )
	else
		excludes+=( "--exclude=${line}" )
	fi
done < .distignore

tar -cf - "${excludes[@]}" . | tar -xf - -C "$tmp/videooptimizer"
rm -f dist/videooptimizer.zip
( cd "$tmp" && zip -qr9 videooptimizer.zip videooptimizer )
mv "$tmp/videooptimizer.zip" dist/videooptimizer.zip
echo "dist/videooptimizer.zip ($(du -h dist/videooptimizer.zip | cut -f1))"
