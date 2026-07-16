#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT_DIR="$ROOT_DIR/scripts"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/.env}"
BACKUP_ROOT="${BACKUP_ROOT:-$ROOT_DIR/backups}"
STATE_DIR="${DEPLOY_STATE_DIR:-$ROOT_DIR/.deploy-state}"
DRY_RUN="${DRY_RUN:-0}"

log() { printf '[v26] %s\n' "$*"; }
die() { printf '[v26] ERROR: %s\n' "$*" >&2; exit 1; }

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Gerekli komut bulunamadi: $1"
}

run_cmd() {
    printf '+ ';
    printf '%q ' "$@";
    printf '\n'
    if [[ "$DRY_RUN" != "1" ]]; then
        "$@"
    fi
}

require_production_env() {
    [[ -f "$ENV_FILE" ]] || die ".env bulunamadi. Production secret ve ayarlarini elle olusturun."
    local app_env
    app_env="$(awk -F= '$1 == "APP_ENV" {print $2; exit}' "$ENV_FILE" | tr -d '\r' | tr -d '"' | tr -d "'")"
    [[ "$app_env" == "production" ]] || die "APP_ENV=production olmadan production komutu calistirilamaz."
}

compose() {
    docker compose --env-file "$ENV_FILE" --profile production "$@"
}

compose_run() {
    printf '+ docker compose --env-file %q --profile production ' "$ENV_FILE"
    printf '%q ' "$@"
    printf '\n'
    if [[ "$DRY_RUN" != "1" ]]; then
        compose "$@"
    fi
}

require_clean_worktree() {
    [[ -z "$(git -C "$ROOT_DIR" status --porcelain)" ]] || die "Git calisma agaci temiz degil. Deploy/rollback icin commitlenmis release kullanin."
}

current_commit() {
    git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || printf 'unknown'
}

full_commit() {
    git -C "$ROOT_DIR" rev-parse HEAD 2>/dev/null || printf 'unknown'
}

safe_backup_root() {
    [[ "$BACKUP_ROOT" != "/" && "$BACKUP_ROOT" != "$ROOT_DIR" ]] || die "BACKUP_ROOT guvensiz bir dizine ayarlanmis."
    if [[ "$DRY_RUN" == "1" ]]; then
        log "DRY-RUN: backup dizini kontrol edilecek: $BACKUP_ROOT"
    else
        mkdir -p "$BACKUP_ROOT"
    fi
}

cleanup_backups() {
    local retention="${BACKUP_RETENTION_DAYS:-14}"
    [[ "$retention" =~ ^[0-9]+$ ]] || die "BACKUP_RETENTION_DAYS sayi olmali."
    (( retention > 0 )) || die "BACKUP_RETENTION_DAYS sifirdan buyuk olmali."
    log "Eski backup dosyalari temizleniyor: ${retention} gun"
    if [[ "$DRY_RUN" == "1" ]]; then
        log "DRY-RUN: $BACKUP_ROOT altinda db_*.sql ve metadata dosyalari temizlenecek."
        return
    fi
    find "$BACKUP_ROOT" -maxdepth 1 -type f \( -name 'db_*.sql' -o -name 'db_*.meta' \) -mtime "+$retention" -print -delete
}

create_database_backup() {
    require_command docker
    safe_backup_root
    local stamp commit dump meta
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    commit="$(current_commit)"
    dump="$BACKUP_ROOT/db_${stamp}_${commit}.sql"
    meta="$BACKUP_ROOT/db_${stamp}_${commit}.meta"
    log "Database backup: $(basename "$dump")"
    if [[ "$DRY_RUN" == "1" ]]; then
        log "DRY-RUN: mysqldump sonucu $dump dosyasina yazilacak."
    else
        compose exec -T mysql sh -c 'exec mysqldump --single-transaction --routines --triggers --hex-blob -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > "$dump"
        [[ -s "$dump" ]] || die "Database backup bos olusturuldu."
        {
            printf 'created_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
            printf 'commit=%s\n' "$(full_commit)"
            printf 'database=%s\n' "${DB_DATABASE:-configured-in-compose}"
        } > "$meta"
    fi
    cleanup_backups
    printf '%s\n' "$dump"
}

prepare_runtime() {
    compose exec -T app sh -c 'mkdir -p /var/www/html/tmp/cache /var/www/html/tmp/logs /var/www/html/tmp/runtime && chown -R www-data:www-data /var/www/html/tmp'
}

run_health_check() {
    local attempts="${HEALTH_CHECK_ATTEMPTS:-12}"
    local delay="${HEALTH_CHECK_DELAY_SECONDS:-5}"
    local output=""
    local i
    for ((i = 1; i <= attempts; i++)); do
        output="$(compose exec -T app php scripts/health-check.php 2>/dev/null || true)"
        if [[ "$output" == *'"database":"ok"'* && "$output" == *'"runtime":"ok"'* && "$output" == *'"queue_worker":"ok"'* && "$output" == *'"scheduler":"ok"'* ]]; then
            log "Health check OK."
            return 0
        fi
        log "Health bekleniyor ($i/$attempts)."
        [[ "$DRY_RUN" == "1" ]] && return 0
        sleep "$delay"
    done
    printf '%s\n' "$output" >&2
    return 1
}
