# WhatsApp Service Wrapper Script (PowerShell)
# Fuer Verwendung mit NSSM oder direktem Start

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ScriptDir

# Lade .env Datei
$envFile = Join-Path $ScriptDir ".env"
if (Test-Path $envFile) {
    Get-Content $envFile | ForEach-Object {
        if ($_ -match "^\s*([^#][^=]+)=(.*)$") {
            $name = $matches[1].Trim()
            $value = $matches[2].Trim()
            [Environment]::SetEnvironmentVariable($name, $value, "Process")
        }
    }
}

# Log-Verzeichnis
$LogDir = if ($env:LOG_DIR) { $env:LOG_DIR } else { Join-Path $ScriptDir "logs" }
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir | Out-Null
}

# Log-Datei mit Datum
$Date = Get-Date -Format "yyyy-MM-dd"
$LogFile = Join-Path $LogDir "whatsapp-service-$Date.log"

# Funktion zum Loggen
function Write-Log {
    param($Message)
    $Timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$Timestamp - $Message" | Tee-Object -FilePath $LogFile -Append
}

Write-Log "Starting WhatsApp Service..."
Write-Log "Log directory: $LogDir"
Write-Log "Node.js version: $(node --version)"

# Node.js starten
try {
    $process = Start-Process -FilePath "node" -ArgumentList "src/index.js" -NoNewWindow -PassThru -RedirectStandardOutput "$LogFile.stdout" -RedirectStandardError "$LogFile.stderr" -Wait

    # Kombiniere stdout und stderr ins Hauptlog
    if (Test-Path "$LogFile.stdout") {
        Get-Content "$LogFile.stdout" | Add-Content $LogFile
        Remove-Item "$LogFile.stdout" -Force
    }
    if (Test-Path "$LogFile.stderr") {
        Get-Content "$LogFile.stderr" | Add-Content $LogFile
        Remove-Item "$LogFile.stderr" -Force
    }

    Write-Log "Service exited with code: $($process.ExitCode)"
} catch {
    Write-Log "Error: $_"
    exit 1
}
