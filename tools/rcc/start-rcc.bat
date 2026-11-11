@echo off
cd /d "%~dp0"
:loop
RCCService.exe -Console -verbose -placeid:-1 -port 64989
echo RCCService exited, restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
