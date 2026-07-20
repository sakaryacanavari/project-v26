#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=production-common.sh
source "$SCRIPT_DIR/production-common.sh"

previous_ref=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --previous-ref) [[ $# -ge 2 ]] || die "--previous-ref bir commit veya tag ister."; previous_ref="$2"; shift 2 ;;
        --help|-h)
            printf 'Usage: scripts/deploy-production.sh [--dry-run] [--previous-ref COMMIT_OR_TAG]\n'
            exit 0
            ;;
        *) die "Bilinmeyen arguman: $1" ;;
    esac
done

require_command docker
require_command git
require_production_env
require_clean_worktree
compose_run config --quiet

current_ref="$(full_commit)"
if [[ -z "$previous_ref" ]]; then
    previous_ref="$(git -C "$ROOT_DIR" rev-parse HEAD^ 2>/dev/null || true)"
fi
[[ -n "$previous_ref" ]] || die "Onceki release bulunamadi. --previous-ref kullanin."
git -C "$ROOT_DIR" rev-parse --verify "$previous_ref^{commit}" >/dev/null 2>&1 || die "Onceki release gecersiz: $previous_ref"

mkdir -p "$STATE_DIR"
log "Deploy basliyor: $(current_commit)"
if [[ "$DRY_RUN" == "1" ]]; then
    log "DRY-RUN: deploy oncesi database backup alinacak."
else
    "$SCRIPT_DIR/production-backup.sh"
fi

compose_run up -d mysql redis
compose_run run --rm frontend-build
compose_run up -d --build app
compose_run exec -T app composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
compose_run exec -T app composer schema-migrate
if [[ "$DRY_RUN" == "1" ]]; then
    log "DRY-RUN: runtime/cache dizinleri hazirlanacak."
else
    prepare_runtime
fi
compose_run up -d --build --force-recreate app worker scheduler

if [[ "$DRY_RUN" != "1" ]]; then
    run_health_check || die "Health check basarisiz; deploy tamamlanmadi."
fi

if [[ "$DRY_RUN" != "1" ]]; then
    printf '%s\n' "$current_ref" > "$STATE_DIR/active_release"
    printf '%s\n' "$previous_ref" > "$STATE_DIR/previous_release"
    printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATE_DIR/last_deploy_at"
fi
log "Production deploy tamamlandi."
