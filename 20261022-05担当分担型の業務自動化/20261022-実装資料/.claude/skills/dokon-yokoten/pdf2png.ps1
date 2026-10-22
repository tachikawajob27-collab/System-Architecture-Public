param(
  [Parameter(Mandatory=$true)][string]$Pdf,
  [Parameter(Mandatory=$true)][string]$OutDir,
  [int]$Width = 2000
)

Add-Type -AssemblyName System.Runtime.WindowsRuntime
$asTaskGeneric = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
  $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and
  $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
})[0]

function Await($WinRtTask, $ResultType) {
  $asTask = $asTaskGeneric.MakeGenericMethod($ResultType)
  $netTask = $asTask.Invoke($null, @($WinRtTask))
  $netTask.Wait(-1) | Out-Null
  $netTask.Result
}
function AwaitAction($WinRtAction) {
  $asTask = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and
    $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
  })[0]
  $netTask = $asTask.Invoke($null, @($WinRtAction))
  $netTask.Wait(-1) | Out-Null
}

[void][Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType=WindowsRuntime]
[void][Windows.Storage.StorageFile, Windows.Storage, ContentType=WindowsRuntime]
[void][Windows.Storage.Streams.RandomAccessStream, Windows.Storage.Streams, ContentType=WindowsRuntime]

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$file = Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync($Pdf)) ([Windows.Storage.StorageFile])
$doc  = Await ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])

Write-Output ("pages=" + $doc.PageCount)
for ($i = 0; $i -lt $doc.PageCount; $i++) {
  $page = $doc.GetPage($i)
  $sz = $page.Size
  Write-Output ("page{0} size={1}x{2}pt" -f $i, [math]::Round($sz.Width,1), [math]::Round($sz.Height,1))
  $opt = New-Object Windows.Data.Pdf.PdfPageRenderOptions
  $opt.DestinationWidth = [uint32]$Width
  $outPath = Join-Path $OutDir ("p{0}.png" -f $i)
  $sf = Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync($outPath)) ([Windows.Storage.StorageFile]) -ErrorAction SilentlyContinue
  if (-not (Test-Path -LiteralPath $outPath)) { New-Item -ItemType File -Path $outPath | Out-Null }
  $sf = Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync($outPath)) ([Windows.Storage.StorageFile])
  $stream = Await ($sf.OpenAsync([Windows.Storage.FileAccessMode]::ReadWrite)) ([Windows.Storage.Streams.IRandomAccessStream])
  AwaitAction ($page.RenderToStreamAsync($stream, $opt))
  $stream.Dispose()
  $page.Close()
  Write-Output ("wrote " + $outPath)
}
