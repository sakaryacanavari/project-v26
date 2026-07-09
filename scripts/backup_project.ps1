param(
    [string]$ProjectRoot = "C:\laragon\www",
    [string]$BackupRoot = "C:\laragon\backups",
    [string]$DatabaseName = "proje",
    [string]$DatabaseUser = "root",
    [string]$DatabasePassword = "",
    [string]$DatabaseHost = "127.0.0.1",
    [string]$MySqlDumpPath = "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe",
    [switch]$IncludeVendor,
    [switch]$IncludeNodeModules
)

$ErrorActionPreference = "Stop"

function New-BackupFolder {
    param([string]$RootPath)

    if (-not (Test-Path -Path $RootPath)) {
        New-Item -ItemType Directory -Path $RootPath | Out-Null
    }

    $timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
    $target = Join-Path $RootPath "projectv26_backup_$timestamp"
    New-Item -ItemType Directory -Path $target | Out-Null
    return $target
}

function Copy-IfExists {
    param(
        [string]$SourcePath,
        [string]$DestinationPath
    )

    if (Test-Path -Path $SourcePath) {
        Copy-Item -Path $SourcePath -Destination $DestinationPath -Recurse -Force
    }
}

function Write-EnvSnapshot {
    param(
        [string]$OutputFile,
        [string]$ProjectRootPath
    )

    $phpVersion = (& php -v | Select-Object -First 1)
    $phpModules = (& php -m)

    $content = @(
        "Project Root: $ProjectRootPath"
        "Generated At: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
        ""
        "PHP Version:"
        $phpVersion
        ""
        "PHP Modules:"
    ) + $phpModules

    Set-Content -Path $OutputFile -Value $content -Encoding UTF8
}

$backupFolder = New-BackupFolder -RootPath $BackupRoot
$projectBackupFolder = Join-Path $backupFolder "project_files"
New-Item -ItemType Directory -Path $projectBackupFolder | Out-Null

$includePaths = @(
    "App",
    "templates",
    "lang",
    "htdocs",
    "public",
    "crons",
    "scripts",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "bootstrap.php",
    "conf.php",
    "conf.sample.php",
    "db.sql",
    "routes.php",
    "SCHEMA_SYNC_APPLY_ORDER.md",
    "BETA_RELEASE_RUNBOOK.md",
    "BETA_FINAL_SMOKE_SCENARIOS.md",
    "BETA_QUICK_TEST_FLOW.md",
    "BETA_DAY0_MONITORING.md",
    "BETA_GO_NO_GO.md",
    "EARLY_ACCESS_SMOKE_CHECKLIST.md",
    "i18n.php",
    "index.php",
    ".htaccess",
    ".gitignore"
)

if ($IncludeVendor) {
    $includePaths += "vendor"
}

if ($IncludeNodeModules) {
    $includePaths += "node_modules"
}

foreach ($item in $includePaths) {
    $source = Join-Path $ProjectRoot $item
    Copy-IfExists -SourcePath $source -DestinationPath $projectBackupFolder
}

$dbDumpFile = Join-Path $backupFolder "database_$DatabaseName.sql"
if (Test-Path -Path $MySqlDumpPath) {
    $dumpArgs = @(
        "--host=$DatabaseHost",
        "--user=$DatabaseUser",
        "--result-file=$dbDumpFile",
        "--routines",
        "--triggers",
        "--single-transaction",
        $DatabaseName
    )

    if ($DatabasePassword -ne "") {
        $dumpArgs = @("--password=$DatabasePassword") + $dumpArgs
    }

    & $MySqlDumpPath @dumpArgs
} else {
    Write-Warning "mysqldump bulunamadi: $MySqlDumpPath"
}

$envSnapshotFile = Join-Path $backupFolder "environment_snapshot.txt"
Write-EnvSnapshot -OutputFile $envSnapshotFile -ProjectRootPath $ProjectRoot

$zipFile = "$backupFolder.zip"
if (Test-Path -Path $zipFile) {
    Remove-Item -Path $zipFile -Force
}

Compress-Archive -Path "$backupFolder\*" -DestinationPath $zipFile -Force

Write-Host "Backup hazirlandi:"
Write-Host $backupFolder
Write-Host $zipFile
