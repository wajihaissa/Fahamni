<?php
$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=fahamni;charset=utf8mb4';
$user = 'root';
$pass = '';
$email = 'aziz228nasri@gmail.com';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare('UPDATE `user` SET roles = :roles, status = 1 WHERE email = :email');
    $stmt->execute([
        ':roles' => '["ROLE_ADMIN"]',
        ':email' => $email,
    ]);

    $check = $pdo->prepare('SELECT id, email, roles, status FROM `user` WHERE email = :email');
    $check->execute([':email' => $email]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo "No user found for {$email}\n";
        exit(1);
    }

    echo "Updated user: ID={$row['id']} EMAIL={$row['email']} ROLES={$row['roles']} STATUS={$row['status']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
