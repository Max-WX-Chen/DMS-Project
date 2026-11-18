<?php
declare(strict_types=1);

// Run in the CLI after importing the SQL. Only a generated application account is saved.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$schema = $argv[1] ?? 'form_club';
$port = filter_var($argv[2] ?? 3306, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
if (!preg_match('/^[a-zA-Z0-9_]{1,48}$/D', $schema) || !$port) {
    exit("Usage: php database/configure.php [schema] [port]\n");
}
$file = dirname(__DIR__) . '/config/local.php';
if (file_exists($file)) {
    exit("A local configuration already exists. It has been preserved.\n");
}
$admin = getenv('FORM_DB_ADMIN') ?: 'root';
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$schema;charset=utf8mb4", $admin, getenv('FORM_DB_ADMIN_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables = ['users','membership_plans','orders','payments','memberships','coaches','venues','courses','class_sessions','bookings','attendance'];
foreach ($tables as $table) {
    $pdo->query("SELECT 1 FROM `$table` LIMIT 0");
}
$user = 'form_' . bin2hex(random_bytes(5));
$pass = bin2hex(random_bytes(24));
$account = $pdo->quote($user) . "@'127.0.0.1'";
$pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($pass));
try {
    $pdo->exec("GRANT SELECT ON `$schema`.* TO $account");
    foreach ($tables as $table) {
        $pdo->exec("GRANT INSERT ON `$schema`.`$table` TO $account");
    }
    $updates = [
        'users'=>'full_name,phone,password_hash,status', 'membership_plans'=>'status',
        'orders'=>'status','coaches'=>'name,specialty,status','venues'=>'name,location,capacity,status',
        'courses'=>'name,description,category,duration_minutes,status',
        'class_sessions'=>'course_id,coach_id,venue_id,starts_at,ends_at,capacity,status',
        'bookings'=>'status,booked_at,cancelled_at',
    ];
    foreach ($updates as $table => $columns) {
        $pdo->exec("GRANT UPDATE ($columns) ON `$schema`.`$table` TO $account");
    }
    $config = ['host'=>'127.0.0.1','port'=>(int)$port,'database'=>$schema,'user'=>$user,'password'=>$pass];
    $handle = fopen($file, 'x');
    if (!$handle) {
        throw new RuntimeException('Could not create config/local.php.');
    }
    $contents = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (fwrite($handle,$contents) !== strlen($contents)) {
        fclose($handle);
        unlink($file);
        throw new RuntimeException('Could not write local configuration.');
    }
    fclose($handle);
} catch (Throwable $error) {
    $pdo->exec("DROP USER $account");
    throw $error;
}
echo "Created restricted account and config/local.php for $schema.\n";
