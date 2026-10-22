# find-lock.ps1 - which process is holding this file?
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File find-lock.ps1 -Path <file>
#
# Why this exists: a job log that stops growing usually means a stray `tail -f`
# still has it open. On Windows that makes every Add-Content in the job fail, so
# the job looks dead while it is actually running fine. Found on 2026-08-14 with
# two orphaned tail.exe from 2026-08-11 sitting on tanka-watch.log for 3 days -
# their parent shells were gone, so they would never have exited on their own.
#
# This prints the holders; it does NOT kill anything. Stop them yourself with
#   Stop-Process -Id <pid> -Force
#
# Uses the Restart Manager API (rstrtmgr.dll), which needs no admin rights and
# no Sysinternals handle.exe.
#
# ASCII only on purpose: PowerShell 5.1 reads a BOM-less .ps1 as CP932. This file
# does carry a BOM, but keeping it ASCII means a copy that loses the BOM still runs.
param([Parameter(Mandatory=$true)][string]$Path)

$src = @'
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;

public static class FileLockFinder {
    [StructLayout(LayoutKind.Sequential)]
    struct RM_UNIQUE_PROCESS {
        public int dwProcessId;
        public System.Runtime.InteropServices.ComTypes.FILETIME ProcessStartTime;
    }

    const int CCH_RM_MAX_APP_NAME = 255;
    const int CCH_RM_MAX_SVC_NAME = 63;

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    struct RM_PROCESS_INFO {
        public RM_UNIQUE_PROCESS Process;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = CCH_RM_MAX_APP_NAME + 1)]
        public string strAppName;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = CCH_RM_MAX_SVC_NAME + 1)]
        public string strServiceShortName;
        public int ApplicationType;
        public uint AppStatus;
        public uint TSSessionId;
        [MarshalAs(UnmanagedType.Bool)] public bool bRestartable;
    }

    [DllImport("rstrtmgr.dll", CharSet = CharSet.Auto)]
    static extern int RmStartSession(out uint pSessionHandle, int dwSessionFlags, string strSessionKey);

    [DllImport("rstrtmgr.dll")]
    static extern int RmEndSession(uint pSessionHandle);

    [DllImport("rstrtmgr.dll", CharSet = CharSet.Unicode)]
    static extern int RmRegisterResources(uint pSessionHandle, uint nFiles, string[] rgsFilenames,
        uint nApplications, [In] RM_UNIQUE_PROCESS[] rgApplications,
        uint nServices, string[] rgsServiceNames);

    [DllImport("rstrtmgr.dll")]
    static extern int RmGetList(uint dwSessionHandle, out uint pnProcInfoNeeded,
        ref uint pnProcInfo, [In, Out] RM_PROCESS_INFO[] rgAffectedApps, ref uint lpdwRebootReasons);

    public static List<int> WhoIsLocking(string path) {
        uint handle;
        string key = Guid.NewGuid().ToString();
        List<int> pids = new List<int>();

        int res = RmStartSession(out handle, 0, key);
        if (res != 0) throw new Exception("RmStartSession failed: " + res);

        try {
            uint needed = 0, count = 0, reasons = 0;
            string[] resources = new string[] { path };

            res = RmRegisterResources(handle, (uint)resources.Length, resources, 0, null, 0, null);
            if (res != 0) throw new Exception("RmRegisterResources failed: " + res);

            res = RmGetList(handle, out needed, ref count, null, ref reasons);
            if (res == 234) {                       // ERROR_MORE_DATA
                RM_PROCESS_INFO[] info = new RM_PROCESS_INFO[needed];
                count = needed;
                res = RmGetList(handle, out needed, ref count, info, ref reasons);
                if (res != 0) throw new Exception("RmGetList failed: " + res);
                for (int i = 0; i < count; i++) pids.Add(info[i].Process.dwProcessId);
            } else if (res != 0) {
                throw new Exception("RmGetList failed: " + res);
            }
        } finally {
            RmEndSession(handle);
        }
        return pids;
    }
}
'@

if (-not ('FileLockFinder' -as [type])) { Add-Type -TypeDefinition $src -Language CSharp }

$full = (Resolve-Path $Path).Path
Write-Output ("target: " + $full)

# Is it actually locked for writing right now?
try {
    $s = [System.IO.File]::Open($full, 'Open', 'Write', 'None')
    $s.Close()
    Write-Output "lock: none (opened for exclusive write)"
} catch {
    Write-Output "lock: HELD"
}

$pids = [FileLockFinder]::WhoIsLocking($full)
if (-not $pids -or $pids.Count -eq 0) {
    Write-Output "holders: none reported by Restart Manager"
    exit 0
}

Write-Output ("holders: " + $pids.Count)
foreach ($procId in $pids) {
    $p = Get-CimInstance Win32_Process -Filter "ProcessId=$procId" -ErrorAction SilentlyContinue
    if (-not $p) { Write-Output ("  pid " + $procId + " : gone"); continue }
    $cmd = if ($p.CommandLine) { $p.CommandLine } else { "(no command line)" }
    if ($cmd.Length -gt 400) { $cmd = $cmd.Substring(0, 400) + " ..." }
    $parent = Get-CimInstance Win32_Process -Filter ("ProcessId=" + $p.ParentProcessId) -ErrorAction SilentlyContinue
    $pname = if ($parent) { $parent.Name + " (" + $p.ParentProcessId + ")" } else { "(" + $p.ParentProcessId + ", gone)" }
    Write-Output ""
    Write-Output ("  pid      : " + $procId + "  " + $p.Name)
    Write-Output ("  started  : " + $p.CreationDate)
    Write-Output ("  parent   : " + $pname)
    Write-Output ("  cmdline  : " + $cmd)
}
