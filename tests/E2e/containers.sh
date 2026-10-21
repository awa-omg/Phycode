#!/usr/bin/env bash
set -euo pipefail

repo="$(cd "$(dirname "$0")/../.." && pwd)"
runtime="${PHYCODE_E2E_RUNTIME:-podman}"
distros=("$@")
[ ${#distros[@]} -eq 0 ] && distros=(debian alpine arch opensuse)

image() {
  case "$1" in
    debian) echo docker.io/library/debian:trixie-slim ;;
    alpine) echo docker.io/library/alpine:latest ;;
    arch) echo docker.io/library/archlinux:latest ;;
    opensuse) echo registry.opensuse.org/opensuse/tumbleweed:latest ;;
    *) echo "unknown distribution: $1 (debian, alpine, arch, opensuse)" >&2; return 1 ;;
  esac
}

setup() {
  case "$1" in
    debian) echo 'apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends php8.4-cli php8.4-curl php8.4-mbstring php8.4-xml composer git unzip ca-certificates >/dev/null && useradd -m neighbour' ;;
    alpine) echo 'apk add -q php85 php85-curl php85-mbstring php85-posix php85-pcntl php85-phar php85-openssl php85-iconv php85-tokenizer php85-dom php85-xml php85-xmlwriter php85-ctype composer bash git coreutils && adduser -D neighbour' ;;
    arch) echo 'pacman -Sy --noconfirm --needed php composer git >/dev/null && useradd -m neighbour' ;;
    opensuse) echo 'zypper -n -q in php8 php8-cli php8-curl php8-mbstring php8-posix php8-pcntl php8-phar php8-openssl php8-tokenizer php8-dom php8-xmlwriter php8-ctype php8-iconv php8-zip php-composer2 git-core bash shadow >/dev/null && useradd -m neighbour' ;;
  esac
}

status=0
for distro in "${distros[@]}"; do
  echo "== $distro: $(image "$distro")"
  if ! "$runtime" run --rm ${PHYCODE_E2E_NETWORK:+--network "$PHYCODE_E2E_NETWORK"} \
      -v "$repo:/src:ro" \
      --mount type=tmpfs,destination=/srv/disk/vol \
      "$(image "$distro")" \
      sh -c "$(setup "$distro") \
        && mkdir /phycode && cd /src && tar --exclude=./vendor --exclude=./bin/phycode-tui --exclude=./build -cf - . | tar -xf - -C /phycode \
        && cd /phycode && composer install -q --no-interaction --no-progress \
        && php tests/E2e/hard-line.php \
        && vendor/bin/phpunit --testsuite unit --filter 'HardLine|PermissionModes|SshPermissions|Deployments|DeployTools' --no-progress"; then
    status=1
    echo "== $distro: FAILED"
  fi
done
exit $status
