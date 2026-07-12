<?php
$path = __DIR__ . '/database/database.sqlite';
if (!file_exists($path)) {
    echo "DB_NOT_FOUND\n";
    exit(1);
}
$pdo = new PDO('sqlite:' . $path);
$updated = $pdo->exec("UPDATE products SET image_url = '/media/fishing-rod.png' WHERE sku = 'FISH-001'");
echo "UPDATED=" . ($updated === false ? 'FAIL' : $updated) . "\n";
$stmt = $pdo->query('SELECT sku, image_url FROM products WHERE sku = "FISH-001"');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "NOT_FOUND\n";
    exit(0);
}
echo $row['sku'] . ' ' . $row['image_url'] . "\n";
