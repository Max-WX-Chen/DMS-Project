[CmdletBinding()]
param(
    [ValidateRange(1,65535)][int]$Port = 8091,
    [string]$Xampp = 'C:\xampp',
    [string]$ConfigPath,
    [string]$RuntimeDir
)
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if (!$ConfigPath) { $ConfigPath = Join-Path $project 'config/local.php' }
$connection = (Resolve-Path -LiteralPath $ConfigPath).Path.Replace('\','/')
$xamppDir = (Resolve-Path -LiteralPath $Xampp).Path.Replace('\','/')
$www = (Resolve-Path -LiteralPath (Join-Path $project 'public')).Path.Replace('\','/')
if (!$RuntimeDir) { $RuntimeDir = Join-Path ([IO.Path]::GetTempPath()) "form-club-apache-$Port" }
$state = (New-Item -ItemType Directory -Force -Path $RuntimeDir).FullName.Replace('\','/')
$httpd = Join-Path $xamppDir 'apache/bin/httpd.exe'
$serverConfig = Join-Path $state 'httpd.conf'
$configuration = @"
ServerRoot "$xamppDir/apache"
Listen 127.0.0.1:$Port
ServerName 127.0.0.1:$Port
PidFile "$state/apache.pid"
ErrorLog "$state/apache-error.log"
LogLevel warn
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule env_module modules/mod_env.so
LoadFile "$xamppDir/php/php8ts.dll"
LoadFile "$xamppDir/php/libpq.dll"
LoadFile "$xamppDir/php/libsqlite3.dll"
LoadModule php_module "$xamppDir/php/php8apache2_4.dll"
PHPINIDir "$xamppDir/php"
SetEnv FORM_CONFIG "$connection"
TypesConfig "$xamppDir/apache/conf/mime.types"
AddType text/javascript .js
DocumentRoot "$www"
DirectoryIndex index.php
<Directory />
    Require all denied
</Directory>
<Directory "$www">
    AllowOverride None
    Options None
    Require all granted
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
php_admin_value session.save_path "$state"
php_admin_flag display_errors Off
"@
[IO.File]::WriteAllText($serverConfig,$configuration,(New-Object Text.UTF8Encoding($false)))
$env:FORM_CONFIG = $connection
$env:PATH = "$xamppDir/php;$xamppDir/apache/bin;" + $env:PATH
& $httpd -t -f $serverConfig
if ($LASTEXITCODE -ne 0) { throw 'Apache rejected the generated configuration.' }
Write-Host "FORM Club http://127.0.0.1:$Port/  (Ctrl+C to stop)"
& $httpd -f $serverConfig
exit $LASTEXITCODE
