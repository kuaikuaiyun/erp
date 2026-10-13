@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0paypal_agent.ps1" %*
set "AGENT_EXIT_CODE=%ERRORLEVEL%"
if not "%AGENT_EXIT_CODE%"=="0" echo PayPal agent exited with code %AGENT_EXIT_CODE%. Check the logs directory.
exit /b %AGENT_EXIT_CODE%
