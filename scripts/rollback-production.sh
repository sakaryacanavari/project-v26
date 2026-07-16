#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=production-common.sh
source "$SCRIPT_DIR/production-common.sh"

confirm=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --confirm) confirm=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --help|-h)
            printf 'Usage: scripts/rollback-production.sh --confirm [--dry-run]\n'
            exit 0
            ;;
        *) die "Bilinmeyen arguman: $1" ;;
    esac
done

[[ "$confirm" == "1" ]] || die "Rollback icin --confirm zorunludur."
require_command docker
require_command git
require_production_env
require_clean_worktree

target_file="$STATE_DIR/previous_release"
[[ -f "$target_file" ]] || die "Onceki release kaydi bulunamadi. Once deploy-production.sh calistirin."
target_ref="$(tr -d '[:space:]' < "$target_file")"
current_ref="$(full_commit)"
git -C "$ROOT_DIR" rev-parse --verify "$target_ref^{commit}" >/dev/null 2>&1 || die "Rollback release'i gecersiz: $target_ref"

if [[ "$DRY_RUN" != "1" ]]; then
    "$SCRIPT_DIR/production-backup.sh"
fi

log "Rollback hedefi: ${target_ref:0:12}"
if [[ "$DRY_RUN" == "1" ]]; then
    log "DRY-RUN: git checkout --detach $target_ref"
else
    git -C "$ROOT_DIR" checkout --detach "$target_ref"
fi

compose_run up -d mysql redis
compose_run up -d --build app
compose_run exec -T app composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
compose_run exec -T app composer schema-migrate
if [[ "$DRY_RUN" != "1" ]]; then
    prepare_runtime
fi
compose_run up -d --build --force-recreate app worker scheduler

if [[ "$DRY_RUN" != "1" ]]; then
    run_health_check || die "Rollback sonrasi health check basarisiz. Mevcut release commit'i: $current_ref"
    printf '%s\n' "$target_ref" > "$STATE_DIR/active_release"
    printf '%s\n' "$current_ref" > "$STATE_DIR/previous_release"
    printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATE_DIR/last_rollback_at"
fi
log "Rollback tamamlandi."
