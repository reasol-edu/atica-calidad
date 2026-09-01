#Requires -Version 5.1
# ÁTICA Calidad - script de arranque (Windows PowerShell)
# Uso: .\start.ps1 [-Port 8080]
param(
    [int]$Port = $(if ($env:PORT) { [int]$env:PORT } else { 8080 })
)

$ErrorActionPreference = "Stop"

# -- Rutas -----------------------------------------------------------------------
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Data = Join-Path $Root "data"
$App  = Join-Path $Root "app"
$FP   = Join-Path $Root "frankenphp.exe"

# -- Variables de entorno --------------------------------------------------------
$env:APP_ENV       = "prod"
$env:APP_DEBUG     = "0"
$env:DOCUMENT_ROOT = Join-Path $App "public"
$env:SERVER_ADDR   = ":$Port"

# SQLite necesita barras hacia delante en la URL
$dataFwd = $Data -replace "\\", "/"
$env:DATABASE_URL  = "sqlite:///$dataFwd/atica-calidad.db"

$env:MIGRATIONS_PATH = "migrations/sqlite"
$env:DEFAULT_URI = "http://localhost:$Port"
if (-not $env:APP_LOG)                     { $env:APP_LOG = "false" }
if (-not $env:APP_LOG_RETENTION_DAYS)      { $env:APP_LOG_RETENTION_DAYS = "90" }
if (-not $env:APP_EXTERNAL_ENABLED)        { $env:APP_EXTERNAL_ENABLED = "true" }
if (-not $env:APP_EXTERNAL_URL)            { $env:APP_EXTERNAL_URL = "https://seneca.juntadeandalucia.es/seneca/jsp/ComprobarUsuarioExt.jsp" }
if (-not $env:APP_EXTERNAL_URL_FORCE_SECURITY) { $env:APP_EXTERNAL_URL_FORCE_SECURITY = "true" }
if (-not $env:MAILER_DSN)                  { $env:MAILER_DSN = "null://null" }
if (-not $env:MAILER_FROM)                 { $env:MAILER_FROM = "no-responder@example.com" }
if (-not $env:MESSENGER_TRANSPORT_DSN)     { $env:MESSENGER_TRANSPORT_DSN = "doctrine://default?auto_setup=0" }

# -- Carpeta de datos ------------------------------------------------------------
New-Item -ItemType Directory -Force -Path $Data | Out-Null

# -- APP_SECRET: generar en el primer arranque -----------------------------------
$SecretFile = Join-Path $Data ".secret"
if (-not (Test-Path $SecretFile)) {
    Write-Host "Generando APP_SECRET..."
    $secret = & $FP php-cli -r 'echo bin2hex(random_bytes(32));' 2>$null
    $secret | Set-Content -Path $SecretFile -NoNewline -Encoding ascii
}
$env:APP_SECRET = (Get-Content $SecretFile -Raw -Encoding ascii).Trim()

# -- .env: exponer variables a PHP ----------------------------------------------
# Set-Content -Encoding utf8 escribe BOM en PS 5.x; Symfony no admite BOM.
$envContent = @"
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$($env:APP_SECRET)
DATABASE_URL=$($env:DATABASE_URL)
MIGRATIONS_PATH=$($env:MIGRATIONS_PATH)
DEFAULT_URI=$($env:DEFAULT_URI)
APP_LOG=$($env:APP_LOG)
APP_LOG_RETENTION_DAYS=$($env:APP_LOG_RETENTION_DAYS)
APP_EXTERNAL_ENABLED=$($env:APP_EXTERNAL_ENABLED)
APP_EXTERNAL_URL=$($env:APP_EXTERNAL_URL)
APP_EXTERNAL_URL_FORCE_SECURITY=$($env:APP_EXTERNAL_URL_FORCE_SECURITY)
MAILER_DSN=$($env:MAILER_DSN)
MAILER_FROM=$($env:MAILER_FROM)
MESSENGER_TRANSPORT_DSN=$($env:MESSENGER_TRANSPORT_DSN)
"@
[System.IO.File]::WriteAllText((Join-Path $App ".env"), $envContent, [System.Text.UTF8Encoding]::new($false))

# -- Caché: limpiar posibles compilaciones parciales de arranques anteriores -----
$CacheDir = Join-Path $App "var\cache"
if (Test-Path $CacheDir) { Remove-Item -Recurse -Force $CacheDir }

Push-Location $App
try {
    # -- Precalentar caché (compila el contenedor DI correctamente) -------------
    Write-Host "Precalentando cache. Espere por favor..."
    & $FP php-cli bin/console cache:warmup --no-interaction

    # -- Base de datos SQLite ---------------------------------------------------
    Write-Host "Aplicando migraciones..."
    & $FP php-cli bin/console doctrine:migrations:migrate --no-interaction

    # -- Datos iniciales: el centro de demostración (IES Ada Lovelace) o, si no
    # se pide, solo la cuenta admin/admin por defecto de app:setup. Son
    # mutuamente excluyentes: app:load-demo-data falla si el usuario "admin"
    # ya existe, así que nunca se llama a app:setup primero cuando se cargan
    # datos de demo.
    if ($env:LOAD_DEMO_DATA -eq "true") {
        Write-Host "Cargando datos de demostración..."
        & $FP php-cli bin/console app:load-demo-data --no-interaction
    } else {
        Write-Host "Inicializando datos por defecto..."
        try { & $FP php-cli bin/console app:setup --no-interaction } catch {}
    }
} finally {
    Pop-Location
}

# -- Worker de Messenger (envío de emails en segundo plano) ---------------------
# FrankenPHP es monoproceso y el Caddyfile no puede supervisar workers. Lanzamos
# el consumidor como proceso aparte y lo detenemos al cerrar el servidor.
$Worker = Start-Process -FilePath $FP `
    -ArgumentList @("php-cli", "bin/console", "messenger:consume", "async", "scheduler_default", "--memory-limit=128M", "--quiet") `
    -WorkingDirectory $App -PassThru -NoNewWindow

# -- Arrancar servidor -----------------------------------------------------------
Set-Location $Root
Write-Host ""
Write-Host "  ÁTICA Calidad disponible en -> http://localhost:$Port"
Write-Host "  Pulsa Ctrl+C para detener."
Write-Host ""
try {
    & $FP run --config Caddyfile
} finally {
    if ($Worker -and -not $Worker.HasExited) {
        Stop-Process -Id $Worker.Id -Force -ErrorAction SilentlyContinue
    }
}
