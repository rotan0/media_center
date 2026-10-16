Write-Host ""
Write-Host "============================================================"
Write-Host "   GIT FORCE-PUSH WITH ANIMATED PROGRESS + LIVE STDERR"
Write-Host "============================================================`n"

# Check remote
Write-Host "[1/6] Checking remote 'origin'..."
$remote = git remote -v | Select-String "origin"
if (-not $remote) {
    Write-Host "Remote 'origin' missing. Adding it..."
    git remote add origin https://github.com/rotan0/offline-video-app.git
}
Write-Host "Remote OK.`n"

# Timer
$start = Get-Date
Write-Host "[2/6] Starting push..."

Write-Host "[3/6] Pushing to GitHub..."
Write-Host "------------------------------------------------------------"

# Prepare process
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = "git"
$psi.Arguments = "push origin main --force --progress"
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError = $true
$psi.UseShellExecute = $false
$psi.CreateNoWindow = $true

$proc = New-Object System.Diagnostics.Process
$proc.StartInfo = $psi
$proc.Start() | Out-Null

# Spinner frames
$frames = @("|","/","-","\")

# Animated progress bar
function Draw-Bar($percent) {
    $width = 40
    $filled = [int]($percent / (100 / $width))
    $empty = $width - $filled
    return ("[" + ("#" * $filled) + ("-" * $empty) + "] $percent%")
}

# Live loop
$percent = 0
$i = 0

while (-not $proc.HasExited) {

    # Read stderr line (Git progress)
    $line = $proc.StandardError.ReadLine()

    if ($line) {
        Add-Content -Path push_output.log -Value $line

        # Extract percent
        if ($line -match "([0-9]+)%") {
            $percent = [int]$matches[1]
        }

        # Extract ETA
        if ($line -match "ETA ([0-9]+)s") {
            $eta = $matches[1]
        }
    }

    # Draw animated spinner + bar
    $spinner = $frames[$i % $frames.Count]
    $bar = Draw-Bar $percent

    Write-Host "`r$spinner $bar" -NoNewline

    Start-Sleep -Milliseconds 120
    $i++
}

# Flush remaining stdout
while (($line = $proc.StandardOutput.ReadLine()) -ne $null) {
    Add-Content -Path push_output.log -Value $line
}

Write-Host "`n------------------------------------------------------------`n"

# End timer
$end = Get-Date
$elapsed = ($end - $start).TotalSeconds

Write-Host "[4/6] Push completed."
Write-Host "Time elapsed: $elapsed seconds`n"

Write-Host "[5/6] Push Summary:"
Select-String "%" push_output.log | ForEach-Object { Write-Host $_ }

Write-Host ""
Write-Host "[6/6] DONE."
Write-Host "Your repository is now force-pushed with full animated progress."
Write-Host "============================================================`n"

Read-Host "Press ENTER to exit"

