#!/bin/sh
set -eu
dcb_test_root="${1:?Pass an empty disposable test directory}"
dcb_repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
if [ -e "$dcb_test_root/nextcloud/config/config.php" ]; then
    echo 'Refusing to overwrite an existing Nextcloud installation' >&2
    exit 1
fi
mkdir -p "$dcb_test_root"
touch "$dcb_test_root/.dcb-disposable"
if [ -n "${DCB_TEST_ARCHIVES:-}" ]; then
    cp "$DCB_TEST_ARCHIVES/nextcloud.tar.bz2" "$dcb_test_root/nextcloud.tar.bz2"
else
    curl -fsSL --retry 2 https://download.nextcloud.com/server/releases/nextcloud-35.0.1.tar.bz2 -o "$dcb_test_root/nextcloud.tar.bz2"
fi
echo "7ed305e880192d804ba83179a19dd22257679608d63161a611ce3f8a918d2aa6  $dcb_test_root/nextcloud.tar.bz2" | sha256sum -c -
tar --no-same-owner --no-same-permissions -xjf "$dcb_test_root/nextcloud.tar.bz2" -C "$dcb_test_root"
if [ -n "${DCB_TEST_ARCHIVES:-}" ]; then
    cp "$DCB_TEST_ARCHIVES/deck.tar.gz" "$dcb_test_root/deck.tar.gz"
else
    curl -fsSL --retry 2 https://github.com/nextcloud/deck/archive/refs/tags/v1.19.0.tar.gz -o "$dcb_test_root/deck.tar.gz"
fi
echo "09b08cba3340951897352a14f097cb33fded5e4c26701ca2051fd9a18d1ff6fb  $dcb_test_root/deck.tar.gz" | sha256sum -c -
mkdir -p "$dcb_test_root/nextcloud/apps/deck"
tar --no-same-owner --no-same-permissions -xzf "$dcb_test_root/deck.tar.gz" --strip-components=1 -C "$dcb_test_root/nextcloud/apps/deck"
(cd "$dcb_test_root/nextcloud/apps/deck" && composer install --no-dev --no-scripts --no-interaction --prefer-dist)
mkdir -p "$dcb_test_root/nextcloud/custom_apps/deckcalendarbridge"
cp -R "$dcb_repo_root/appinfo" "$dcb_repo_root/lib" "$dcb_repo_root/config" "$dcb_repo_root/docs" "$dcb_repo_root/templates" "$dcb_repo_root/js" "$dcb_repo_root/css" "$dcb_repo_root/packaging" "$dcb_repo_root/bin" "$dcb_test_root/nextcloud/custom_apps/deckcalendarbridge/"
cp "$dcb_repo_root/README.md" "$dcb_repo_root/LICENSE.md" "$dcb_repo_root/CHANGELOG.md" "$dcb_repo_root/CONTRIBUTING.md" "$dcb_repo_root/SECURITY.md" "$dcb_repo_root/AI_USAGE.md" "$dcb_test_root/nextcloud/custom_apps/deckcalendarbridge/"
mkdir -p "$dcb_test_root/tmp"
# Keep even core Snowflake files inside the disposable fixture. Switching to
# www-data later must not inherit an installer-owned private /tmp sequence dir.
php -r '$root=$argv[1]; $config=["tempdirectory"=>dirname($root)."/tmp","apps_paths"=>[["path"=>$root."/apps","url"=>"/apps","writable"=>false],["path"=>$root."/custom_apps","url"=>"/custom_apps","writable"=>true]]]; file_put_contents($root."/config/dcb-test.config.php","<?php\n\$CONFIG = ".var_export($config,true).";\n");' "$dcb_test_root/nextcloud"
case "${DCB_TEST_DATABASE:-sqlite}" in
    sqlite)
        php "$dcb_test_root/nextcloud/occ" maintenance:install --database sqlite --admin-user ncadmin --admin-pass bridge-disposable-test-42 --data-dir "$dcb_test_root/data"
        ;;
    mysql)
        php "$dcb_test_root/nextcloud/occ" maintenance:install --database mysql --database-host 127.0.0.1 --database-name dcb_test --database-user dcb_test --database-pass "${DCB_TEST_DB_PASSWORD:?Disposable MariaDB password required}" --admin-user ncadmin --admin-pass bridge-disposable-test-42 --data-dir "$dcb_test_root/data"
        ;;
    *) echo 'Unsupported disposable test database' >&2; exit 1 ;;
esac
php "$dcb_test_root/nextcloud/occ" config:system:set overwrite.cli.url --value=https://bridge.example.invalid
if [ "${DCB_TEST_LOCKING:-database}" = redis ]; then
    php "$dcb_test_root/nextcloud/occ" config:system:set redis host --value=127.0.0.1
    php "$dcb_test_root/nextcloud/occ" config:system:set redis port --type=integer --value=6379
    php "$dcb_test_root/nextcloud/occ" config:system:set memcache.locking --value='\OC\Memcache\Redis'
fi
php "$dcb_test_root/nextcloud/occ" app:enable deck
php "$dcb_test_root/nextcloud/custom_apps/deckcalendarbridge/bin/check-environment.php" "$dcb_test_root/nextcloud"
php "$dcb_test_root/nextcloud/occ" app:enable deckcalendarbridge
php "$dcb_test_root/nextcloud/occ" deckcalendarbridge:doctor
php "$dcb_repo_root/tests/integration/provision.php" "$dcb_test_root/nextcloud"
if [ "${DCB_TEST_SKIP_SCENARIOS:-0}" != 1 ]; then
    python3 "$dcb_repo_root/tests/integration/run.py" "$dcb_test_root/nextcloud"
fi
