@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
cd /d "%~dp0.."

rem  Met a jour une installation EXISTANTE (poste client Laragon) vers la
rem  nouvelle version du code. Contrairement a installer.bat, ne touche ni au
rem  .env, ni a la cle de l'application, ni au referentiel, ni aux comptes :
rem  seulement le code, les dependances, les migrations et les caches.
rem  Une sauvegarde de la base est faite avant toute modification.

echo ============================================
echo  Mise a jour - 360inmystore
echo ============================================
echo.
echo IMPORTANT : lancez ce script depuis le Terminal Laragon
echo (Laragon ^> Menu ^> Terminal), pour que php/composer/npm soient reconnus.
echo.

where php >nul 2>nul
if errorlevel 1 (
    echo [ERREUR] php introuvable dans le PATH.
    goto :fin_erreur_avant
)
where composer >nul 2>nul
if errorlevel 1 (
    echo [ERREUR] composer introuvable dans le PATH.
    goto :fin_erreur_avant
)
where npm >nul 2>nul
if errorlevel 1 (
    echo [ERREUR] npm introuvable dans le PATH.
    goto :fin_erreur_avant
)

rem  Une installation existe-t-elle ? Sinon c'est installer.bat qu'il faut.
if not exist .env (
    echo [ERREUR] Aucun fichier .env : l'application n'est pas encore installee ici.
    echo Lancez scripts\installer.bat pour une premiere installation.
    goto :fin_erreur_avant
)
set APP_KEY=
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
    if "%%A"=="APP_KEY" set APP_KEY=%%B
)
if "%APP_KEY%"=="" (
    echo [ERREUR] Le .env n'a pas de cle d'application : installation incomplete.
    echo Lancez scripts\installer.bat pour une premiere installation.
    goto :fin_erreur_avant
)

echo [1/8] Recuperation de la nouvelle version...
if exist .git (
    where git >nul 2>nul
    if errorlevel 1 (
        echo [ERREUR] git introuvable dans le PATH.
        goto :fin_erreur_avant
    )
    for /f "delims=" %%B in ('git rev-parse --abbrev-ref HEAD') do set BRANCHE=%%B
    echo Branche : !BRANCHE!
    call git pull --ff-only
    if errorlevel 1 (
        echo [ERREUR] Impossible de recuperer la nouvelle version : le dossier a des
        echo modifications locales, ou n'est pas relie au depot. Rien n'a ete modifie.
        goto :fin_erreur_avant
    )
) else (
    echo Ce dossier n'est pas un depot git : la nouvelle version doit deja avoir
    echo ete copiee par-dessus ^(sans ecraser .env ni storage^).
    choice /c ON /m "La nouvelle version est-elle bien copiee ? (O = oui, N = arreter)"
    if errorlevel 2 goto :fin_erreur_avant
)

echo.
echo [2/8] Sauvegarde de la base avant la mise a jour...
call php artisan app:sauvegarder
if errorlevel 1 (
    echo [ATTENTION] La sauvegarde a echoue ^(mysqldump introuvable ?^).
    choice /c ON /m "Continuer SANS sauvegarde ? (O = oui, N = arreter)"
    if errorlevel 2 goto :fin_erreur_avant
)

echo.
echo [3/8] Application en maintenance...
call php artisan down

rem  Les caches de l'ancienne version (configuration, routes, vues) sont
rem  effaces : la suite doit lire la configuration de la nouvelle.
call php artisan optimize:clear
if errorlevel 1 goto :fin_erreur

echo.
echo [4/8] Dependances PHP...
call composer install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 goto :fin_erreur

echo.
echo [5/8] Compilation des styles et scripts...
call npm install --no-audit --no-fund --prefer-offline
if errorlevel 1 goto :fin_erreur
call npm run build
if errorlevel 1 goto :fin_erreur

echo.
echo [6/8] Migration de la base de donnees...
call php artisan migrate --force
if errorlevel 1 goto :fin_erreur

echo.
echo [7/8] Permissions, miniatures des photos...
call php artisan app:apres-mise-a-jour
if errorlevel 1 goto :fin_erreur

echo.
echo [8/8] Optimisation (cache config/routes/vues)...
call php artisan config:cache
if errorlevel 1 goto :fin_erreur
call php artisan route:cache
if errorlevel 1 goto :fin_erreur
call php artisan view:cache
if errorlevel 1 goto :fin_erreur
call php artisan storage:link >nul 2>nul

call php artisan up

echo.
echo ============================================
echo  Mise a jour terminee
echo ============================================
pause
goto :eof

:fin_erreur
echo.
echo Mise a jour interrompue suite a une erreur ^(voir le message ci-dessus^).
echo L'application est restee EN MAINTENANCE. Une sauvegarde de la base a ete
echo faite a l'etape 2. Une fois le probleme corrige, relancez ce script ;
echo pour simplement rouvrir l'application : php artisan up
pause
exit /b 1

:fin_erreur_avant
echo.
echo Mise a jour annulee : rien n'a ete modifie.
pause
exit /b 1
