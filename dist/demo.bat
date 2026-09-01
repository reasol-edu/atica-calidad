@echo off
:: ATICA Calidad - arranque con datos de demostracion (Windows)
:: Carga el centro de demostracion (IES Ada Lovelace) y arranca la aplicacion.
:: Uso: demo.bat [puerto]          (por defecto: 8080)
set LOAD_DEMO_DATA=true
call "%~dp0start.bat" %*
