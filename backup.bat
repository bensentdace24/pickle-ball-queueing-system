@echo off
set PGPASSWORD=vincent1234
set BACKUP_DIR=%USERPROFILE%\Documents\PickleballBackups
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm"') do set STAMP=%%i
"C:\Program Files\PostgreSQL\18\bin\pg_dump.exe" -U postgres -h localhost -F c -f "%BACKUP_DIR%\pickleball_%STAMP%.backup" pickleball
forfiles /p "%BACKUP_DIR%" /m *.backup /d -30 /c "cmd /c del @path" 2>nul
echo Backup saved in %BACKUP_DIR%
if /i not "%~1"=="auto" pause
