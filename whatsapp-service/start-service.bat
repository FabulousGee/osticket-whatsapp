@echo off
REM WhatsApp Service Wrapper Script
REM Fuer Verwendung mit NSSM oder direktem Start

cd /d "%~dp0"

REM Lade Umgebungsvariablen aus .env falls vorhanden
if exist .env (
    for /f "usebackq tokens=1,* delims==" %%a in (".env") do (
        set "%%a=%%b"
    )
)

REM Standard Log-Verzeichnis falls nicht gesetzt
if "%LOG_DIR%"=="" set "LOG_DIR=%~dp0logs"

REM Log-Verzeichnis erstellen falls nicht vorhanden
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

REM Timestamp fuer Log-Datei
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set datetime=%%I
set "DATE=%datetime:~0,4%-%datetime:~4,2%-%datetime:~6,2%"

REM Node.js starten mit Log-Ausgabe
node src/index.js >> "%LOG_DIR%\whatsapp-service-%DATE%.log" 2>&1
