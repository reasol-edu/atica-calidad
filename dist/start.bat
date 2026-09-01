@echo off
:: ÁTICA Calidad — script de arranque (Windows)
:: Uso: start.bat [puerto]          (por defecto: 8080)
setlocal enabledelayedexpansion

if "%~1"=="" ( set PORT=8080 ) else ( set PORT=%~1 )
if not "%PORT_ENV%"=="" set PORT=%PORT_ENV%

:: -- Rutas ------------------------------------------------------------------
set "ROOT=%~dp0"
set "ROOT=%ROOT:~0,-1%"
set "DATA=%ROOT%\data"
set "APP=%ROOT%\app"
set "FP=%ROOT%\frankenphp.exe"

:: -- Variables de entorno ----------------------------------------------------
set APP_ENV=prod
set APP_DEBUG=0
set DOCUMENT_ROOT=%APP%\public
set SERVER_ADDR=:%PORT%

:: SQLite necesita barras hacia delante en la URL
set "DATA_FWD=%DATA:\=/%"
set DATABASE_URL=sqlite:///%DATA_FWD%/atica-calidad.db

set MIGRATIONS_PATH=migrations/sqlite
set DEFAULT_URI=http://localhost:%PORT%
if "%APP_LOG%"==""                     set APP_LOG=false
if "%APP_LOG_RETENTION_DAYS%"==""      set APP_LOG_RETENTION_DAYS=90
if "%APP_EXTERNAL_ENABLED%"==""        set APP_EXTERNAL_ENABLED=true
if "%APP_EXTERNAL_URL%"==""            set APP_EXTERNAL_URL=https://seneca.juntadeandalucia.es/seneca/jsp/ComprobarUsuarioExt.jsp
if "%APP_EXTERNAL_URL_FORCE_SECURITY%"=="" set APP_EXTERNAL_URL_FORCE_SECURITY=true
if "%MAILER_DSN%"==""                  set MAILER_DSN=null://null
if "%MAILER_FROM%"==""                 set MAILER_FROM=no-responder@example.com
if "%MESSENGER_TRANSPORT_DSN%"==""     set MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

:: -- Carpeta de datos --------------------------------------------------------
if not exist "%DATA%" mkdir "%DATA%"

:: -- APP_SECRET: generar en el primer arranque --------------------------------
if not exist "%DATA%\.secret" (
    echo Generando APP_SECRET...
    "%FP%" php-cli -r "file_put_contents('%DATA_FWD%/.secret', bin2hex(random_bytes(32)));" 2>nul
)
set /p APP_SECRET=<"%DATA%\.secret"

:: -- .env: exponer variables a PHP --------------------------------------------
(
    echo APP_ENV=prod
    echo APP_DEBUG=0
    echo APP_SECRET=%APP_SECRET%
    echo DATABASE_URL=%DATABASE_URL%
    echo MIGRATIONS_PATH=%MIGRATIONS_PATH%
    echo DEFAULT_URI=%DEFAULT_URI%
    echo APP_LOG=%APP_LOG%
    echo APP_LOG_RETENTION_DAYS=%APP_LOG_RETENTION_DAYS%
    echo APP_EXTERNAL_ENABLED=%APP_EXTERNAL_ENABLED%
    echo APP_EXTERNAL_URL=%APP_EXTERNAL_URL%
    echo APP_EXTERNAL_URL_FORCE_SECURITY=%APP_EXTERNAL_URL_FORCE_SECURITY%
    echo MAILER_DSN=%MAILER_DSN%
    echo MAILER_FROM=%MAILER_FROM%
    echo MESSENGER_TRANSPORT_DSN=%MESSENGER_TRANSPORT_DSN%
) > "%APP%\.env"

:: -- Caché: limpiar posibles compilaciones parciales de arranques anteriores --
if exist "%APP%\var\cache" rmdir /s /q "%APP%\var\cache"

:: -- Precalentar caché (compila el contenedor DI correctamente) ---------------
cd /d "%APP%"
echo Precalentando cache. Espere por favor...
"%FP%" php-cli bin\console cache:warmup --no-interaction

:: -- Base de datos SQLite -----------------------------------------------------
echo Aplicando migraciones...
"%FP%" php-cli bin\console doctrine:migrations:migrate --no-interaction

:: -- Datos iniciales: el centro de demostracion (IES Ada Lovelace) o, si no
:: se pide, solo la cuenta admin/admin por defecto de app:setup. Son
:: mutuamente excluyentes: app:load-demo-data falla si el usuario "admin" ya
:: existe, asi que nunca se llama a app:setup primero cuando se cargan datos
:: de demo.
if /i "%LOAD_DEMO_DATA%"=="true" (
    echo Cargando datos de demostracion...
    "%FP%" php-cli bin\console app:load-demo-data --no-interaction
) else (
    echo Inicializando datos por defecto...
    "%FP%" php-cli bin\console app:setup --no-interaction
)

:: -- Worker de Messenger (envío de emails en segundo plano) -------------------
:: FrankenPHP es monoproceso; lanzamos el consumidor en segundo plano. Para una
:: gestión más robusta del ciclo de vida del worker, usa start.ps1 en Windows.
start "atica-calidad-worker" /b /d "%APP%" "%FP%" php-cli bin\console messenger:consume async scheduler_default --memory-limit=128M --quiet

:: -- Arrancar servidor --------------------------------------------------------
cd /d "%ROOT%"
echo.
echo   ATICA Calidad disponible en -^> http://localhost:%PORT%
echo   Pulsa Ctrl+C para detener.
echo.
"%FP%" run --config Caddyfile
