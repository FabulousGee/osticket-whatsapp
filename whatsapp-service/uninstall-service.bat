@echo off
REM WhatsApp Service - NSSM Deinstallation Script
REM Muss als Administrator ausgefuehrt werden

set SERVICE_NAME=WhatsAppOsTicket

echo ============================================
echo WhatsApp Service Deinstallation
echo ============================================
echo.

REM Pruefe ob als Admin ausgefuehrt
net session >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo FEHLER: Bitte als Administrator ausfuehren!
    pause
    exit /b 1
)

echo Stoppe Service...
nssm stop %SERVICE_NAME% >nul 2>&1

echo Entferne Service...
nssm remove %SERVICE_NAME% confirm

echo.
echo Service wurde entfernt.
echo.
pause
