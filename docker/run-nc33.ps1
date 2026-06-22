# NC33 Testinstanz starten (SSH-kompatibel, ohne Windows Credential Store)
$configDir = "$env:USERPROFILE\docker-ssh-config"
New-Item -ItemType Directory -Force -Path $configDir | Out-Null
[System.IO.File]::WriteAllText("$configDir\config.json", '{"auths":{},"credsStore":""}', [System.Text.Encoding]::ASCII)

$env:DOCKER_CONFIG = $configDir
docker --context default compose -f "$env:USERPROFILE\docker-nc33.yml" up -d
