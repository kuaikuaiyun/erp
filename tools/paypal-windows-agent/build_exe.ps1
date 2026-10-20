$ErrorActionPreference = "Stop"

$agentDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$outputDirectory = Join-Path $agentDirectory "release"
$workDirectory = Join-Path $agentDirectory "build"
$specDirectory = Join-Path $agentDirectory "build"

Push-Location $agentDirectory
try {
    py -3.10 -m pip install --upgrade requests pyinstaller
    py -3.10 -m PyInstaller `
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

    $zipPath = Join-Path $outputDirectory "paypal-agent-windows-x64.zip"
    Compress-Archive -LiteralPath @(
        (Join-Path $outputDirectory "paypal_agent.exe"),
        (Join-Path $outputDirectory "config.json"),
        (Join-Path $outputDirectory "README.txt")
    ) -DestinationPath $zipPath -Force

    Write-Host ""
    Write-Host "构建完成：$outputDirectory" -ForegroundColor Green
} finally {
    Pop-Location
}
