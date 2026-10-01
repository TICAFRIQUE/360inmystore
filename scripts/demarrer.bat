@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0.."

set APP_URL=
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
    if "%%A"=="APP_URL" set APP_URL=%%B
)
if not defined APP_URL set APP_URL=http://localhost

echo ============================================
echo  Demarrage - 360inmystore
echo ============================================

tasklist /fi "imagename eq httpd.exe" 2>nul | find /i "httpd.exe" >nul
if errorlevel 1 (
    if exist "C:\laragon\laragon.exe" (
        echo Lancement de Laragon ^(minimise^)...
        start /min "" "C:\laragon\laragon.exe"
        call :attendre_apache
    ) else (
        echo [ATTENTION] Laragon introuvable a l'emplacement par defaut C:\laragon.
        echo Demarrez Apache et MySQL manuellement.
    )
) else (
    echo Apache deja demarre.
)

echo Ouverture de l'application : %APP_URL%
start "" "%APP_URL%"
goto :eof

:attendre_apache
set COMPTEUR=0
:boucle_attente_apache
ping -n 2 127.0.0.1 >nul
set /a COMPTEUR+=1
tasklist /fi "imagename eq httpd.exe" 2>nul | find /i "httpd.exe" >nul
if errorlevel 1 if %COMPTEUR% lss 20 goto :boucle_attente_apache
goto :eof
