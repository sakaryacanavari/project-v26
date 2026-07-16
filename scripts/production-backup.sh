#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=production-common.sh
source "$SCRIPT_DIR/production-common.sh"

usage() {
    cat <<'EOF'
Usage: scripts/production-backup.sh [--dry-run]

Environment: ENV_FILE, BACKUP_ROOT, BACKUP_RETENTION_DAYS
EOF
}

case "${1:-}" in
    --help|-h) usage; exit 0 ;;
    --dry-run) DRY_RUN=1 ;;
    "") ;;
    *) die "Bilinmeyen arguman: $1" ;;
esac

require_command docker
require_command git
require_production_env
create_database_backup
log "Backup tamamlandi."
