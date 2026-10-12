#!/usr/bin/env bash
#
# Local CI for BlockCraft. GitHub Actions workflows are not run for this
# repo; this script runs the same two jobs as .github/workflows/tests.yml on
# the maintainer's machine, and its summary goes into the pull request.
#
# Job 1, "TypeScript & Vite build":
#   a fresh clone of HEAD (like actions/checkout), npm ci, typecheck, Vitest,
#   build, then fails if the committed assets/dist differs from the fresh build.
# Job 2, "PHPUnit (against a real October CMS install)":
#   a fresh October CMS 4.x install, BlockCraft installed from `git archive HEAD`
#   (exactly what Packagist ships, honouring .gitattributes export-ignore),
#   SQLite, migrations, then the PHPUnit suite.
#
# Only committed code is tested (HEAD). Commit first.
#
# Usage:   scripts/ci-local.sh
# Options: PHP=/path/to/php  COMPOSER=/path/to/composer  CI_WORKDIR=/some/dir  KEEP=1
#          JOB=ts|phpunit (run one job only)
#
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-$(command -v composer)}"
WORK="${CI_WORKDIR:-$(mktemp -d -t blockcraft-ci)}"
JOB="${JOB:-all}"

step() { printf '\n== %s\n' "$*"; }

cleanup() {
    if [ "${KEEP:-0}" != "1" ] && [ -z "${CI_WORKDIR:-}" ]; then
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

step "Repository state"
if [ -n "$(git -C "$REPO" status --porcelain)" ]; then
    echo "Warning: uncommitted changes are not tested (HEAD only)."
fi
git -C "$REPO" log -1 --format='Testing %h %s'
echo "node $(node --version), npm $(npm --version), $("$PHP" -r 'echo "PHP ", PHP_VERSION;'), $("$PHP" "$COMPOSER" --version 2>/dev/null | head -1)"

if [ "$JOB" = "all" ] || [ "$JOB" = "ts" ]; then
    step "Job: TypeScript & Vite build"
    CHECKOUT="$WORK/checkout"
    git clone --quiet --no-hardlinks "$REPO" "$CHECKOUT"
    git -C "$CHECKOUT" checkout --quiet --detach "$(git -C "$REPO" rev-parse HEAD)"
    (
        cd "$CHECKOUT"
        npm ci --no-audit --no-fund
        npm run typecheck
        npm test
        npm run build
    )

    # assets/dist is committed because installs have no build step. A stale
    # or missing build would ship broken, so the committed copy must match.
    step "Committed assets/dist matches a fresh build"
    git -C "$CHECKOUT" status --porcelain -- assets/dist
    test -z "$(git -C "$CHECKOUT" status --porcelain -- assets/dist)" \
        || { echo "FAIL: committed assets/dist is stale; run npm run build and commit it"; exit 1; }
    echo "assets/dist is up to date"
fi

if [ "$JOB" = "all" ] || [ "$JOB" = "phpunit" ]; then
    step "Job: PHPUnit (against a real October CMS install)"
    APP="$WORK/octoberapp"

    step "Fresh October CMS install in $APP"
    # --no-scripts: October's post-create script (key:generate) needs vendor/,
    # which isn't there yet. No pipes on install steps: a pipeline's exit status
    # is its last command's, which hid a failed install before (PLATFORM-RULES §15).
    "$PHP" "$COMPOSER" create-project october/october "$APP" --no-install --no-scripts --no-interaction --quiet
    # PLATFORM-RULES §15: October opts out of Composer's advisory block in the
    # newer "policy" syntax, which Composer 2.9 ignores. Add the 2.9-era setting too.
    "$PHP" -r '
        $file = $argv[1];
        $json = json_decode(file_get_contents($file), true);
        $json["config"]["audit"]["block-insecure"] = false;
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    ' "$APP/composer.json"
    (cd "$APP" && "$PHP" "$COMPOSER" install --no-interaction --quiet)
    test -f "$APP/vendor/autoload.php" || { echo "FAIL: composer install left no vendor/autoload.php"; exit 1; }
    cp "$APP/.env.example" "$APP/.env"
    "$PHP" "$APP/artisan" key:generate --no-interaction
    "$PHP" "$APP/artisan" --version

    # Install from `git archive`, not the checkout: it honours .gitattributes
    # export-ignore, so this is exactly what Packagist ships to customers.
    # Only tests/ (export-ignored) is copied in separately to run them.
    step "Install BlockCraft from git archive HEAD"
    PLUGIN="$APP/plugins/amjadiqbal/blockcraft"
    mkdir -p "$PLUGIN"
    git -C "$REPO" archive HEAD | tar -x -C "$PLUGIN"
    test -f "$PLUGIN/assets/dist/manifest.json" || { echo "FAIL: shipped archive has no assets/dist/manifest.json"; exit 1; }
    test ! -e "$PLUGIN/tests" || { echo "FAIL: tests/ is shipping; check .gitattributes export-ignore"; exit 1; }
    cp -R "$REPO/tests" "$PLUGIN/tests"

    step "Configure SQLite and migrate"
    cd "$APP"
    mkdir -p storage
    touch storage/database.sqlite
    sed -i.bak 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
    sed -i.bak "s#^DB_DATABASE=.*#DB_DATABASE=$APP/storage/database.sqlite#" .env
    rm -f .env.bak
    "$PHP" artisan october:migrate --no-interaction
    "$PHP" artisan plugin:list > "$WORK/plugin-list.txt"
    grep -q "AmjadIqbal.BlockCraft" "$WORK/plugin-list.txt" || { echo "FAIL: AmjadIqbal.BlockCraft not registered"; exit 1; }

    step "Run BlockCraft's PHPUnit suite"
    cat > phpunit.blockcraft.xml <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="modules/system/tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="BlockCraft">
            <directory>./plugins/amjadiqbal/blockcraft/tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing" />
        <env name="CACHE_STORE" value="array" />
        <env name="SESSION_DRIVER" value="array" />
        <env name="ENABLE_CSRF" value="false" />
        <env name="DB_CONNECTION" value="sqlite" />
        <env name="DB_DATABASE" value=":memory:" />
    </php>
</phpunit>
EOF
    "$PHP" vendor/bin/phpunit --configuration phpunit.blockcraft.xml
fi

step "PASS"
