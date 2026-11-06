@echo off
rem Registers finobe2012: links (the site's Play button for 2012 games) for this Windows user.
rem Run it again if you move this folder.
set "DIR=%~dp0"
reg add "HKCU\Software\Classes\finobe2012" /ve /d "URL:Finobe 2012" /f >nul
reg add "HKCU\Software\Classes\finobe2012" /v "URL Protocol" /d "" /f >nul
reg add "HKCU\Software\Classes\finobe2012\DefaultIcon" /ve /d "\"%DIR%RobloxApp.exe\",0" /f >nul
reg add "HKCU\Software\Classes\finobe2012\shell\open\command" /ve /d "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"%DIR%FinobeLaunch2012.ps1\" \"%%1\"" /f >nul
echo Finobe 2012 is installed. Press Play on a 2012 game on finobe.wtf.
pause
