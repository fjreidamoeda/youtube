@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"
title YouTube IPTV
color 0B

echo.
echo  ==================================================
echo     YouTube IPTV
echo  ==================================================
echo.

REM ================================================================ PYTHON
set "PY="
py -3 -c "print(1)" >nul 2>nul && set "PY=py -3"
if not defined PY python -c "print(1)" >nul 2>nul && set "PY=python"
if not defined PY (
  echo  [ERRO] Python nao encontrado.
  echo.
  echo  Instale em https://www.python.org/downloads/
  echo  e marque "Add Python to PATH" durante a instalacao.
  echo.
  pause
  exit /b 1
)

REM ================================================================ SENHA
if /i "%~1"=="senha" (
  set "NOVA=%~2"
  if not defined NOVA set /p NOVA=Digite a nova senha do admin: 
  echo.
  %PY% run.py --senha "!NOVA!"
  echo.
  pause
  exit /b 0
)

REM ================================================================ FERRAMENTAS
where ffmpeg  >nul 2>nul || echo  [AVISO] ffmpeg nao esta no PATH - os videos NAO vao tocar.
where ffprobe >nul 2>nul || echo  [AVISO] ffprobe nao esta no PATH.
where yt-dlp  >nul 2>nul || echo  [AVISO] yt-dlp nao esta no PATH - instale com: pip install -U yt-dlp

REM ================================================================ .env
set "KEY="
if not exist ".env" (
  if exist ".env.example" (
    copy ".env.example" ".env" >nul
    echo  [OK] .env criado a partir do .env.example
  )
)
if exist ".env" (
  for /f "tokens=2* delims==" %%k in ('findstr "YT_API_KEY" .env') do set "KEY=%%k"
) else (
  echo  [ERRO] arquivo .env nao encontrado.
)
if not defined KEY (
  echo.
  echo  [ATENCAO] a YT_API_KEY esta VAZIA no .env
  echo             Abra o arquivo ".env" e cole sua chave da YouTube Data API v3,
  echo             senao nenhum canal sera listado.
)
if not defined KEY (
  echo.
  pause
  exit /b 1
)

REM ================================================================ DEPENDENCIAS
%PY% -c "import fastapi, uvicorn, httpx" >nul 2>nul
if errorlevel 1 (
  echo  [..] instalando dependencias ^(so na primeira vez^)...
  %PY% -m pip install --quiet --disable-pip-version-check -r requirements.txt
  if errorlevel 1 (
    echo.
    echo  [ERRO] nao foi possivel instalar as dependencias.
    echo  Tente manualmente:  %PY% -m pip install -r requirements.txt
    echo.
    pause
    exit /b 1
  )
  echo  [OK] dependencias instaladas.
)

REM ================================================================ PORTA
set "PORTA=8000"
if not "%~1"=="" set "PORTA=%~1"
%PY% -c "import socket,sys;s=socket.socket();s.settimeout(2);r=s.connect_ex(('127.0.0.1',!PORTA!));s.close();sys.exit(0 if r else 1)" >nul 2>nul
if errorlevel 1 (
  echo.
  echo  [ERRO] a porta !PORTA! ja esta em uso.
  echo  Feche o outro programa ou use outra porta:  iniciar.bat 8080
  echo.
  pause
  exit /b 1
)

REM ================================================================ ENDERECO NA REDE
REM descobre pelo proprio Python (UDP connect so escolhe a rota, nao envia nada)
set "IP="
for /f "delims=" %%i in ('%PY% -c "import socket;s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);s.connect(('8.8.8.8',80));print(s.getsockname()[0]);s.close()" 2^>nul') do set "IP=%%i"
if not defined IP (
  for /f "delims=" %%i in ('%PY% -c "import socket;print(socket.gethostbyname(socket.gethostname()))" 2^>nul') do set "IP=%%i"
)

echo.
echo  --------------------------------------------------
echo   PAINEL   : http://localhost:!PORTA!/
if defined IP echo   NA REDE : http://!IP!:!PORTA!/
echo.
echo   usuario: admin
echo   na primeira execucao a senha aparece logo abaixo.
echo   para trocar a senha:  iniciar.bat senha MINHASENHA
echo.
echo   para PARAR o servidor: Ctrl+C  (ou feche esta janela)
echo  --------------------------------------------------
echo.

start "" /b cmd /c "timeout /t 5 >nul & start "" http://localhost:!PORTA!/" >nul 2>&1

%PY% run.py !PORTA!
set "RC=!ERRORLEVEL!"

echo.
if not "!RC!"=="0" (
  echo  [ERRO] o servidor terminou com codigo !RC! - veja a mensagem acima.
) else (
  echo  Servidor encerrado.
)
echo.
pause
endlocal
