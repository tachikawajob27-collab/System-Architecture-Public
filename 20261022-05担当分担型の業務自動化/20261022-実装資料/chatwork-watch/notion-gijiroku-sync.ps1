# notion-gijiroku-sync.ps1
# Daily job: copy meeting notes from the PRODUCTION Notion database into the
# sandbox one, but only the ones about shooting/casting and retail stores.
#
# The prompt itself lives in notion-gijiroku-sync-prompt.md (UTF-8).
# This script is ASCII-only on purpose so PowerShell 5.1 cannot mangle it.
#
# Idempotency lives in the prompt, not here: the sandbox DB has a
# "転記元URL" column, and the prompt skips anything already recorded there.
# That is why running this twice in a day is harmless.

# ---------------------------------------------------------------------------
# WHY WE REDIRECT INSTEAD OF PIPING  (measured 2026-08-14)
# ---------------------------------------------------------------------------
# This script used to set $OutputEncoding / [Console]::OutputEncoding and then
# pipe the prompt in ("$prompt | & claude -p"). That destroyed every Japanese
# character: the agent received "?" and could not read its instructions.
#
# Measured, inside a .ps1, feeding the same 6-char string:
#   encoding preamble set + pipe -> efbbbf 3f3f3f3f3f3f  (BOM + "??????")
#   nothing set           + pipe -> efbbbf <correct utf8> (stray BOM)
#   cmd redirect from file       -> <correct utf8>        (clean)
#
# So the encoding preamble was the CAUSE, not the cure. $OutputEncoding read
# back "utf-8" the entire time while the pipe emitted ASCII, which is why this
# went unnoticed for so long. DO NOT add the preamble back.
#
# The failure is silent and severe: on 2026-08-14 the agent reconstructed the
# procedure from CLAUDE.md instead and still produced plausible-looking output,
# so a corrupted run does not necessarily look like a failed run.
#
# Redirection hands bytes from the file straight to the process, so no
# PowerShell encoder is involved, and it behaves the same under Task Scheduler
# as in an interactive shell.
#
# A BOM-less temp copy is used because the prompt files are saved WITH a BOM,
# and redirection would pass those 3 bytes through as a stray character.
# ---------------------------------------------------------------------------

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

$BASE       = Split-Path -Parent $MyInvocation.MyCommand.Path
$promptFile = Join-Path $BASE 'notion-gijiroku-sync-prompt.md'
$logFile    = Join-Path $BASE 'notion-gijiroku-sync.log'

function Log($msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Add-Content -Path $logFile -Value $line -Encoding UTF8
}

Log '--- start ---'

if (-not (Test-Path $promptFile)) {
    Log "ERROR: prompt file not found: $promptFile"
    exit 1
}

# claude must be on PATH (same assumption as run.ps1 / notion-progress.ps1)
$claude = Get-Command claude -ErrorAction SilentlyContinue
if (-not $claude) {
    Log 'ERROR: claude command not found on PATH'
    exit 1
}

$tmpDir = Join-Path $env:TEMP 'claude'
if (-not (Test-Path $tmpDir)) { New-Item -ItemType Directory -Path $tmpDir -Force | Out-Null }
$stdinFile = Join-Path $tmpDir 'notion-gijiroku-sync-stdin.md'

try {
    # Read as UTF8 (handles the BOM), write back without one.
    $prompt = Get-Content $promptFile -Raw -Encoding UTF8
    [System.IO.File]::WriteAllText($stdinFile, $prompt, (New-Object System.Text.UTF8Encoding($false)))

    # Sanity check: the prompt must still be multi-byte. If this trips,
    # something flattened it to ASCII - stop rather than let the agent guess
    # at a corrupted instruction and write to Notion from it.
    $bytes = [System.IO.File]::ReadAllBytes($stdinFile)
    if ($bytes.Length -le $prompt.Length) {
        Log "ERROR: prompt looks single-byte ($($bytes.Length) bytes / $($prompt.Length) chars). Aborting."
        exit 1
    }

    $cmdLine = 'claude -p --permission-mode auto < "' + $stdinFile + '"'
    $out = & cmd /c $cmdLine | Out-String

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
