#!/usr/bin/env bash
# Regenerates languages/videooptimizer.pot, merges it into the bundled translations and compiles
# .mo (PHP) + .json (JS) files. Runs inside the WordPress container (needs WP-CLI).
set -euo pipefail
cd "$(dirname "$0")/.."

wp i18n make-pot . languages/videooptimizer.pot \
	--slug=videooptimizer --domain=videooptimizer \
	--exclude=vendor,node_modules,build,dist,tests,docker,bin \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/ScaleCommerce/videooptimizer-wordpress/issues"}'

# The JS strings live in the built bundles; make-pot reads them from assets/src (source maps are
# not needed because the JSON files are keyed by the built script path via --use-map below).
for po in languages/*.po; do
	wp i18n update-po languages/videooptimizer.pot "$po"
done

wp i18n make-mo languages
rm -f languages/videooptimizer-*.json
wp i18n make-json languages --no-purge --use-map=languages/js-map.json
echo "Translations updated."
