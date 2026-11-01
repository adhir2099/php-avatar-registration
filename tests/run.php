<?php

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function request(array $data, string &$cookie): array {
    global $baseUrl;
    $curl = curl_init($baseUrl . '/core/signupController.php');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_COOKIE => $cookie,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException(curl_error($curl));
    }
    $headers = substr($response, 0, curl_getinfo($curl, CURLINFO_HEADER_SIZE));
    if (preg_match('/Set-Cookie: (PHPSESSID=[^;\r\n]+)/i', $headers, $match)) {
        $cookie = $match[1];
    }
    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), substr($response, strlen($headers))];
}

$repo = dirname(__DIR__);
$root = sys_get_temp_dir() . '/avatar-registration-test-' . bin2hex(random_bytes(8));
$server = null;
$db = null;

try {
    mkdir($root . '/core', 0755, true);
    mkdir($root . '/assets/fonts', 0755, true);
    mkdir($root . '/sessions');
    foreach (['functions.php', 'signupController.php'] as $file) {
        copy($repo . '/core/' . $file, $root . '/core/' . $file);
    }
    copy($repo . '/assets/fonts/Pokemon.ttf', $root . '/assets/fonts/Pokemon.ttf');
    file_put_contents($root . '/core/dbcon.php', <<<'PHP'
<?php
$connect = new PDO('sqlite:' . __DIR__ . '/../test.sqlite');
$connect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connect->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
PHP);
    $db = new PDO('sqlite:' . $root . '/test.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, userName TEXT, email TEXT, pass TEXT, userImg TEXT, createdAt TEXT)');
    $db->exec("CREATE TRIGGER reject_insert BEFORE INSERT ON users WHEN NEW.email = 'fail@example.com' BEGIN SELECT RAISE(ABORT, 'test insert failure'); END");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $server = proc_open([
        PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $root . '/sessions',
        '-S', $address, '-t', $root,
    ], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start test server');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'temporary HTTP server starts');

    $registration = ['action' => 'formRegistration', 'userName' => 'Alice', 'email' => 'alice@example.com', 'entryPass' => ' password with spaces '];
    $aliceCookie = '';
    check(request($registration, $aliceCookie) === [200, 'ok'], 'registration succeeds');
    $alice = $db->query('SELECT * FROM users ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check(password_verify($registration['entryPass'], $alice['pass']), 'password keeps its leading and trailing spaces');
    check(is_file($root . '/' . $alice['userImg']), 'registration creates its avatar');

    $bobCookie = '';
    check(request(array_replace($registration, ['userName' => 'Bob', 'email' => 'bob@example.com']), $bobCookie) === [200, 'ok'], 'second browser registers independently');
    [$status, $body] = request(['action' => 'Load'], $aliceCookie);
    check($status === 200 && str_contains($body, '>Alice</h2>') && !str_contains($body, '>Bob</h2>'), 'first browser keeps its own profile after another registration');
    [$status, $body] = request(['action' => 'Load'], $bobCookie);
    check($status === 200 && str_contains($body, '>Bob</h2>'), 'second browser gets its own profile');
    $anonymousCookie = '';
    [$status, $body] = request(['action' => 'Load'], $anonymousCookie);
    check($status === 200 && str_contains($body, 'No info to show') && !str_contains($body, '<h2'), 'anonymous requests reveal no profile');

    $fileCount = count(glob($root . '/assets/img/profile/*.png'));
    $rowCount = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    foreach (['userName', 'email', 'entryPass'] as $field) {
        foreach ([[$registration[$field]], null, ''] as $value) {
            [$status, $body] = request(array_replace($registration, [$field => $value]), $aliceCookie);
            check($status === 400 && $body === 'err', "$field rejects array, missing or empty input (" . get_debug_type($value) . ')');
        }
    }
    check(request(array_replace($registration, ['email' => 'invalid']), $aliceCookie) === [400, 'invalid_email'], 'invalid email is rejected');
    check(request(array_replace($registration, ['userName' => "\xC3"]), $aliceCookie) === [400, 'err'], 'invalid UTF-8 is rejected');
    check(count(glob($root . '/assets/img/profile/*.png')) === $fileCount && $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === $rowCount, 'invalid requests create no users or avatars');

    check(request(array_replace($registration, ['email' => 'fail@example.com']), $aliceCookie) === [500, 'err'], 'insert failure returns a controlled error');
    check(count(glob($root . '/assets/img/profile/*.png')) === $fileCount && $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === $rowCount, 'insert failure leaves no orphan avatar or user');
    [, $body] = request(['action' => 'Load'], $aliceCookie);
    check(str_contains($body, '>Alice</h2>'), 'failed registration preserves the previous profile');

    $unicodeCookie = '';
    check(request(array_replace($registration, ['userName' => 'érika', 'email' => 'erika@example.com']), $unicodeCookie) === [200, 'ok'], 'Unicode name registers');
    $avatarPath = $db->query("SELECT userImg FROM users WHERE email = 'erika@example.com'")->fetchColumn();
    $avatar = imagecreatefrompng($root . '/' . $avatarPath);
    $expected = imagecreatetruecolor(200, 200);
    $white = imagecolorallocate($expected, 255, 255, 255);
    $font = $repo . '/assets/fonts/Pokemon.ttf';
    $box = imagettfbbox(100, 0, $font, 'É');
    imagettftext($expected, 100, 0, (int)((200 - ($box[2] - $box[0])) / 2), (int)((200 + ($box[1] - $box[7])) / 2), $white, $font, 'É');
    $matches = true;
    $whitePixels = 0;
    for ($y = 0; $y < 200; $y++) {
        for ($x = 0; $x < 200; $x++) {
            $expectedWhite = imagecolorat($expected, $x, $y) === 0xFFFFFF;
            $matches = $matches && $expectedWhite === (imagecolorat($avatar, $x, $y) === 0xFFFFFF);
            $whitePixels += (int)$expectedWhite;
        }
    }
    check($matches && $whitePixels > 0, 'avatar renders the complete uppercase É initial');

    foreach (['index.html', 'home.html'] as $page) {
        $html = file_get_contents($repo . '/' . $page);
        check((bool)preg_match('/<script\s+type="module"\s+src="assets\/js\/bootstrap\.bundle\.min\.js"/', $html), "$page loads Bootstrap as a module");
        check(str_contains($html, 'assets/css/bootstrap6.css'), "$page uses the current Bootstrap stylesheet");
        preg_match_all('/(?:src|href)="(assets\/[^"?#]+)"/', $html, $assets);
        foreach ($assets[1] as $asset) {
            check(is_file($repo . '/' . $asset), "$page asset exists: $asset");
        }
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    unset($db, $avatar, $expected);
    $resolvedRoot = realpath($root);
    $tempPrefix = rtrim(realpath(sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'avatar-registration-test-';
    if ($resolvedRoot !== false && str_starts_with($resolvedRoot, $tempPrefix)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolvedRoot);
    }
}
