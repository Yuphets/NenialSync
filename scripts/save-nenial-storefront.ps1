# Script to save the Nenial Enterprises storefront image
# Usage: .\scripts\save-nenial-storefront.ps1

$mediaPath = "public/media"
$imageName = "nenial-storefront.jpg"
$imagePath = Join-Path $mediaPath $imageName

# Create media folder if it doesn't exist
if (!(Test-Path $mediaPath)) {
    New-Item -ItemType Directory -Path $mediaPath -Force | Out-Null
    Write-Host "Created $mediaPath directory"
}

Write-Host "Save the Nenial Enterprises storefront image to: $imagePath"
Write-Host ""
Write-Host "Steps:"
Write-Host "1. Right-click on the Nenial Enterprises image in the chat"
Write-Host "2. Select 'Save image as...'"
Write-Host "3. Navigate to: $(Get-Location)\$mediaPath"
Write-Host "4. Name it: $imageName"
Write-Host "5. Click Save"
Write-Host ""
Write-Host "Once saved, the login page background will automatically update."
