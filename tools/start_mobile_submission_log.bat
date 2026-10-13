@echo off
title DIT Logbook - Mobile Submission Log
"C:\xampp\php\php.exe" "%~dp0mobile_submission_console.php"
if errorlevel 1 pause
