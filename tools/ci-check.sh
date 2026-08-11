#!/bin/bash
# Local approximation of this repo's CI checks (PHP-CS-Fixer + PHPStan).
# Run manually with `tools/ci-check.sh`, or it's invoked automatically by
# the pre-push git hook (.git/hooks/pre-push, not tracked in the repo).
#
# PHP-CS-Fixer runs on the host (needs only PHP + the phar, no GLPI
# runtime). PHPStan needs real GLPI core classes/constants to resolve
# type info, so it runs inside the testing dev container, using a
# generated config with absolute container paths (the plugin's own
# tools/phpstan/phpstan.neon ships with stale bootstrap paths for GLPI 11
# — see ARCHITECTURE-adjacent skill notes — and the root phpstan.neon's
# relative ../../stubs path assumes a CI-only stub checkout this dev
# container doesn't have).

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." >/dev/null 2>&1 && pwd)"
CACHE_DIR="$HOME/.cache/domainmanager-ci"
CS_FIXER_PHAR="$CACHE_DIR/php-cs-fixer.phar"
CONTAINER="testing_glpi_1"
PLUGIN_IN_CONTAINER="/var/www/glpi/plugins/domainmanager"
PHPSTAN_CONFIG_NAME="local-ci-parity.neon"

mkdir -p "$CACHE_DIR"

echo -e "\033[0;33m==> PHP-CS-Fixer\033[0m"
if [ ! -f "$CS_FIXER_PHAR" ]; then
    echo "Downloading php-cs-fixer.phar to $CS_FIXER_PHAR ..."
    curl -fsSL -o "$CS_FIXER_PHAR" https://cs.symfony.com/download/php-cs-fixer-v3.phar
fi
cd "$REPO_DIR"
php "$CS_FIXER_PHAR" fix --config=.php-cs-fixer.php --dry-run --diff

echo
echo -e "\033[0;33m==> PHPStan (via testing dev container)\033[0m"
if ! command -v podman >/dev/null 2>&1 || ! podman ps --format '{{.Names}}' 2>/dev/null | grep -qx "$CONTAINER"; then
    echo "testing dev container not running — skipping PHPStan (start it with:"
    echo "  cd ~/containers/testing && podman compose -f compose-glpi-65108-persistent.yml up -d)"
else
    cat > "$REPO_DIR/tools/phpstan/$PHPSTAN_CONFIG_NAME" <<EOF
parameters:
    parallel:
        maximumNumberOfProcesses: 2
    level: 5
    bootstrapFiles:
        - $PLUGIN_IN_CONTAINER/tools/phpstan/stubs/glpi_constants.php
        - /var/www/glpi/vendor/autoload.php
        - /var/www/glpi/src/autoload/constants.php
    paths:
        - $PLUGIN_IN_CONTAINER/src
        - $PLUGIN_IN_CONTAINER/hook.php
        - $PLUGIN_IN_CONTAINER/setup.php
        - $PLUGIN_IN_CONTAINER/tools/phpstan/rules
    excludePaths:
        - '**/vendor/*'
        - '**/tests/*'
        - '**/tools/*'
    scanDirectories:
        - /var/www/glpi/src
        - $PLUGIN_IN_CONTAINER/tools/phpstan/rules
    stubFiles:
        - $PLUGIN_IN_CONTAINER/tools/phpstan/stubs/glpi_constants.php
    ignoreErrors:
        - '/(Instantiated class|Class) (GlpiPlugin(\\\\\w+)+|Plugin\w+) not found/'
        - '/Call to (static )?method \w+\(\) on an unknown class (GlpiPlugin(\\\\\w+)+|Plugin\w+)/'
        - '/Access to (property \\\$\w+|constant \w+) on an unknown class (GlpiPlugin(\\\\\w+)+|Plugin\w+)/'
        - '/PHPDoc tag \@var for variable \\\$\w+ contains unknown class (GlpiPlugin(\\\\\w+)+|Plugin\w+)/'
        - '/Static method (GlpiPlugin(\\\\\w+)+|Plugin\w+).* is unused/'
        -
            message: '/The class in Config\.php or config\.class\.php must have the \\\$rightname attribute set to "config"\./'
            path: $PLUGIN_IN_CONTAINER/src/SupplierConfig.php
        -
            message: '/Strict comparison using === between string and null will always evaluate to false\./'
            path: $PLUGIN_IN_CONTAINER/src/SupplierConfig.php
    reportUnmatchedIgnoredErrors: false
rules:
    - GlpiProject\Tools\PHPStan\Rules\GlobalVarTypeRule
services:
    - class: CustomPHPStanRules\HardcodedPasswordRule
      tags: [phpstan.rules.rule]
    - class: CustomPHPStanRules\UnsafeFunctionsRule
      tags: [phpstan.rules.rule]
    - class: CustomPHPStanRules\InputValidationRule
      tags: [phpstan.rules.rule]
    - class: CustomPHPStanRules\SensitiveInfoExposureRule
      tags: [phpstan.rules.rule]
    - class: CustomPHPStanRules\SqlInjectionRule
      tags: [phpstan.rules.rule]
    - class: CustomPHPStanRules\PermissionControlRule
      tags: [phpstan.rules.rule]
    - class: CustomPHPStanRules\UserSessionCheck
      tags: [phpstan.rules.rule]
    - class: CustomPHPStanRules\DebugLogsRule
      tags: [phpstan.rules.rule]
EOF
    podman exec "$CONTAINER" sh -c "cd $PLUGIN_IN_CONTAINER && php -d memory_limit=2G tools/phpstan/phpstan.phar analyse -c tools/phpstan/$PHPSTAN_CONFIG_NAME --no-progress"
fi

echo
echo -e "\033[0;32mAll local CI checks passed.\033[0m"
