#Requires -Version 5.1
# ÁTICA Calidad - arranque con datos de demostración (Windows PowerShell)
# Carga el centro de demostración (IES Ada Lovelace) y arranca la aplicación.
# Uso: .\demo.ps1 [-Port 8080]
$env:LOAD_DEMO_DATA = "true"
& (Join-Path $PSScriptRoot "start.ps1") @args
