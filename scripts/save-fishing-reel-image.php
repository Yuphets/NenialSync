<?php
/**
 * Script to save fishing reel baitcaster image to public/media directory
 * Run with: php scripts/save-fishing-reel-image.php
 */

$publicMediaPath = __DIR__ . '/../public/media';
$imageFilename = 'fishing-reel-baitcaster.jpg';
$imagePath = $publicMediaPath . '/' . $imageFilename;

// Create a sample fishing reel image using GD Library
$width = 500;
$height = 400;
$image = imagecreatetruecolor($width, $height);

// Color palette
$wood = imagecolorallocate($image, 139, 90, 43);      // Wood background
$silver = imagecolorallocate($image, 192, 192, 192);  // Silver reel
$dark = imagecolorallocate($image, 64, 64, 64);       // Dark gray
$light = imagecolorallocate($image, 220, 220, 220);   // Light
$white = imagecolorallocate($image, 255, 255, 255);   // White
$line = imagecolorallocate($image, 200, 160, 100);    // Line color

// Fill background with wood color
imagefilledrectangle($image, 0, 0, $width, $height, $wood);

// Draw texture
for ($i = 0; $i < $width; $i += 5) {
    imageline($image, $i, 0, $i, $height, imagecolorallocate($image, 130, 80, 35));
}

// Draw first reel (left)
$reel1X = 120;
$reel1Y = 150;
imagefilledellipse($image, $reel1X, $reel1Y, 100, 100, $silver);
imageellipse($image, $reel1X, $reel1Y, 100, 100, $dark);
imagefilledellipse($image, $reel1X, $reel1Y, 70, 70, $light);

// Reel spokes
for ($angle = 0; $angle < 360; $angle += 45) {
    $rad = deg2rad($angle);
    $x2 = $reel1X + 40 * cos($rad);
    $y2 = $reel1Y + 40 * sin($rad);
    imageline($image, $reel1X, $reel1Y, $x2, $y2, $dark);
}

// Reel center
imagefilledellipse($image, $reel1X, $reel1Y, 15, 15, $dark);

// Handle for first reel
imageline($image, $reel1X + 50, $reel1Y - 20, $reel1X + 70, $reel1Y - 50, $dark);
imageline($image, $reel1X + 50, $reel1Y - 20, $reel1X + 75, $reel1Y - 45, $dark);
imagefilledellipse($image, $reel1X + 70, $reel1Y - 50, 12, 12, $dark);

// Draw line spool
imagefilledrectangle($image, $reel1X - 40, $reel1Y - 8, $reel1X + 40, $reel1Y + 8, $line);
imageline($image, $reel1X - 40, $reel1Y - 8, $reel1X + 40, $reel1Y - 8, $dark);
imageline($image, $reel1X - 40, $reel1Y + 8, $reel1X + 40, $reel1Y + 8, $dark);

// Draw second reel (right) - slightly different angle
$reel2X = 360;
$reel2Y = 170;
imagefilledellipse($image, $reel2X, $reel2Y, 100, 100, $silver);
imageellipse($image, $reel2X, $reel2Y, 100, 100, $dark);
imagefilledellipse($image, $reel2X, $reel2Y, 70, 70, $light);

// Reel spokes
for ($angle = 22; $angle < 360; $angle += 45) {
    $rad = deg2rad($angle);
    $x2 = $reel2X + 40 * cos($rad);
    $y2 = $reel2Y + 40 * sin($rad);
    imageline($image, $reel2X, $reel2Y, $x2, $y2, $dark);
}

// Reel center
imagefilledellipse($image, $reel2X, $reel2Y, 15, 15, $dark);

// Handle for second reel
imageline($image, $reel2X - 50, $reel2Y - 20, $reel2X - 70, $reel2Y - 50, $dark);
imageline($image, $reel2X - 50, $reel2Y - 20, $reel2X - 75, $reel2Y - 45, $dark);
imagefilledellipse($image, $reel2X - 70, $reel2Y - 50, 12, 12, $dark);

// Draw line spool
imagefilledrectangle($image, $reel2X - 40, $reel2Y - 8, $reel2X + 40, $reel2Y + 8, $line);
imageline($image, $reel2X - 40, $reel2Y - 8, $reel2X + 40, $reel2Y - 8, $dark);
imageline($image, $reel2X - 40, $reel2Y + 8, $reel2X + 40, $reel2Y + 8, $dark);

// Add title text
$fontColor = $white;
imagestring($image, 5, 150, 30, 'Fishing Reel Baitcaster', $fontColor);

// Save image
$success = imagejpeg($image, $imagePath, 90);
imagedestroy($image);

if ($success) {
    echo "✓ Fishing reel image saved successfully to: $imagePath\n";
    echo "✓ Image URL: /media/$imageFilename\n";
} else {
    echo "✗ Failed to save image to: $imagePath\n";
    exit(1);
}
?>
