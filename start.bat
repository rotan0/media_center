@echo off
REM Local Media Center (console launcher). Double-click "Media Center.hta" for the normal launcher.
REM Uses media_center\tools\php (portable). If no PHP exists anywhere it is downloaded automatically.
cd /d "%~dp0"
set "PHP="
set "PHPARGS="
if not exist "tools\php\php.exe" (
  where php >nul 2>nul && set "PHP=php"
  if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
)
if not defined PHP if not exist "tools\php\php.exe" (
  echo Downloading PHP into media_center\tools\php ...
  if not exist tools mkdir tools
  curl.exe -L -f -o "tools\_php.zip" "https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip" || curl.exe -L -f -o "tools\_php.zip" "https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip"
  if not exist "tools\php" mkdir "tools\php"
  tar.exe -xf "tools\_php.zip" -C "tools\php" && del "tools\_php.zip"
)
if exist "tools\php\php.exe" (
  set "PHP=%~dp0tools\php\php.exe"
  set PHPARGS=-d extension_dir="%~dp0tools\php\ext" -d extension=curl -d extension=openssl -d extension=zip -d extension=mbstring -d extension=fileinfo
  if exist "tools\php\php.ini" set PHPARGS=-c "%~dp0tools\php\php.ini" -d extension_dir="%~dp0tools\php\ext"
)
if not defined PHP (
  echo PHP could not be found or downloaded. Check the internet connection, or copy a PHP folder to media_center\tools\php
  pause
  exit /b 1
)
set PORT=8080
start "" http://127.0.0.1:%PORT%/index.php
"%PHP%" %PHPARGS% -S 127.0.0.1:%PORT% -t "%~dp0."