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
if not exist ".env" if exist ".env.example" (
  copy ".env.example" ".env" >nul
  echo  [OK] .env criado a partir do .env.example
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

REM --- 1) o NOSSO app ja esta rodando nessa porta? (duplo clique repetido)
%PY% -c "import json,sys,urllib.request;d=json.load(urllib.request.urlopen('http://127.0.0.1:!PORTA!/healthz',timeout=4));sys.exit(0 if d.get('yt') else 1)" >nul 2>&1
if not errorlevel 1 goto :ja_rodando

REM --- 2) a porta esta ocupada por OUTRO programa?
REM     o teste sai com 0 quando CONSEGUE conectar (porta ocupada) e 1 quando falha (livre)
%PY% -c "import socket,sys;s=socket.socket();s.settimeout(2);r=s.connect_ex(('127.0.0.1',!PORTA!));s.close();sys.exit(1 if r else 0)" >nul 2>&1
if not errorlevel 1 goto :porta_ocupada
goto :servidor

REM ================================================================ JA ESTA NO AR
:ja_rodando
call :descobrir_ip
echo.
echo  --------------------------------------------------
echo   O app JA esta rodando. So abrindo o navegador.
echo.
echo   PAINEL   : http://localhost:!PORTA!/
if defined IP echo   NA REDE : http://!IP!:!PORTA!/
echo.
echo   Para PARAR o servidor: feche a janela onde ele foi
echo   iniciado, ou aperte Ctrl+C nela.
echo  --------------------------------------------------
echo.
start "" /b cmd /c "timeout /t 2 >nul & start "" http://localhost:!PORTA!/" >nul 2>&1
timeout /t 4 >nul
endlocal
exit /b 0

REM ================================================================ OUTRO PROGRAMA NA PORTA
:porta_ocupada
set "PIDP="
set "PROG="
for /f "tokens=5" %%p in ('netstat -ano -p tcp ^| findstr /c:":!PORTA! " ^| findstr "LISTENING"') do (
  if not defined PIDP set "PIDP=%%p"
)
if defined PIDP (
  for /f "tokens=1" %%n in ('tasklist /FI "PID eq !PIDP!" /NH 2^>nul') do (
    if not defined PROG set "PROG=%%n"
  )
)
set "OUTRA=8090"
for /f "delims=" %%p in ('%PY% -c "print(!PORTA!+1)"') do set "OUTRA=%%p"
echo.
echo  [ERRO] a porta !PORTA! ja esta em uso por outro programa.
if defined PROG echo          programa: !PROG! ^(pid !PIDP!^)
echo.
echo     O que fazer:
echo       1) fechar esse programa; ou
echo       2) usar outra porta:   iniciar.bat !OUTRA!
echo.
pause
endlocal
exit /b 1

REM ================================================================ SOBE O SERVIDOR
:servidor
call :descobrir_ip
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
exit /b !RC!

REM ================================================================ IP DA REDE
:descobrir_ip
set "IP="
for /f "delims=" %%i in ('%PY% -c "import socket;s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);s.connect(('8.8.8.8',80));print(s.getsockname()[0]);s.close()" 2^>nul') do set "IP=%%i"
if not defined IP (
  for /f "delims=" %%i in ('%PY% -c "import socket;print(socket.gethostbyname(socket.gethostname()))" 2^>nul') do set "IP=%%i"
)
goto :eof
