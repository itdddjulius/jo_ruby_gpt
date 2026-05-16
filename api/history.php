<?php
header("Content-Type: application/json; charset=utf-8");

$dataDir = __DIR__ . "/../data";

if (!is_dir($dataDir)) {
    echo json_encode([]);
    exit;
}

$files = glob($dataDir . "/*.json");
$records = [];

foreach ($files as $file) {
    if (basename($file) === "latest.json") {
        continue;
    }

    $json = json_decode(file_get_contents($file), true);

    if ($json) {
        $records[] = $json;
    }
}

usort($records, function ($a, $b) {
    return strtotime($b["created_at"] ?? "now") <=> strtotime($a["created_at"] ?? "now");
});

echo json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);