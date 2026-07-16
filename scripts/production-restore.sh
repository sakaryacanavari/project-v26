#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=production-common.sh
source "$SCRIPT_DIR/production-common.sh"

confirm=0
dump=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --confirm) confirm=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --help|-h)
            printf 'Usage: scripts/production-restore.sh --confirm BACKUP.sql\n'
            exit 0
            ;;
        --*) die "Bilinmeyen arguman: $1" ;;
        *) [[ -z "$dump" ]] || die "Yalnizca bir backup dosyasi verilebilir."; dump="$1"; shift ;;
    esac
done

[[ "$confirm" == "1" ]] || die "Restore icin --confirm zorunludur."
[[ -n "$dump" && -f "$dump" ]] || die "Backup SQL dosyasi bulunamadi."
require_command docker
require_production_env
safe_backup_root

log "Restore oncesi mevcut database backup'i aliniyor."
create_database_backup >/dev/null
compose_run up -d mysql
log "Database restore basliyor: $(basename "$dump")"
if [[ "$DRY_RUN" == "1" ]]; then
    log "DRY-RUN: SQL backup mysql container'ina aktarilacak."
else
    compose exec -T mysql sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < "$dump"
fi
log "Restore tamamlandi. Uygulama health check'i deploy sonrasinda calistirilmalidir."
