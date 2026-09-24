$ErrorActionPreference = 'Stop'
$BaseUrl = '__RESTOCLOUD_AGENT_BASE_URL__'
$InstallDir = Join-Path $env:ProgramData 'RestoCloud\PrintAgent'

Write-Host 'Instalando RestoCloud Print Agent...' -ForegroundColor Cyan
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null

try { node --version | Out-Null } catch {
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        winget install --id OpenJS.NodeJS.LTS --exact --silent --accept-package-agreements --accept-source-agreements
    } else {
        throw 'Node.js no esta instalado y winget no esta disponible. Instala Node.js LTS y vuelve a ejecutar este instalador.'
    }
}

Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/server.js" -OutFile (Join-Path $InstallDir 'server.js')
Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/package.json" -OutFile (Join-Path $InstallDir 'package.json')

$node = (Get-Command node -ErrorAction Stop).Source
$taskName = 'RestoCloud Print Agent'
$action = New-ScheduledTaskAction -Execute $node -Argument ('"{0}"' -f (Join-Path $InstallDir 'server.js'))
$trigger = New-ScheduledTaskTrigger -AtLogOn
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $taskName

Write-Host 'Agente instalado y ejecutandose.' -ForegroundColor Green
Write-Host 'Puedes volver a RestoCloud y pulsar Actualizar impresoras.'
Read-Host 'Presiona Enter para cerrar'
