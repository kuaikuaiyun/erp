@echo off
setlocal
cd /d "%~dp0"
call "%~dp0run_paypal_agent.bat" -TestImport
set "AGENT_EXIT_CODE=%ERRORLEVEL%"
echo.
if "%AGENT_EXIT_CODE%"=="0" (
    echo ERP connection test succeeded.
) else (
    echo ERP connection test failed. Check the logs directory.
)
pause
exit /b %AGENT_EXIT_CODE%
