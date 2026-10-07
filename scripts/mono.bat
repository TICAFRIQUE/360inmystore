@echo off
setlocal
cd /d "%~dp0.."

rem ============================================================
rem  Revient a la version installee (branche mono_client) apres
rem  un essai de la plateforme SaaS : bascule de branche, vide les
rem  caches et recompile les assets. Le site local habituel
rem  (Laragon) est alors de nouveau a jour.
rem
rem  Usage :   scripts\mono
rem
rem  Ce fichier est identique sur les deux branches : il continue
rem  donc de s'executer normalement apres le changement de branche.
rem ============================================================

echo ============================================
echo  360inmystore - version installee (local)
echo ============================================
echo.

echo [1/3] Branche mono_client...
git checkout mono_client
if errorlevel 1 goto :erreur

echo.
echo [2/3] Caches...
call php artisan optimize:clear >nul

echo.
echo [3/3] Compilation des assets...
call npm run build
if errorlevel 1 goto :erreur

echo.
echo Pret : la version installee est de nouveau servie par votre site local.
goto :eof

:erreur
echo.
echo Retour interrompu suite a une erreur (voir le message ci-dessus).
exit /b 1
