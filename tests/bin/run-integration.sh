#!/usr/bin/env bash
# Runs the integration suite inside the "woo" container (WordPress + WooCommerce installed) against
# the separate wp_tests database. The WordPress test suite resets that database on every run.
set -euo pipefail
cd "$(dirname "$0")/../.."

export VIDEOOPTIMIZER_INTEGRATION=1
export WP_PHPUNIT__TESTS_CONFIG="$(pwd)/tests/wp-tests-config.php"

exec vendor/bin/phpunit --testsuite integration "$@"
