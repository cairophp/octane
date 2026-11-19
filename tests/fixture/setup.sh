#!/usr/bin/env bash
# setup.sh DIR: a fresh Laravel app in DIR with this package and the test
# routes, for tests/ServerTest.php (CAIRO_OCTANE_TEST_APP=DIR).
set -euo pipefail
dir=$1
pkg=$(cd "$(dirname "$0")/../.." && pwd)
composer create-project laravel/laravel "$dir" --no-interaction --quiet
cd "$dir"
composer config repositories.cairo-octane "{\"type\":\"path\",\"url\":\"$pkg\",\"options\":{\"symlink\":true}}"
composer require laravel/octane cairophp/octane:@dev --no-interaction --quiet
printf 'User-agent: *\n' > public/robots.txt
printf "\nrequire '%s/tests/fixture/routes.php';\n" "$pkg" >> routes/web.php
