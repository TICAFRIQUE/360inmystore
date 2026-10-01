@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
cd /d "%~dp0.."
set PROJET_DIR=%~dp0..\

echo ============================================
echo  Installation - 360inmystore
echo ============================================
echo.
echo IMPORTANT : lancez ce script depuis le Terminal Laragon
echo (Laragon ^> Menu ^> Terminal), pour que php/composer/mysql/npm
echo soient reconnus. Fermez cette fenetre et relancez depuis ce
echo terminal si besoin.
echo.
pause

where php >nul 2>nul
if errorlevel 1 (
    echo [ERREUR] php introuvable dans le PATH.
    goto :fin_erreur
)
where composer >nul 2>nul
if errorlevel 1 (
    echo [ERREUR] composer introuvable dans le PATH.
    goto :fin_erreur
)
where npm >nul 2>nul
if errorlevel 1 (
    echo [ERREUR] npm introuvable dans le PATH.
    goto :fin_erreur
)

set MYSQL_DISPONIBLE=1
where mysql >nul 2>nul
if errorlevel 1 (
    echo [ATTENTION] client mysql introuvable dans le PATH : la base devra
    echo etre creee manuellement ^(HeidiSQL / phpMyAdmin^).
    set MYSQL_DISPONIBLE=0
)

echo.
set /p APP_URL_SAISIE="URL de l'application (ex: http://360_inmystore.test) : "
set /p DB_NAME="Nom de la base de donnees [360inmystore] : "
if "%DB_NAME%"=="" set DB_NAME=360inmystore
set /p DB_USER="Utilisateur MySQL [root] : "
if "%DB_USER%"=="" set DB_USER=root
set /p DB_PASS="Mot de passe MySQL (laisser vide si aucun) : "

echo.
echo [1/9] Installation des dependances PHP...
call composer install --no-dev --optimize-autoloader
if errorlevel 1 goto :fin_erreur

echo.
echo [2/9] Configuration de l'environnement...
if not exist .env (
    copy .env.example .env >nul
)
call php artisan key:generate --force
if errorlevel 1 goto :fin_erreur

set DB_PASS_ARG=%DB_PASS%
if "%DB_PASS%"=="" set DB_PASS_ARG=__NOPASS__
powershell -NoProfile -ExecutionPolicy Bypass -File "scripts\configurer-env.ps1" -AppUrl "%APP_URL_SAISIE%" -DbName "%DB_NAME%" -DbUser "%DB_USER%" -DbPass "%DB_PASS_ARG%"
if errorlevel 1 goto :fin_erreur

echo.
echo [3/9] Creation de la base de donnees...
if "%MYSQL_DISPONIBLE%"=="1" (
    if "%DB_PASS%"=="" (
        mysql -u %DB_USER% -e "CREATE DATABASE IF NOT EXISTS `%DB_NAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    ) else (
        mysql -u %DB_USER% -p%DB_PASS% -e "CREATE DATABASE IF NOT EXISTS `%DB_NAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    )
    if errorlevel 1 (
        echo [ERREUR] Impossible de creer la base. Verifiez les identifiants MySQL.
        goto :fin_erreur
    )
) else (
    echo Creez manuellement la base "%DB_NAME%" ^(utf8mb4_unicode_ci^), puis continuez.
    pause
)

echo.
echo [4/9] Migration de la base de donnees...
call php artisan migrate --force
if errorlevel 1 goto :fin_erreur

echo.
echo [5/9] Chargement du referentiel (roles, permissions, unites, taxes, moyens de paiement)...
call php artisan db:seed --force
if errorlevel 1 goto :fin_erreur

echo.
echo [6/9] Installation et compilation des assets...
call npm install
if errorlevel 1 goto :fin_erreur
call npm run build
if errorlevel 1 goto :fin_erreur

echo.
echo [7/9] Lien de stockage (images produits)...
call php artisan storage:link

echo.
echo [8/9] Optimisation (cache config/routes/vues)...
call php artisan config:cache
call php artisan route:cache
call php artisan view:cache

echo.
echo [9/9] Planification des alertes automatiques (stock sous seuil, sessions ouvertes)...
set PHP_EXE=
for /f "delims=" %%P in ('where php 2^>nul') do (
    if not defined PHP_EXE set PHP_EXE=%%P
)
if not defined PHP_EXE set PHP_EXE=php.exe

powershell -NoProfile -ExecutionPolicy Bypass -File "scripts\creer-tache-planifiee.ps1" -PhpExe "%PHP_EXE%" -ArtisanPath "%PROJET_DIR%artisan"
if errorlevel 1 (
    echo [ATTENTION] Tache planifiee non creee automatiquement ^(relancez ce
    echo script en tant qu'administrateur, ou creez-la a la main dans le
    echo Planificateur de taches Windows^) :
    echo   Programme  : %PHP_EXE%
    echo   Arguments  : "%PROJET_DIR%artisan" schedule:run
    echo   Frequence  : chaque minute
)

echo.
echo ============================================
echo  Compte superadmin
echo ============================================
echo Creation du premier compte de connexion ^(nom d'utilisateur + code PIN^).

:creer_superadmin
call php artisan app:creer-superadmin
if errorlevel 1 (
    echo.
    echo [ATTENTION] Le compte n'a pas ete cree ^(identifiant deja utilise, ou
    echo les deux codes saisis ne correspondaient pas ^- ils sont masques a la
    echo saisie^). On reessaie.
    echo.
    goto :creer_superadmin
)

echo.
echo ============================================
echo  Installation terminee
echo ============================================
echo N'oubliez pas, dans Laragon :
echo   - creer un virtual host pointant vers le dossier "public" de ce projet
echo   - verifier qu'Apache et MySQL sont demarres (ou installes comme
echo     services Windows pour un demarrage automatique au boot)
echo.
echo URL configuree : %APP_URL_SAISIE%
pause
goto :eof

:fin_erreur
echo.
echo Installation interrompue suite a une erreur.
pause
exit /b 1
