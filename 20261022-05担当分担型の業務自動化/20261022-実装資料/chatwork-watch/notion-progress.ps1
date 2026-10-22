# notion-progress.ps1
# Daily job: update the "implementation progress" section of the Notion design doc.
# Runs once a day at 23:57 via Task Scheduler ("Notion進捗更新").
#
# The prompt itself lives in notion-progress-prompt.md (UTF-8).
# This script is ASCII-only on purpose so PowerShell 5.1 cannot mangle it.

$ErrorActionPreference = 'Continue'
# Decode what comes BACK from claude as UTF-8.
# Under Task Scheduler, [Console]::OutputEncoding defaults to the OEM codepage
# (CP932 here), so claude's UTF-8 stdout is mis-decoded and every Japanese
# character becomes mojibake. Measured 2026-08-17: the 23:00 gijiroku run
# completed fine but logged garbage, while run.ps1 (which sets this) was clean.
#
# This is the OUTPUT side. It is a DIFFERENT knob from $OutputEncoding, which
# only affects strings piped INTO a native exe. Do NOT set $OutputEncoding here:
# the prompt is fed by cmd redirect from a file, so no PowerShell encoder is
# involved on the way in, and setting it was the cause of the input-side bug.
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false

# Do NOT pipe the prompt into claude, and do NOT set $OutputEncoding to "fix" it.
# Under Task Scheduler (fresh powershell.exe -NoProfile) $OutputEncoding is
# us-ascii and the pipe turns every Japanese character into "?".
# Measured 2026-08-15; full numbers in notion-gijiroku-sync.ps1.
# Redirect from a BOM-less temp file instead - no PowerShell encoder involved.

$BASE       = Split-Path -Parent $MyInvocation.MyCommand.Path
$promptFile = Join-Path $BASE 'notion-progress-prompt.md'
$logFile    = Join-Path $BASE 'notion-progress.log'

function Log($msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Add-Content -Path $logFile -Value $line -Encoding UTF8
}

Log '--- start ---'

if (-not (Test-Path $promptFile)) {
    Log "ERROR: prompt file not found: $promptFile"
    exit 1
}

# claude must be on PATH (same assumption as run.ps1)
$claude = Get-Command claude -ErrorAction SilentlyContinue
if (-not $claude) {
    Log 'ERROR: claude command not found on PATH'
    exit 1
}

$tmpDir = Join-Path $env:TEMP 'claude'
if (-not (Test-Path $tmpDir)) { New-Item -ItemType Directory -Path $tmpDir -Force | Out-Null }
$stdinFile = Join-Path $tmpDir 'notion-progress-stdin.md'

try {
    $prompt = Get-Content $promptFile -Raw -Encoding UTF8
    [System.IO.File]::WriteAllText($stdinFile, $prompt, (New-Object System.Text.UTF8Encoding($false)))

    $bytes = [System.IO.File]::ReadAllBytes($stdinFile)
    if ($bytes.Length -le $prompt.Length) {
        Log "ERROR: prompt looks single-byte ($($bytes.Length) bytes / $($prompt.Length) chars) - aborting"
        exit 1
    }

    $out = & cmd /c ('claude -p --permission-mode auto < "' + $stdinFile + '"') | Out-String

    if ([string]::IsNullOrWhiteSpace($out)) {
        Log 'WARN: empty response from claude'
    } elseif ($out -match 'Failed to authenticate' -or
         $out -match '^API Error' -or
         $out -match 'OAuth session expired' -or
         $out -match 'Credit balance is too low') {
        # claude's own error text is non-empty, so it passes the check above and
        # would be logged as a normal result. Record it as a failure instead.
        Log 'ERROR: claude returned an error string, not a result'
        Add-Content -Path $logFile -Value $out -Encoding UTF8
        Log '--- end ---'
        exit 1
    } else {
        Log 'result:'
        Add-Content -Path $logFile -Value $out -Encoding UTF8
    }
} catch {
    Log ("ERROR: " + $_.Exception.Message)
    exit 1
} finally {
    Remove-Item $stdinFile -Force -ErrorAction SilentlyContinue
}

Log '--- end ---'
