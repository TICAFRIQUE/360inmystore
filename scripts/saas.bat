@echo off
setlocal
cd /d "%~dp0.."

rem ============================================================
rem  Lance la plateforme SaaS en local, en une seule commande :
rem  bascule sur la branche multi-tenant, met a jour la base de
rem  la plateforme et celle de chaque entreprise, compile les
rem  assets, puis demarre le serveur.
rem
rem  Usage :   scripts\saas
rem  Retour :  Ctrl+C, puis  scripts\mono
rem
rem  Ce fichier est identique sur les deux branches : il continue
rem  donc de s'executer normalement apres le changement de branche.
rem
rem  Reglages facultatifs (a definir avant de lancer) :
rem    SAAS_DB    base de la plateforme   [360inmystore_plateforme]
rem    SAAS_PORT  port du serveur local   [8000]
rem ============================================================

if not defined SAAS_DB set SAAS_DB=360inmystore_plateforme
if not defined SAAS_PORT set SAAS_PORT=8000

echo ============================================
echo  360inmystore - plateforme SaaS (local)
echo  Base : %SAAS_DB%    Port : %SAAS_PORT%
echo ============================================
echo.

echo [1/7] Arret d'un ancien serveur de developpement sur le port %SAAS_PORT%...
rem Deux serveurs sur le meme port : c'est l'ancien qui repond, avec son
rem ancienne configuration. On n'arrete que php.exe, rien d'autre.
for /f "tokens=5" %%P in ('netstat -ano ^| findstr LISTENING ^| findstr /c:":%SAAS_PORT% "') do (
    tasklist /fi "PID eq %%P" 2>nul | find /i "php.exe" >nul
    if not errorlevel 1 taskkill /PID %%P /F >nul 2>nul
)

echo [2/7] Branche multi-tenant...
git checkout multi-tenant
if errorlevel 1 goto :erreur

rem Variable limitee a cette fenetre de commande (setlocal) : le fichier
rem .env et le site local habituel ne sont pas modifies.
set DB_DATABASE=%SAAS_DB%

echo.
echo [3/7] Base de la plateforme...
call php artisan optimize:clear >nul
call php artisan migrate --force
if errorlevel 1 goto :erreur
call php artisan db:seed --class=ReferentielSeeder --force
if errorlevel 1 goto :erreur

echo.
echo [4/7] Compte de l'editeur...
call php artisan tinker --execute="echo App\Models\User::exists() ? 'COMPTE_OK' : 'AUCUN_COMPTE';" | findstr AUCUN_COMPTE >nul
if not errorlevel 1 (
    echo Premier lancement : creation de votre compte d'editeur.
    call php artisan app:creer-superadmin
) else (
    echo Deja cree.
)

echo.
echo [5/7] Base de chaque entreprise...
call php artisan entreprises:executer -- migrate --force

echo.
echo [6/7] Compilation des assets...
call npm run build
if errorlevel 1 goto :erreur

echo.
echo [7/7] Demarrage du serveur.
echo.
echo   Plateforme :  http://localhost:%SAAS_PORT%
echo   Un espace  :  http://nom-de-l-espace.localhost:%SAAS_PORT%
echo.
echo   Ctrl+C pour arreter, puis  scripts\mono  pour revenir
echo   a la version installee.
echo.
rem --no-reload : sans cette option, le serveur ignore la base choisie ci-dessus.
call php artisan serve --port=%SAAS_PORT% --no-reload
goto :eof

:erreur
echo.
echo Lancement interrompu suite a une erreur (voir le message ci-dessus).
exit /b 1
