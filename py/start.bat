@echo off
REM Sobe o YouTube IPTV no Windows (duplo clique).
REM A primeira vez: pip install -r requirements.txt
cd /d "%~dp0"
if not exist ".env" copy ".env.example" ".env" >nul
set PORT=8000
if not "%1"=="" set PORT=%1
echo.
echo   YouTube IPTV  ->  http://localhost:%PORT%
echo   Pare com Ctrl+C
echo.
python run.py
pause
