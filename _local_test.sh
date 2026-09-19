#!/usr/bin/env bash
CWD="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
trap "docker rm -f magento-project-community-edition" SIGINT
set -e

export VENDOR="samjuk"
export MODULE="m2-module-media-proxy"
export MODULE_DIR_BASE="/data/extensions"
export MODULE_DIR="${MODULE_DIR_BASE}/${MODULE}"
export RELATIVE_PROJECT_DIR="../.."

# CI resolves this matrix dynamically from samjuk/github-actions, which we cannot
# read from here. Override with VERSIONS="8.3:2.4.8" to test a single combination.
# Each pair has to exist as a michielgerritsen tag, php<XY>-fpm-magento<VERSION>.
VERSIONS="${VERSIONS:-7.4:2.4.3
8.1:2.4.5
8.2:2.4.6
8.3:2.4.7
8.4:2.4.8}"

echo "[i] Executing Unit Tests Locally"
while IFS= read -r VER; do
  export PHP_VER=$(echo "${VER}" | awk -F: '{print $1}')
  export MAGE_VER=$(echo "${VER}" | awk -F: '{print $2}')
  echo "[i] Testing ${MAGE_VER} on ${PHP_VER}"

  docker rm -f magento-project-community-edition 2>/dev/null || true
  # Tags read php83-fpm-magento2.4.8, not 8.3-magento2.4.8.
  IMAGE_TAG="php${PHP_VER//./}-fpm-magento${MAGE_VER}"
  docker run --detach --name magento-project-community-edition \
    -e MODULE_DIR=${MODULE_DIR} -e RELATIVE_PROJECT_DIR=${RELATIVE_PROJECT_DIR} \
    michielgerritsen/magento-project-community-edition:${IMAGE_TAG}

  docker cp ${CWD}/ magento-project-community-edition:${MODULE_DIR}/
  # The image stopped shipping make at some point, and the targets are the contract.
  docker exec magento-project-community-edition sh -c \
    'command -v make >/dev/null || (apt-get update -qq && apt-get install -y -qq make)'
  docker exec magento-project-community-edition composer require ${VENDOR}/${MODULE}:@dev
  docker exec magento-project-community-edition make -C ${MODULE_DIR} test-composer
  docker exec magento-project-community-edition make -C ${MODULE_DIR} test-phpstan
  docker exec magento-project-community-edition make -C ${MODULE_DIR} test-phpcs
  docker exec magento-project-community-edition make -C ${MODULE_DIR} test-unit
  docker exec magento-project-community-edition make -C ${MODULE_DIR} test-compile
  docker rm -f magento-project-community-edition 2>/dev/null || true
done <<< "${VERSIONS}"
