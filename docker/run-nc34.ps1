# NC34 Testinstanz starten (SSH-kompatibel, ohne Windows Credential Store).
#
# Voraussetzung: `nextcloud:34` wurde EINMAL interaktiv gepullt (siehe nc34.yml-Header).
# `compose up` braucht dann keinen Pull mehr und läuft über SSH durch.
$configDir = "$env:USERPROFILE\docker-ssh-config"
New-Item -ItemType Directory -Force -Path $configDir | Out-Null
[System.IO.File]::WriteAllText("$configDir\config.json", '{"auths":{},"credsStore":""}', [System.Text.Encoding]::ASCII)

$env:DOCKER_CONFIG = $configDir
docker --context default compose -f "$env:USERPROFILE\docker-nc34.yml" up -d
