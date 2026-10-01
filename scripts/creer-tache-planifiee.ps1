<#
    (Re)cree la tache planifiee Windows qui execute "php artisan schedule:run"
    chaque minute (alertes stock sous seuil / sessions ouvertes trop longtemps).

    Appelle php.exe via un wrapper .vbs (WScript.Shell.Run avec fenetre = 0) :
    lance directement php.exe depuis le Planificateur de taches affiche une
    fenetre console noire chaque minute sur le poste du client (comportement
    par defaut de Windows pour un executable console lance en session
    interactive) - le wrapper l'execute en tache de fond, sans fenetre.
#>
param(
    [Parameter(Mandatory = $true)][string]$PhpExe,
    [Parameter(Mandatory = $true)][string]$ArtisanPath
)

$ErrorActionPreference = "Stop"
$nomTache = "360inmystore_Schedule"
$scriptsDir = $PSScriptRoot
$vbsPath = Join-Path $scriptsDir "executer-schedule-silencieux.vbs"

$cmdLine = '"' + $PhpExe + '" "' + $ArtisanPath + '" schedule:run'
$cmdLineEchappee = $cmdLine -replace '"', '""'
$contenuVbs = 'Set objShell = CreateObject("WScript.Shell")' + "`r`n" +
              'objShell.Run "' + $cmdLineEchappee + '", 0, True'

Set-Content -LiteralPath $vbsPath -Value $contenuVbs -Encoding ASCII

$action = New-ScheduledTaskAction -Execute "wscript.exe" -Argument ('//B "' + $vbsPath + '"')
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -Hidden

Unregister-ScheduledTask -TaskName $nomTache -Confirm:$false -ErrorAction SilentlyContinue

Register-ScheduledTask -TaskName $nomTache -Action $action -Trigger $trigger -Settings $settings -RunLevel Limited | Out-Null

Write-Host "Tache planifiee '$nomTache' (re)creee : execution silencieuse chaque minute, aucune fenetre visible."
