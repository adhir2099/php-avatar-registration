<?php
require __DIR__ . '/functions.php';

if (!session_start([
    'use_strict_mode' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
])) {
    http_response_code(500);
    echo 'err';
    exit;
}

function post($key) {
    return $_POST[$key] ?? null;
}

$action = post('action');

if ($action === "formRegistration") {

    $userName = post('userName');
    $email    = post('email');
    $password = post('entryPass');

    if (!is_string($userName) || !is_string($email) || !is_string($password)) {
        http_response_code(400);
        echo 'err';
        exit;
    }

    $userName = trim($userName);
    $email = trim($email);

    if ($userName === '' || $email === '' || $password === '' || !mb_check_encoding($userName, 'UTF-8')) {
        http_response_code(400);
        echo 'err';
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo 'invalid_email';
        exit;
    }

    $userImage = null;
    try {
        require __DIR__ . '/dbcon.php';
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Failed to regenerate session');
        }
        $passHash  = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
        $userImage = makeImage($userName);

        $query = "INSERT INTO users (userName, email, pass, userImg, createdAt)
                  VALUES (:userName, :email, :pass, :userImg, NOW())";

        $statement = $connect->prepare($query);

        $result = $statement->execute([
            ':userName' => $userName,
            ':email'    => $email,
            ':pass'     => $passHash,
            ':userImg'  => $userImage
        ]);

        if (!$result) {
            throw new RuntimeException('Failed to save user');
        }
        $_SESSION['user_id'] = $connect->lastInsertId();
        echo 'ok';
    } catch (Throwable $e) {
        if ($userImage !== null && is_file(__DIR__ . '/../' . $userImage)) {
            unlink(__DIR__ . '/../' . $userImage);
        }
        error_log('Registration error: ' . $e->getMessage());
        http_response_code(500);
        echo 'err';
    }
}

if ($action === "Load") {

    $row = false;
    if (isset($_SESSION['user_id'])) {
        try {
            require __DIR__ . '/dbcon.php';
            $qry = "SELECT userName, userImg FROM users WHERE id = :id LIMIT 1";
            $statement = $connect->prepare($qry);
            $statement->execute([':id' => $_SESSION['user_id']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Profile load error: ' . $e->getMessage());
            http_response_code(500);
            echo 'err';
            exit;
        }
    }

    if ($row) {

        $userName = htmlspecialchars($row["userName"], ENT_QUOTES, 'UTF-8');
        $userImg  = htmlspecialchars($row["userImg"], ENT_QUOTES, 'UTF-8');

        echo '
        <picture>
            <img src="'.$userImg.'" class="img-fluid img-thumbnail rounded-circle" width="140" height="140" alt="User image">
        </picture>
        <p>User name:</p>
        <h2 class="fw-normal text-capitalize">'.$userName.'</h2>
        <a href="./index.html" class="btn btn-primary">Return</a>';
        
    } else {
        echo '
        <div class="alert alert-danger">
            <strong>Error</strong>
            <hr>
            <p>No info to show</p>
        </div>';
    }
}
