@echo off
REM WhatsApp Service - NSSM Installation Script
REM Muss als Administrator ausgefuehrt werden

set SERVICE_NAME=WhatsAppOsTicket
set "SERVICE_DIR=%~dp0"
set "NODE_PATH=node"
set "LOG_DIR=%SERVICE_DIR%logs"

echo ============================================
echo WhatsApp Service Installation
echo ============================================
echo.

REM Pruefe ob NSSM verfuegbar ist
where nssm >nul 2>nul
if %ERRORLEVEL% neq 0 (
    echo FEHLER: nssm nicht gefunden!
    echo Bitte nssm installieren und zum PATH hinzufuegen.
    echo Download: https://nssm.cc/download
    pause
    exit /b 1
)

REM Pruefe ob als Admin ausgefuehrt
net session >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo FEHLER: Bitte als Administrator ausfuehren!
    pause
    exit /b 1
)

REM Log-Verzeichnis erstellen
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo Service Name: %SERVICE_NAME%
echo Verzeichnis: %SERVICE_DIR%
echo Log-Verzeichnis: %LOG_DIR%
echo.

REM Entferne existierenden Service falls vorhanden
nssm stop %SERVICE_NAME% >nul 2>&1
nssm remove %SERVICE_NAME% confirm >nul 2>&1

REM Installiere Service
echo Installiere Service...
nssm install %SERVICE_NAME% "%NODE_PATH%" "src/index.js"

REM Konfiguriere Service
nssm set %SERVICE_NAME% AppDirectory "%SERVICE_DIR%"
nssm set %SERVICE_NAME% AppStdout "%LOG_DIR%\service-stdout.log"
nssm set %SERVICE_NAME% AppStderr "%LOG_DIR%\service-stderr.log"
nssm set %SERVICE_NAME% AppStdoutCreationDisposition 4
nssm set %SERVICE_NAME% AppStderrCreationDisposition 4
nssm set %SERVICE_NAME% AppRotateFiles 1
nssm set %SERVICE_NAME% AppRotateOnline 1
nssm set %SERVICE_NAME% AppRotateBytes 10485760
nssm set %SERVICE_NAME% Description "WhatsApp Integration Service for osTicket"
nssm set %SERVICE_NAME% Start SERVICE_AUTO_START
nssm set %SERVICE_NAME% AppExit Default Restart
nssm set %SERVICE_NAME% AppRestartDelay 5000

echo.
echo ============================================
echo Service installiert!
echo ============================================
echo.
echo Befehle:
echo   nssm start %SERVICE_NAME%    - Service starten
echo   nssm stop %SERVICE_NAME%     - Service stoppen
echo   nssm restart %SERVICE_NAME%  - Service neustarten
echo   nssm status %SERVICE_NAME%   - Status anzeigen
echo   nssm edit %SERVICE_NAME%     - Konfiguration bearbeiten
echo.
echo Logs: %LOG_DIR%
echo.

set /p START_NOW="Service jetzt starten? (j/n): "
if /i "%START_NOW%"=="j" (
    nssm start %SERVICE_NAME%
    echo Service gestartet!
    nssm status %SERVICE_NAME%
)

echo.
pause
