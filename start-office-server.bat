@echo off
title Start CEIT Digital Logbook
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-office-server.ps1"
if errorlevel 1 (
    echo.
    echo The logbook server could not be started.
    pause
)
