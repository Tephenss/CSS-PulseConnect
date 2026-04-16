$sourceRoot = 'C:\Users\marks\Downloads\CCS PulseConnect'
$appRoot = 'C:\Users\marks\Downloads\CCS PulseConnect(APP)'

$copies = @(
    @{
        Source = Join-Path $sourceRoot 'tmp_notification_service_cert.dart'
        Destination = Join-Path $appRoot 'lib\services\notification_service.dart'
    },
    @{
        Source = Join-Path $sourceRoot 'tmp_push_notification_service_cert.dart'
        Destination = Join-Path $appRoot 'lib\services\push_notification_service.dart'
    },
    @{
        Source = Join-Path $sourceRoot 'tmp_notifications_modal_cert.dart'
        Destination = Join-Path $appRoot 'lib\widgets\notifications_modal.dart'
    }
)

foreach ($copy in $copies) {
    if (-not (Test-Path -LiteralPath $copy.Source)) {
        throw "Missing source file: $($copy.Source)"
    }

    Copy-Item -LiteralPath $copy.Source -Destination $copy.Destination -Force
}

Write-Output 'Patched certificate notification files copied to app repo.'
