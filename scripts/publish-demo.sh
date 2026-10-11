#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"
export DEMO_BASE_PATH="${DEMO_BASE_PATH:-/bezalko/}"
node scripts/build-demo.mjs
node scripts/check-demo.mjs
remote_url="$(git remote get-url origin)"
source_sha="$(git rev-parse HEAD)"
demo_stage="$(mktemp -d "${TMPDIR:-/tmp}/bezalko-pages.XXXXXX")"
trap 'rm -rf "$demo_stage"' EXIT
if git ls-remote --exit-code --heads "$remote_url" gh-pages >/dev/null 2>&1; then
  git clone --quiet --branch gh-pages --single-branch "$remote_url" "$demo_stage"
else
  git -C "$demo_stage" init --quiet --initial-branch=gh-pages
  git -C "$demo_stage" remote add origin "$remote_url"
fi
git -C "$demo_stage" config user.name "$(git config user.name)"
git -C "$demo_stage" config user.email "$(git config user.email)"
# The temporary directory contains only the dedicated demonstration branch.
find "$demo_stage" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R dist/. "$demo_stage/"
git -C "$demo_stage" add -A
if git -C "$demo_stage" diff --cached --quiet; then
  echo 'Demonstration unchanged; no commit or push required.'
else
  git -C "$demo_stage" commit --quiet -m "Deploy demonstration from ${source_sha:0:12}"
  git -C "$demo_stage" push origin gh-pages
fi
