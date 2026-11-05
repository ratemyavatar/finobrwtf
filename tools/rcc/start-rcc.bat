@echo off
rem Finobe arbiter: runs the patched 2016 RCCService with its SOAP API on port 64989.
rem Put this file next to RCCService.exe. It restarts RCC if it ever exits.
cd /d "%~dp0"
:loop
RCCService.exe -Console -verbose -placeid:-1 -port 64989
echo RCCService exited, restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
