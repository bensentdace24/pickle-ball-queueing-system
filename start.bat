@echo off
title Pickleball Queue - keep this window open
cd /d "%~dp0"
start "" /min cmd /c "timeout /t 4 /nobreak >nul & start http://localhost:8000/admin"
php artisan serve --host=0.0.0.0 --port=8000
pause
