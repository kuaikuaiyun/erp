$ErrorActionPreference = "Stop"

$agentDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$outputDirectory = Join-Path $agentDirectory "release"
$workDirectory = Join-Path $agentDirectory "build"
$specDirectory = Join-Path $agentDirectory "build"

Push-Location $agentDirectory
try {
    py -3 -m pip install --upgrade requests pyinstaller
    py -3 -m PyInstaller `
        --noconfirm `
        --clean `
        --onefile `
        --console `
        --name "paypal_agent" `
        --collect-data "certifi" `
        --distpath $outputDirectory `
        --workpath $workDirectory `
        --specpath $specDirectory `
        "paypal_agent.py"

    Copy-Item -LiteralPath "config.example.json" -Destination (Join-Path $outputDirectory "config.json") -Force
    Copy-Item -LiteralPath "README.txt" -Destination (Join-Path $outputDirectory "README.txt") -Force

    Write-Host ""
    Write-Host "构建完成：$outputDirectory" -ForegroundColor Green
} finally {
    Pop-Location
}
