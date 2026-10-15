#!/usr/bin/env bash
# Run on Beget over its authenticated terminal, from a private source checkout.
set -euo pipefail
TASK_SOURCE=$(cd "$(dirname "$0")/.." && pwd)
TASK_DEST=${1:?Pass the existing absolute public_html path}
TASK_HASH=${2:-}
if [[ "$TASK_DEST" != /*/public_html || ! -d "$TASK_DEST" ]]; then
  echo 'Destination must be an existing public_html directory.' >&2; exit 1
fi
TASK_PARENT=$(dirname "$TASK_DEST")
TASK_DATA="$TASK_PARENT/.neverlimonad-data"
TASK_STAMP=$(date -u +%Y%m%dT%H%M%SZ)
if [[ ! -f "$TASK_DATA/cms.sqlite" && ! "$TASK_HASH" =~ ^[a-f0-9]{64}$ ]]; then
  echo 'First installation requires the SHA-256 of a random one-time setup key.' >&2; exit 1
fi
cd "$TASK_SOURCE"
node scripts/build-production.mjs
# Backup stays outside the public root. Existing uploads and content are preserved.
mkdir -p "$TASK_PARENT/deployment-backups"
chmod 700 "$TASK_PARENT/deployment-backups"
tar -czf "$TASK_PARENT/deployment-backups/site-$TASK_STAMP.tar.gz" -C "$TASK_DEST" .
chmod 600 "$TASK_PARENT/deployment-backups/site-$TASK_STAMP.tar.gz"
TASK_EXTRA=()
# Hosting PHP/HTTPS directives may be added to .htaccess after the first deployment.
if [[ -f "$TASK_DATA/cms.sqlite" ]]; then TASK_EXTRA+=(--exclude=/.htaccess); fi
rsync -a --exclude=uploads/ --exclude=router.php --exclude=public/ "${TASK_EXTRA[@]}" build/production/ "$TASK_DEST/"
if [[ ! -f "$TASK_DATA/cms.sqlite" ]]; then
  NL_DATA_DIR="$TASK_DATA" php "$TASK_DEST/cms/install.php" "$TASK_HASH"
fi
printf '%s\n' 'Deployment completed; runtime content and uploads preserved.'
