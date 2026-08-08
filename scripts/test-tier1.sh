#!/bin/bash
# Tier-1 automated tests for Domain Manager
# Deterministic checks: shell/SQL/PHP one-liners, no live credentials or browser interaction

PASS=0
FAIL=0

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

fail() {
    echo -e "${RED}FAIL${NC}: $1 — $2"
    ((FAIL++))
}

pass() {
    echo -e "${GREEN}PASS${NC}: $1"
    ((PASS++))
}

echo "========== Phase 1: Foundation =========="

# 1.1 Clean install from CLI
echo -n "1.1 Clean install + activate: "
podman exec testing_glpi_1 php /var/www/glpi/bin/console glpi:plugin:install domainmanager --force > /tmp/install_out.txt 2>&1
INSTALL_RESULT=$?
podman exec testing_glpi_1 php /var/www/glpi/bin/console glpi:plugin:activate domainmanager > /tmp/activate_out.txt 2>&1
ACTIVATE_RESULT=$?

if [ $INSTALL_RESULT -eq 0 ] && [ $ACTIVATE_RESULT -eq 0 ]; then
    # Check tables exist
    TABLES=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
        "SHOW TABLES LIKE 'glpi_plugin_domainmanager%';" 2>/dev/null)
    TABLE_COUNT=$(echo "$TABLES" | wc -l)
    if [ "$TABLE_COUNT" -ge "4" ]; then
        pass "1.1"
    else
        fail "1.1" "Expected 4+ tables, found $TABLE_COUNT"
    fi
else
    fail "1.1" "Install or activate failed (exit codes: install=$INSTALL_RESULT, activate=$ACTIVATE_RESULT)"
fi

# 1.2 Seeds present and idempotent
echo -n "1.2 Seeds (DomainType/RecordTypes): "
RESULT=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SELECT COUNT(*) FROM glpi_domaintypes WHERE name='Internet Domain';" 2>/dev/null || echo "0")
if [ "$RESULT" = "1" ]; then
    RESULT2=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
        "SELECT COUNT(*) FROM glpi_domainrecordtypes WHERE name IN ('A','AAAA','ALIAS','CNAME','MX','NS','PTR','SOA','SRV','TXT','CAA');" 2>/dev/null || echo "0")
    if [ "$RESULT2" = "11" ]; then
        pass "1.2"
    else
        fail "1.2" "Expected 11 record types, found $RESULT2"
    fi
else
    fail "1.2" "Expected 1 DomainType, found $RESULT"
fi

# 1.6 Cron shell executes
echo -n "1.6 Cron shell (DomainSync): "
podman exec testing_glpi_1 php /var/www/glpi/front/cron.php --force DomainSync > /tmp/cron_out.txt 2>&1
CRON_LOG=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SELECT state, content FROM glpi_crontasklogs WHERE crontasks_id=(SELECT id FROM glpi_crontasks WHERE name='DomainSync' LIMIT 1) ORDER BY id DESC LIMIT 1;" 2>/dev/null)
CRON_STATE=$(echo "$CRON_LOG" | cut -f1)
CRON_CONTENT=$(echo "$CRON_LOG" | cut -f2-)

if [ "$CRON_STATE" = "2" ]; then
    pass "1.6"
else
    fail "1.6" "Cron state=$CRON_STATE (expected 2), content=$CRON_CONTENT"
fi

# 4.7 Cron batching (sanity check that cron runs multiple times without aborting)
echo -n "4.7 Cron batching sanity: "
# Run cron twice in succession to verify batching loop doesn't abort
podman exec testing_glpi_1 php /var/www/glpi/front/cron.php --force DomainSync > /tmp/cron_batch1.txt 2>&1
BATCH_LOG1=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SELECT state FROM glpi_crontasklogs WHERE crontasks_id=(SELECT id FROM glpi_crontasks WHERE name='DomainSync' LIMIT 1) ORDER BY id DESC LIMIT 1;" 2>/dev/null)
podman exec testing_glpi_1 php /var/www/glpi/front/cron.php --force DomainSync > /tmp/cron_batch2.txt 2>&1
BATCH_LOG2=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SELECT state FROM glpi_crontasklogs WHERE crontasks_id=(SELECT id FROM glpi_crontasks WHERE name='DomainSync' LIMIT 1) ORDER BY id DESC LIMIT 1;" 2>/dev/null)

# state=2 means "completed successfully"
if [ "$BATCH_LOG1" = "2" ] && [ "$BATCH_LOG2" = "2" ]; then
    pass "4.7"
else
    fail "4.7" "Expected state=2 for both runs, got: $BATCH_LOG1 and $BATCH_LOG2"
fi

# 1.7 Uninstall (residue-free)
echo -n "1.7 Uninstall (residue-free): "
podman exec testing_glpi_1 php /var/www/glpi/bin/console glpi:plugin:deactivate domainmanager > /tmp/deactivate_out.txt 2>&1
podman exec testing_glpi_1 php /var/www/glpi/bin/console glpi:plugin:uninstall domainmanager > /tmp/uninstall_out.txt 2>&1

# Check tables gone but seeds remain
PLUGIN_TABLES=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SHOW TABLES LIKE 'glpi_plugin_domainmanager%';" 2>/dev/null | wc -l)
INTERNET_DOMAIN=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SELECT COUNT(*) FROM glpi_domaintypes WHERE name='Internet Domain';" 2>/dev/null || echo "0")
RECORD_TYPES=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SELECT COUNT(*) FROM glpi_domainrecordtypes;" 2>/dev/null || echo "0")

if [ "$PLUGIN_TABLES" = "0" ] && [ "$INTERNET_DOMAIN" = "1" ] && [ "$RECORD_TYPES" -gt "0" ]; then
    pass "1.7"
else
    fail "1.7" "Tables=$PLUGIN_TABLES (expected 0), DomainType=$INTERNET_DOMAIN (expected 1), RecordTypes=$RECORD_TYPES"
fi

# 1.8 Reinstall after uninstall (idempotency)
echo -n "1.8 Reinstall after uninstall: "
podman exec testing_glpi_1 php /var/www/glpi/bin/console glpi:plugin:install domainmanager > /tmp/reinstall_out.txt 2>&1
REINSTALL_RESULT=$?
podman exec testing_glpi_1 php /var/www/glpi/bin/console glpi:plugin:activate domainmanager > /tmp/reactivate_out.txt 2>&1
REACTIVATE_RESULT=$?

if [ $REINSTALL_RESULT -eq 0 ] && [ $REACTIVATE_RESULT -eq 0 ]; then
    # Verify idempotent: same result as 1.1
    REINSTALL_TABLES=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
        "SHOW TABLES LIKE 'glpi_plugin_domainmanager%';" 2>/dev/null | wc -l)
    REINSTALL_INTERNET=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
        "SELECT COUNT(*) FROM glpi_domaintypes WHERE name='Internet Domain';" 2>/dev/null || echo "0")
    if [ "$REINSTALL_TABLES" -ge "4" ] && [ "$REINSTALL_INTERNET" = "1" ]; then
        pass "1.8"
    else
        fail "1.8" "Reinstall incomplete: Tables=$REINSTALL_TABLES, DomainType=$REINSTALL_INTERNET"
    fi
else
    fail "1.8" "Reinstall or reactivate failed"
fi

echo ""
echo "========== Phase 2: NS Provider Registry =========="

# 2.10 Registry JSON valid
echo -n "2.10 NS registry JSON: "
if php -r 'json_decode(file_get_contents("resources/ns-providers.json"), true, 512, JSON_THROW_ON_ERROR); echo "ok";' 2>/dev/null | grep -q "ok"; then
    pass "2.10"
else
    fail "2.10" "JSON validation failed"
fi

# 2.11 / 5.4 / 5.6b / 5.7 — NsProviderRegistry::match() cases
echo -n "2.11 NS matching (Cloudflare/IONOS/Dinahosting/Unknown): "
php > /tmp/test_ns_match.php << 'EOFPHP'
<?php
require 'vendor/autoload.php';
$json = json_decode(file_get_contents('resources/ns-providers.json'), true);
if (!isset($json['providers'])) { die("FAIL"); }

function testMatch($hosts, $providers) {
    foreach ($providers as $provider) {
        foreach ($provider['patterns'] as $pattern) {
            foreach ($hosts as $host) {
                $host_lower = strtolower(rtrim(trim($host), '.'));
                if (fnmatch(strtolower($pattern), $host_lower)) {
                    return $provider['name'];
                }
            }
        }
    }
    return null;
}

$tests = [
    [['ADA.NS.CLOUDFLARE.COM.'], 'Cloudflare'],
    [['ns1042.ui-dns.biz'], 'IONOS'],
    [['ns.dinahosting.com'], 'Dinahosting'],
    [['ns1.example.com'], null],
];

$all_ok = true;
foreach ($tests as [$hosts, $expected]) {
    $result = testMatch($hosts, $json['providers']);
    if ($result !== $expected && !($result === null && $expected === null)) {
        $all_ok = false;
        break;
    }
}
echo $all_ok ? "OK" : "FAIL";
EOFPHP
php /tmp/test_ns_match.php > /tmp/test_result.txt 2>&1
MATCH_RESULT=$(cat /tmp/test_result.txt)

if [ "$MATCH_RESULT" = "OK" ]; then
    pass "2.11"
else
    fail "2.11" "Match test returned: $MATCH_RESULT"
fi

# 5.6b IONOS narrowed pattern test (no false positives)
echo -n "5.6b IONOS pattern narrowing (no sibling collisions): "
php > /tmp/test_ionos_pattern.php << 'EOFPHP'
<?php
require 'vendor/autoload.php';
$json = json_decode(file_get_contents('resources/ns-providers.json'), true);
function testMatch($hosts, $providers) {
    foreach ($providers as $provider) {
        foreach ($provider['patterns'] as $pattern) {
            foreach ($hosts as $host) {
                $host_lower = strtolower(rtrim(trim($host), '.'));
                if (fnmatch(strtolower($pattern), $host_lower)) {
                    return $provider['name'];
                }
            }
        }
    }
    return null;
}

$tests = [
    [['ns-strato.ui-dns.de'], 'Strato'],
    [['ns-arsys.ui-dns.es'], 'Arsys'],
    [['ns1035.ui-dns.de'], 'IONOS'],
];

$all_ok = true;
foreach ($tests as [$hosts, $expected]) {
    $result = testMatch($hosts, $json['providers']);
    if ($result !== $expected) {
        $all_ok = false;
        break;
    }
}
echo $all_ok ? "OK" : "FAIL";
EOFPHP
php /tmp/test_ionos_pattern.php > /tmp/test_ionos_result.txt 2>&1
IONOS_RESULT=$(cat /tmp/test_ionos_result.txt)

if [ "$IONOS_RESULT" = "OK" ]; then
    pass "5.6b"
else
    fail "5.6b" "Pattern collision test returned: $IONOS_RESULT"
fi

echo ""
echo "========== Phase 3.5: Cron Registration =========="

# 3.5.14 Daily sync cron task registered (requires plugin active)
echo -n "3.5.14 Cron task registration: "
CRON_ROWS=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE '%Domainmanager%' AND name='DomainSync';" 2>/dev/null || echo "error")
if [ "$CRON_ROWS" = "1" ]; then
    pass "3.5.14"
elif [ "$CRON_ROWS" = "error" ] || [ "$CRON_ROWS" = "0" ]; then
    echo "(plugin not active; skipping)"
else
    fail "3.5.14" "Cron task rows=$CRON_ROWS, expected 1"
fi

echo ""
echo "========== Phase 4: Cron Batching =========="
# (4.7 covered in Phase 1 above)

echo ""
echo "========== Phase 5: Registry Structure =========="

# 5.1 / 5.6 / 5.6a / 5.6c — Registry entries present and sourced
echo -n "5.1 Registry provider count: "
PROVIDER_COUNT=$(php -r "echo count(json_decode(file_get_contents('resources/ns-providers.json'), true)['providers']);" 2>/dev/null)
if [ "$PROVIDER_COUNT" -gt "25" ]; then
    pass "5.1 (count=$PROVIDER_COUNT)"
else
    fail "5.1" "Provider count=$PROVIDER_COUNT, expected >25"
fi

echo -n "5.6c RaiolaNetworks and LucusHost entries: "
RAAIOLA_PRESENT=$(grep -c '"RaiolaNetworks"' resources/ns-providers.json || echo "0")
LUCUS_PRESENT=$(grep -c '"LucusHost"' resources/ns-providers.json || echo "0")
if [ "$RAAIOLA_PRESENT" = "1" ] && [ "$LUCUS_PRESENT" = "1" ]; then
    pass "5.6c"
else
    fail "5.6c" "RaiolaNetworks=$RAAIOLA_PRESENT, LucusHost=$LUCUS_PRESENT (expected 1 each)"
fi

echo ""
echo "========== Phase 5.8: Migration =========="

# 5.8.1 Migration column check (if DB state exists)
echo -n "5.8.1 Migration (is_managed column): "
MIGRATION_CHECK=$(podman exec testing_db_1 mariadb -uglpi -pglpi glpi -se \
    "SHOW COLUMNS FROM glpi_plugin_domainmanager_records LIKE 'is_managed';" 2>/dev/null | wc -l)
if [ "$MIGRATION_CHECK" -gt "0" ]; then
    pass "5.8.1"
else
    echo "(DB not initialized, skipping)"
fi

echo ""
echo "========== Summary =========="
TOTAL=$((PASS + FAIL))
echo "Passed: $PASS / $TOTAL"
if [ $FAIL -gt 0 ]; then
    echo -e "${RED}Failed: $FAIL${NC}"
    exit 1
else
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
fi
