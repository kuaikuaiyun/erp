#requires -Version 3.0

param(
    [string]$ConfigPath = "",
    [switch]$TestImport
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"

$Script:AgentDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = Join-Path $Script:AgentDirectory "config.json"
}

$Script:PendingDirectory = Join-Path $Script:AgentDirectory "pending"
$Script:ArchiveDirectory = Join-Path $Script:AgentDirectory "archive"
$Script:FailedDirectory = Join-Path $Script:AgentDirectory "failed"
$Script:LogDirectory = Join-Path $Script:AgentDirectory "logs"
$Script:StateFile = Join-Path $Script:AgentDirectory "state.json"
$Script:Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$Script:LogFile = $null

$Script:PayPalClientId = ""
$Script:PayPalClientSecret = ""
$Script:PayPalMerchantId = ""
$Script:PayPalEnvironment = "live"
$Script:ErpImportUrl = ""
$Script:ErpImportSecret = ""
$Script:InitialSyncDays = 30
$Script:OverlapHours = 4
$Script:RequestTimeoutSeconds = 60
$Script:VerifyTls = $true
$Script:PayPalAccessToken = ""

function Initialize-AgentDirectories {
    foreach ($directory in @(
        $Script:PendingDirectory,
        $Script:ArchiveDirectory,
        $Script:FailedDirectory,
        $Script:LogDirectory
    )) {
        if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
            New-Item -ItemType Directory -Path $directory -Force | Out-Null
        }
    }
    $Script:LogFile = Join-Path $Script:LogDirectory ("paypal_agent_{0}.log" -f [DateTime]::UtcNow.ToString("yyyyMMdd"))
}

function Write-AgentLog {
    param(
        [Parameter(Mandatory = $true)][string]$Message,
        [ValidateSet("INFO", "WARN", "ERROR")][string]$Level = "INFO"
    )

    $line = "{0} [{1}] {2}" -f [DateTime]::Now.ToString("yyyy-MM-dd HH:mm:ss"), $Level, $Message
    Write-Host $line
    if (-not [string]::IsNullOrWhiteSpace($Script:LogFile)) {
        [System.IO.File]::AppendAllText($Script:LogFile, $line + [Environment]::NewLine, $Script:Utf8NoBom)
    }
}

function Get-ObjectProperty {
    param(
        [AllowNull()]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [AllowNull()]$Default = $null
    )

    if ($null -eq $Object) {
        return $Default
    }
    if ($Object -is [System.Collections.IDictionary]) {
        if ($Object.Contains($Name)) {
            return $Object[$Name]
        }
        return $Default
    }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) {
        return $Default
    }
    return $property.Value
}

function Get-ObjectArray {
    param([AllowNull()]$Value)

    if ($null -eq $Value) {
        return @()
    }
    return @($Value)
}

function Read-AgentConfig {
    param([Parameter(Mandatory = $true)][string]$Path)

    $resolvedPath = [System.IO.Path]::GetFullPath($Path)
    if (-not (Test-Path -LiteralPath $resolvedPath -PathType Leaf)) {
        throw "Config file not found: $resolvedPath"
    }

    try {
        $config = Get-Content -LiteralPath $resolvedPath -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        throw "Invalid config.json: $($_.Exception.Message)"
    }

    $Script:PayPalClientId = ([string](Get-ObjectProperty $config "paypal_client_id" "")).Trim()
    $Script:PayPalClientSecret = ([string](Get-ObjectProperty $config "paypal_client_secret" "")).Trim()
    $Script:PayPalMerchantId = ([string](Get-ObjectProperty $config "paypal_merchant_id" "")).Trim().ToUpperInvariant()
    $Script:PayPalEnvironment = ([string](Get-ObjectProperty $config "paypal_environment" "live")).Trim().ToLowerInvariant()
    $Script:ErpImportUrl = ([string](Get-ObjectProperty $config "erp_import_url" "")).Trim()
    $Script:ErpImportSecret = ([string](Get-ObjectProperty $config "erp_import_secret" "")).Trim()

    try {
        $Script:InitialSyncDays = [Convert]::ToInt32((Get-ObjectProperty $config "initial_sync_days" 30))
        $Script:OverlapHours = [Convert]::ToInt32((Get-ObjectProperty $config "overlap_hours" 4))
        $Script:RequestTimeoutSeconds = [Convert]::ToInt32((Get-ObjectProperty $config "request_timeout_seconds" 60))
    }
    catch {
        throw "initial_sync_days, overlap_hours and request_timeout_seconds must be integers."
    }

    $verifyTls = Get-ObjectProperty $config "verify_tls" $true
    if ($verifyTls -isnot [bool]) {
        throw "verify_tls must be true or false."
    }
    $Script:VerifyTls = [bool]$verifyTls
}

function Test-AgentConfig {
    param([bool]$RequirePayPalCredentials)

    $missing = New-Object System.Collections.Generic.List[string]
    if ([string]::IsNullOrWhiteSpace($Script:PayPalMerchantId)) { $missing.Add("paypal_merchant_id") }
    if ([string]::IsNullOrWhiteSpace($Script:ErpImportUrl)) { $missing.Add("erp_import_url") }
    if ([string]::IsNullOrWhiteSpace($Script:ErpImportSecret)) { $missing.Add("erp_import_secret") }
    if ($RequirePayPalCredentials) {
        if ([string]::IsNullOrWhiteSpace($Script:PayPalClientId)) { $missing.Add("paypal_client_id") }
        if ([string]::IsNullOrWhiteSpace($Script:PayPalClientSecret)) { $missing.Add("paypal_client_secret") }
    }
    if ($missing.Count -gt 0) {
        throw "Missing config values: $($missing -join ', ')"
    }
    if ($Script:PayPalEnvironment -notin @("live", "sandbox")) {
        throw "paypal_environment must be live or sandbox."
    }
    if ($Script:InitialSyncDays -lt 1 -or $Script:InitialSyncDays -gt 730) {
        throw "initial_sync_days must be between 1 and 730."
    }
    if ($Script:OverlapHours -lt 0 -or $Script:OverlapHours -gt 168) {
        throw "overlap_hours must be between 0 and 168."
    }
    if ($Script:RequestTimeoutSeconds -lt 10 -or $Script:RequestTimeoutSeconds -gt 600) {
        throw "request_timeout_seconds must be between 10 and 600."
    }

    $absoluteUri = $null
    if (-not [Uri]::TryCreate($Script:ErpImportUrl, [UriKind]::Absolute, [ref]$absoluteUri)) {
        throw "erp_import_url is invalid."
    }
}

function Enable-Tls12 {
    try {
        [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor ([System.Net.SecurityProtocolType]3072)
        [System.Net.ServicePointManager]::Expect100Continue = $false
        if ($Script:VerifyTls) {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $null
        }
        else {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
            Write-AgentLog "TLS certificate verification is disabled by config." "WARN"
        }
    }
    catch {
        throw "TLS 1.2 is unavailable. Install current Windows and .NET Framework updates."
    }
}

function Get-PayPalBaseUrl {
    if ($Script:PayPalEnvironment -eq "sandbox") {
        return "https://api-m.sandbox.paypal.com"
    }
    return "https://api-m.paypal.com"
}

function ConvertTo-IsoUtc {
    param([Parameter(Mandatory = $true)][DateTime]$Value)
    return $Value.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss'Z'", [Globalization.CultureInfo]::InvariantCulture)
}

function ConvertFrom-IsoDate {
    param([AllowNull()][string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) {
        return $null
    }
    try {
        return [DateTimeOffset]::Parse($Value, [Globalization.CultureInfo]::InvariantCulture).UtcDateTime
    }
    catch {
        return $null
    }
}

function ConvertTo-LowerHex {
    param([Parameter(Mandatory = $true)][byte[]]$Bytes)
    return ([BitConverter]::ToString($Bytes)).Replace("-", "").ToLowerInvariant()
}

function ConvertTo-QueryString {
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Parameters)

    $parts = New-Object System.Collections.Generic.List[string]
    foreach ($key in $Parameters.Keys) {
        $name = [Uri]::EscapeDataString([string]$key)
        $value = [Uri]::EscapeDataString([Convert]::ToString($Parameters[$key], [Globalization.CultureInfo]::InvariantCulture))
        $parts.Add("$name=$value")
    }
    return $parts -join "&"
}

function Invoke-RawHttpRequest {
    param(
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Uri,
        [System.Collections.IDictionary]$Headers = @{},
        [AllowNull()][byte[]]$Body = $null,
        [string]$ContentType = "application/json"
    )

    $request = [System.Net.HttpWebRequest]::Create($Uri)
    $request.Method = $Method
    $request.Accept = "application/json"
    $request.UserAgent = "KKY-PayPal-Windows-Agent-PowerShell/1.0"
    $request.Timeout = $Script:RequestTimeoutSeconds * 1000
    $request.ReadWriteTimeout = $Script:RequestTimeoutSeconds * 1000
    $request.AutomaticDecompression = [System.Net.DecompressionMethods]::GZip -bor [System.Net.DecompressionMethods]::Deflate

    foreach ($key in $Headers.Keys) {
        $value = [string]$Headers[$key]
        switch -Regex ($key) {
            "^Accept$" { $request.Accept = $value; continue }
            "^User-Agent$" { $request.UserAgent = $value; continue }
            "^Content-Type$" { $ContentType = $value; continue }
            default { $request.Headers[$key] = $value }
        }
    }

    if ($null -ne $Body) {
        $request.ContentType = $ContentType
        $request.ContentLength = $Body.Length
        $requestStream = $request.GetRequestStream()
        try {
            $requestStream.Write($Body, 0, $Body.Length)
        }
        finally {
            $requestStream.Dispose()
        }
    }

    $response = $null
    try {
        $response = [System.Net.HttpWebResponse]$request.GetResponse()
    }
    catch [System.Net.WebException] {
        if ($null -eq $_.Exception.Response) {
            throw
        }
        $response = [System.Net.HttpWebResponse]$_.Exception.Response
    }

    try {
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream(), [Text.Encoding]::UTF8)
        try {
            $responseText = $reader.ReadToEnd()
        }
        finally {
            $reader.Dispose()
        }
        return [PSCustomObject]@{
            StatusCode = [int]$response.StatusCode
            Body = $responseText
        }
    }
    finally {
        $response.Dispose()
    }
}

function ConvertFrom-JsonResponse {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if ($Response.StatusCode -lt 200 -or $Response.StatusCode -ge 300) {
        throw "$Label (HTTP $($Response.StatusCode)): $($Response.Body)"
    }
    try {
        return $Response.Body | ConvertFrom-Json
    }
    catch {
        throw "$Label returned invalid JSON: $($_.Exception.Message)"
    }
}

function Request-PayPalAccessToken {
    $pair = "$($Script:PayPalClientId):$($Script:PayPalClientSecret)"
    $basic = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pair))
    $body = [Text.Encoding]::UTF8.GetBytes("grant_type=client_credentials")
    $response = Invoke-RawHttpRequest -Method "POST" -Uri ((Get-PayPalBaseUrl) + "/v1/oauth2/token") -Headers @{
        "Authorization" = "Basic $basic"
    } -Body $body -ContentType "application/x-www-form-urlencoded"
    $json = ConvertFrom-JsonResponse $response "PayPal access token request failed"
    $token = ([string](Get-ObjectProperty $json "access_token" "")).Trim()
    if ([string]::IsNullOrWhiteSpace($token)) {
        throw "PayPal did not return an access token."
    }
    $Script:PayPalAccessToken = $token
}

function Invoke-PayPalGet {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$Parameters,
        [Parameter(Mandatory = $true)][string]$Label
    )

    $lastError = $null
    for ($attempt = 0; $attempt -lt 3; $attempt++) {
        try {
            if ([string]::IsNullOrWhiteSpace($Script:PayPalAccessToken)) {
                Request-PayPalAccessToken
            }
            $query = ConvertTo-QueryString $Parameters
            $uri = (Get-PayPalBaseUrl) + $Path
            if (-not [string]::IsNullOrWhiteSpace($query)) {
                $uri += "?" + $query
            }
            $response = Invoke-RawHttpRequest -Method "GET" -Uri $uri -Headers @{
                "Authorization" = "Bearer $($Script:PayPalAccessToken)"
            }

            if ($response.StatusCode -eq 401 -and $attempt -eq 0) {
                $Script:PayPalAccessToken = ""
                Request-PayPalAccessToken
                continue
            }
            if (($response.StatusCode -eq 429 -or $response.StatusCode -ge 500) -and $attempt -lt 2) {
                Start-Sleep -Seconds ([Math]::Pow(2, $attempt + 1))
                continue
            }
            return ConvertFrom-JsonResponse $response $Label
        }
        catch {
            $lastError = $_.Exception
            if ($attempt -ge 2) {
                break
            }
            Start-Sleep -Seconds ([Math]::Pow(2, $attempt + 1))
        }
    }
    throw $lastError
}

function Read-AgentState {
    if (-not (Test-Path -LiteralPath $Script:StateFile -PathType Leaf)) {
        return $null
    }
    try {
        return Get-Content -LiteralPath $Script:StateFile -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        Write-AgentLog "State file is invalid. The initial collection period will be used." "WARN"
        return $null
    }
}

function Get-SyncRange {
    $end = [DateTime]::UtcNow.AddMinutes(-1)
    $state = Read-AgentState
    $lastSuccess = ConvertFrom-IsoDate ([string](Get-ObjectProperty $state "last_success_to" ""))
    if ($null -eq $lastSuccess) {
        $start = $end.AddDays(-1 * [Math]::Max(1, $Script:InitialSyncDays))
    }
    else {
        $start = $lastSuccess.AddHours(-1 * $Script:OverlapHours)
    }
    if ($start -ge $end) {
        $start = $end.AddHours(-1 * [Math]::Max(1, $Script:OverlapHours))
    }
    return [PSCustomObject]@{ Start = $start; End = $end }
}

function Get-PayPalTransactions {
    param(
        [Parameter(Mandatory = $true)][DateTime]$Start,
        [Parameter(Mandatory = $true)][DateTime]$End
    )

    $records = New-Object System.Collections.Generic.List[object]
    $cursor = $Start
    while ($cursor -le $End) {
        $segmentEnd = $cursor.AddDays(30).AddSeconds(-1)
        if ($segmentEnd -gt $End) { $segmentEnd = $End }
        $page = 1
        while ($true) {
            $body = Invoke-PayPalGet "/v1/reporting/transactions" ([ordered]@{
                start_date = ConvertTo-IsoUtc $cursor
                end_date = ConvertTo-IsoUtc $segmentEnd
                fields = "all"
                page_size = 500
                page = $page
            }) "PayPal transaction search failed"
            foreach ($row in (Get-ObjectArray (Get-ObjectProperty $body "transaction_details" $null))) {
                if ($null -ne $row) { $records.Add($row) }
            }
            $totalPages = [Convert]::ToInt32((Get-ObjectProperty $body "total_pages" 1))
            if ($page -ge [Math]::Max(1, $totalPages)) { break }
            $page++
            if ($page -gt 500) { throw "PayPal transaction pagination exceeded 500 pages." }
        }
        $cursor = $segmentEnd.AddSeconds(1)
    }
    return @($records.ToArray())
}

function Merge-JsonObjects {
    param([AllowNull()]$First, [AllowNull()]$Second)

    $merged = [ordered]@{}
    foreach ($source in @($First, $Second)) {
        if ($null -eq $source) { continue }
        if ($source -is [System.Collections.IDictionary]) {
            foreach ($key in $source.Keys) { $merged[[string]$key] = $source[$key] }
        }
        else {
            foreach ($property in $source.PSObject.Properties) { $merged[$property.Name] = $property.Value }
        }
    }
    return $merged
}

function Get-PayPalDisputes {
    param(
        [Parameter(Mandatory = $true)][DateTime]$Start,
        [Parameter(Mandatory = $true)][DateTime]$End
    )

    $earliest = [DateTime]::UtcNow.AddDays(-179)
    if ($Start -lt $earliest) { $Start = $earliest }
    $records = New-Object System.Collections.Generic.List[object]
    $errors = New-Object System.Collections.Generic.List[string]
    $page = 1

    while ($Start -le $End) {
        $body = Invoke-PayPalGet "/v1/customer/disputes" ([ordered]@{
            update_time_after = ConvertTo-IsoUtc $Start
            update_time_before = ConvertTo-IsoUtc $End
            page_size = 50
            page = $page
        }) "PayPal dispute list failed"

        foreach ($item in (Get-ObjectArray (Get-ObjectProperty $body "items" $null))) {
            if ($null -eq $item) { continue }
            $disputeId = ([string](Get-ObjectProperty $item "dispute_id" "")).Trim()
            if ([string]::IsNullOrWhiteSpace($disputeId)) { continue }
            try {
                $escapedId = [Uri]::EscapeDataString($disputeId)
                $detail = Invoke-PayPalGet ("/v1/customer/disputes/" + $escapedId) ([ordered]@{}) "PayPal dispute detail failed"
                $records.Add((Merge-JsonObjects $item $detail))
            }
            catch {
                $records.Add($item)
                $errors.Add("${disputeId}: $($_.Exception.Message)")
            }
        }

        $hasNext = $false
        foreach ($link in (Get-ObjectArray (Get-ObjectProperty $body "links" $null))) {
            if (([string](Get-ObjectProperty $link "rel" "")) -eq "next") {
                $hasNext = $true
                break
            }
        }
        if (-not $hasNext) { break }
        $page++
        if ($page -gt 100) { throw "PayPal dispute pagination exceeded 100 pages." }
    }

    return [PSCustomObject]@{
        Records = @($records.ToArray())
        Errors = @($errors.ToArray())
    }
}

function New-CollectionPayload {
    $range = Get-SyncRange
    Request-PayPalAccessToken
    $transactions = @()
    $disputes = @()
    $transactionError = $null
    $disputeError = $null

    try {
        $transactions = @(Get-PayPalTransactions $range.Start $range.End)
    }
    catch {
        $transactionError = $_.Exception.Message
    }

    try {
        $disputeResult = Get-PayPalDisputes $range.Start $range.End
        $disputes = @($disputeResult.Records)
        if ($disputeResult.Errors.Count -gt 0) {
            $disputeError = "Partial dispute detail errors: " + (($disputeResult.Errors | Select-Object -First 20) -join "; ")
        }
    }
    catch {
        $disputeError = $_.Exception.Message
    }

    return [ordered]@{
        schema_version = 1
        upload_id = [Guid]::NewGuid().ToString()
        merchant_id = $Script:PayPalMerchantId
        environment = $Script:PayPalEnvironment
        generated_at = ConvertTo-IsoUtc ([DateTime]::UtcNow)
        period = [ordered]@{
            from = ConvertTo-IsoUtc $range.Start
            to = ConvertTo-IsoUtc $range.End
        }
        transactions = $transactions
        disputes = $disputes
        errors = [ordered]@{
            transactions = $transactionError
            disputes = $disputeError
        }
        collector = [ordered]@{
            name = "KKY PayPal Windows Agent PowerShell"
            version = "1.0"
        }
    }
}

function Convert-PayloadToBytes {
    param([Parameter(Mandatory = $true)]$Payload)
    $json = $Payload | ConvertTo-Json -Depth 100 -Compress
    return $Script:Utf8NoBom.GetBytes($json)
}

function Compress-GzipBytes {
    param([Parameter(Mandatory = $true)][byte[]]$Bytes)

    $output = New-Object System.IO.MemoryStream
    try {
        $gzip = New-Object System.IO.Compression.GZipStream($output, [IO.Compression.CompressionMode]::Compress, $true)
        try {
            $gzip.Write($Bytes, 0, $Bytes.Length)
        }
        finally {
            $gzip.Dispose()
        }
        return $output.ToArray()
    }
    finally {
        $output.Dispose()
    }
}

function Send-PayloadBytes {
    param(
        [Parameter(Mandatory = $true)][byte[]]$JsonBytes,
        [Parameter(Mandatory = $true)][string]$FileName
    )

    $compressed = Compress-GzipBytes $JsonBytes
    $unixEpoch = [DateTime]::Parse("1970-01-01T00:00:00Z", [Globalization.CultureInfo]::InvariantCulture).ToUniversalTime()
    $timestampNumber = [Int64][Math]::Floor(([DateTime]::UtcNow - $unixEpoch).TotalSeconds)
    $timestamp = $timestampNumber.ToString([Globalization.CultureInfo]::InvariantCulture)
    $nonce = [Guid]::NewGuid().ToString("N")

    $sha = New-Object System.Security.Cryptography.SHA256Managed
    try {
        $bodyHash = ConvertTo-LowerHex ($sha.ComputeHash($compressed))
    }
    finally {
        $sha.Dispose()
    }

    $signatureInput = "$timestamp`n$nonce`n$bodyHash"
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    try {
        $hmac.Key = $Script:Utf8NoBom.GetBytes($Script:ErpImportSecret)
        $signature = ConvertTo-LowerHex ($hmac.ComputeHash($Script:Utf8NoBom.GetBytes($signatureInput)))
    }
    finally {
        $hmac.Dispose()
    }

    $response = Invoke-RawHttpRequest -Method "POST" -Uri $Script:ErpImportUrl -Headers @{
        "Content-Encoding" = "gzip"
        "X-PayPal-Timestamp" = $timestamp
        "X-PayPal-Nonce" = $nonce
        "X-PayPal-Signature" = $signature
        "X-PayPal-Filename" = $FileName
    } -Body $compressed -ContentType "application/json"
    $result = ConvertFrom-JsonResponse $response "ERP import failed"
    if (-not [bool](Get-ObjectProperty $result "accepted" $false)) {
        throw "ERP rejected the import: $([string](Get-ObjectProperty $result 'message' $response.Body))"
    }
    return $result
}

function Save-PendingPayload {
    param([Parameter(Mandatory = $true)]$Payload)

    $safeMerchant = $Script:PayPalMerchantId -replace "[^A-Za-z0-9_-]", "_"
    $fileName = "paypal_{0}_{1}_{2}.json" -f $safeMerchant, [DateTime]::UtcNow.ToString("yyyyMMdd_HHmmss"), $Payload.upload_id
    $path = Join-Path $Script:PendingDirectory $fileName
    $temporary = $path + ".tmp"
    [System.IO.File]::WriteAllBytes($temporary, (Convert-PayloadToBytes $Payload))
    Move-Item -LiteralPath $temporary -Destination $path -Force
    return $path
}

function Save-AgentState {
    param([Parameter(Mandatory = $true)]$Payload)

    $state = [ordered]@{
        merchant_id = $Script:PayPalMerchantId
        last_success_to = $Payload.period.to
        last_upload_id = $Payload.upload_id
        updated_at = ConvertTo-IsoUtc ([DateTime]::UtcNow)
    }
    $temporary = $Script:StateFile + ".tmp"
    [System.IO.File]::WriteAllBytes($temporary, (Convert-PayloadToBytes $state))
    Move-Item -LiteralPath $temporary -Destination $Script:StateFile -Force
}

function Move-ToArchive {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)]$Payload,
        [Parameter(Mandatory = $true)]$Response
    )

    $monthDirectory = Join-Path $Script:ArchiveDirectory ([DateTime]::UtcNow.ToString("yyyyMM"))
    if (-not (Test-Path -LiteralPath $monthDirectory -PathType Container)) {
        New-Item -ItemType Directory -Path $monthDirectory -Force | Out-Null
    }
    Move-Item -LiteralPath $Path -Destination (Join-Path $monthDirectory ([IO.Path]::GetFileName($Path))) -Force
    if (([string](Get-ObjectProperty $Response "status" "")) -eq "success") {
        Save-AgentState $Payload
    }
}

function Send-PendingFiles {
    $files = @(Get-ChildItem -LiteralPath $Script:PendingDirectory -Filter "*.json" -File | Sort-Object Name)
    foreach ($file in $files) {
        try {
            $payload = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
            $response = Send-PayloadBytes ([IO.File]::ReadAllBytes($file.FullName)) $file.Name
            Move-ToArchive $file.FullName $payload $response
            Write-AgentLog ("Pending file accepted: {0}; {1}" -f $file.Name, [string](Get-ObjectProperty $response "message" ""))
        }
        catch {
            Write-AgentLog ("Pending file failed; new collection stopped: {0}; {1}" -f $file.Name, $_.Exception.Message) "ERROR"
            return $false
        }
    }
    return $true
}

function Invoke-ImportConnectionTest {
    $now = ConvertTo-IsoUtc ([DateTime]::UtcNow)
    $payload = [ordered]@{
        schema_version = 1
        upload_id = [Guid]::NewGuid().ToString()
        merchant_id = $Script:PayPalMerchantId
        environment = $Script:PayPalEnvironment
        generated_at = $now
        period = [ordered]@{ from = $now; to = $now }
        transactions = @()
        disputes = @()
        errors = [ordered]@{}
        collector = [ordered]@{
            name = "KKY PayPal Windows Agent PowerShell connection test"
            version = "1.0"
        }
    }
    $response = Send-PayloadBytes (Convert-PayloadToBytes $payload) "paypal_import_connection_test.json"
    Write-AgentLog ("ERP connection test succeeded: {0}" -f [string](Get-ObjectProperty $response "message" "accepted"))
    return 0
}

function New-AgentMutex {
    $sha = New-Object System.Security.Cryptography.SHA1Managed
    try {
        $nameHash = ConvertTo-LowerHex ($sha.ComputeHash($Script:Utf8NoBom.GetBytes($Script:AgentDirectory.ToLowerInvariant())))
    }
    finally {
        $sha.Dispose()
    }
    return New-Object System.Threading.Mutex($false, ("Local\KKY_PayPal_Agent_" + $nameHash.Substring(0, 20)))
}

function Invoke-Agent {
    Initialize-AgentDirectories
    Read-AgentConfig $ConfigPath
    Test-AgentConfig (-not $TestImport.IsPresent)
    Enable-Tls12

    $mutex = New-AgentMutex
    $acquired = $false
    try {
        try {
            $acquired = $mutex.WaitOne(0, $false)
        }
        catch [Threading.AbandonedMutexException] {
            $acquired = $true
        }
        if (-not $acquired) {
            throw "Another PayPal agent process is already running."
        }

        if ($TestImport.IsPresent) {
            return Invoke-ImportConnectionTest
        }
        if (-not (Send-PendingFiles)) {
            return 2
        }

        Write-AgentLog ("Starting PayPal collection for Merchant ID {0}." -f $Script:PayPalMerchantId)
        $payload = New-CollectionPayload
        $pendingPath = Save-PendingPayload $payload
        Write-AgentLog ("Collection completed: transactions {0}, disputes {1}, file {2}." -f $payload.transactions.Count, $payload.disputes.Count, [IO.Path]::GetFileName($pendingPath))
        try {
            $response = Send-PayloadBytes ([IO.File]::ReadAllBytes($pendingPath)) ([IO.Path]::GetFileName($pendingPath))
            Move-ToArchive $pendingPath $payload $response
            Write-AgentLog ("ERP accepted the import: {0}" -f [string](Get-ObjectProperty $response "message" "completed"))
            $status = [string](Get-ObjectProperty $response "status" "")
            if ($status -in @("success", "partial")) { return 0 }
            return 3
        }
        catch {
            Write-AgentLog ("Upload failed. The JSON remains in pending: {0}" -f $_.Exception.Message) "ERROR"
            return 2
        }
    }
    finally {
        if ($acquired) {
            try { $mutex.ReleaseMutex() } catch {}
        }
        $mutex.Dispose()
    }
}

try {
    exit (Invoke-Agent)
}
catch {
    if ([string]::IsNullOrWhiteSpace($Script:LogFile)) {
        try { Initialize-AgentDirectories } catch {}
    }
    try {
        Write-AgentLog ("Agent failed: {0}" -f $_.Exception.Message) "ERROR"
        if (-not [string]::IsNullOrWhiteSpace($_.ScriptStackTrace)) {
            Write-AgentLog $_.ScriptStackTrace "ERROR"
        }
    }
    catch {
        Write-Host ("Agent failed: {0}" -f $_.Exception.Message)
    }
    exit 1
}
