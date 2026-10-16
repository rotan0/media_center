@echo off
setlocal enabledelayedexpansion

title Media Center Cleanup with Progress Bar

echo.
echo ============================================================
echo        MEDIA CENTER CLEANUP WITH PROGRESS BAR
echo ============================================================
echo.

REM --- Directories containing media files ---
set DIRS=assets backdrops covers library thumbnails trailers backup share data

REM --- File extensions to delete (safe: media only) ---
set EXT=jpg jpeg png webp gif bmp mp4 mkv avi mov wmv flv m4v

REM --- Count total files first ---
set /a TOTAL=0

for %%D in (%DIRS%) do (
    for %%E in (%EXT%) do (
        for /f "delims=" %%F in ('dir /b /s "%%D\*.%%E" 2^>nul') do (
            set /a TOTAL+=1
        )
    )
)

if %TOTAL%==0 (
    echo No media files found. Nothing to clean.
    pause
    exit /b
)

echo Found %TOTAL% media files to remove.
echo Starting cleanup...
echo.

REM --- Progress bar setup ---
set /a COUNT=0
set BAR_WIDTH=40

REM --- Delete files with progress ---
for %%D in (%DIRS%) do (
    for %%E in (%EXT%) do (
        for /f "delims=" %%F in ('dir /b /s "%%D\*.%%E" 2^>nul') do (

            del "%%F" >nul 2>&1
            set /a COUNT+=1

            REM --- Calculate percent ---
            set /a PCT=(COUNT*100)/TOTAL

            REM --- Draw bar ---
            set /a FILLED=(PCT*BAR_WIDTH)/100
            set /a EMPTY=BAR_WIDTH-FILLED

            set BAR=
            for /l %%i in (1,1,!FILLED!) do set BAR=!BAR!#
            for /l %%i in (1,1,!EMPTY!) do set BAR=!BAR!-

            <nul set /p "=!BAR! !PCT!%%  (deleted !COUNT! of !TOTAL!)`r"
        )
    )
)

echo.
echo ------------------------------------------------------------
echo Cleanup complete.
echo Removed %COUNT% media files.
echo ------------------------------------------------------------
echo.

pause

