<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=fahamni;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS face_id_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS face_id_token VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS face_id_enrolled_at DATETIME DEFAULT NULL");
$stmt = $pdo->query("SHOW COLUMNS FROM `user` LIKE 'face_id_%'");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT), PHP_EOL;
