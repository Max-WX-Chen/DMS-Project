<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

final class BusinessError extends RuntimeException
{
    public function __construct(public string $reason, string $message, public int $http)
    {
        parent::__construct($message);
    }
}

function fail(string $code, string $message, int $http = 422): never
{
    throw new BusinessError($code, $message, $http);
}

function timed(callable $call): mixed
{
    $start = hrtime(true);
    try {
        return $call();
    } finally {
        $GLOBALS['db_ns'] = ($GLOBALS['db_ns'] ?? 0) + hrtime(true) - $start;
    }
}

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $file = getenv('FORM_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
        if (!is_file($file)) {
            fail('SETUP_REQUIRED', 'Set up the database connection in config/local.php.', 503);
        }
        $c = require $file;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']);
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET SESSION time_zone = '+08:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    return $pdo;
}

function run(string $sql, array $args = []): PDOStatement
{
    $pdo = db();
    return timed(function () use ($pdo, $sql, $args) {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s;
    });
}

function rows(string $sql, array $args = []): array
{
    return run($sql, $args)->fetchAll();
}

function one(string $sql, array $args = []): ?array
{
    return run($sql, $args)->fetch() ?: null;
}

function tx(callable $callback): mixed
{
    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        timed(fn() => $pdo->beginTransaction());
        try {
            $result = $callback();
            timed(fn() => $pdo->commit());
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                timed(fn() => $pdo->rollBack());
            }
            if (!$e instanceof PDOException || !in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw $e;
            }
            if ($attempt >= 3) {
                fail('BUSY', 'Another operation is in progress. Retry this request.', 409);
            }
            usleep(random_int(10000, 35000));
        }
    }
}

function field(array $in, string $name, int $max = 255): string
{
    $v = $in[$name] ?? null;
    if (!is_string($v) || trim($v) === '' || mb_strlen(trim($v), 'UTF-8') > $max) {
        fail('INVALID_FIELD', "Supply a valid $name (up to $max characters).");
    }
    return trim($v);
}

function positive(array $in, string $name): int
{
    $value = $in[$name] ?? null;
    if ((!is_int($value) && !is_string($value)) ||
        filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range'=>2147483647]]) === false) {
        fail('INVALID_FIELD', "Supply a positive integer for $name.");
    }
    return (int)$value;
}

function pageArgs(array $in): array
{
    $page = positive($in + ['page'=>1], 'page');
    $limit = positive($in + ['limit'=>25], 'limit');
    if ($limit > 100) {
        fail('INVALID_FIELD', 'limit must be 1 to 100.');
    }
    if (isset($in['offset'])) {
        $offset = $in['offset'];
        if ((!is_int($offset) && !is_string($offset)) ||
            filter_var($offset, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>2147483647]]) === false) {
            fail('INVALID_FIELD', 'offset must be a nonnegative integer.');
        }
        return [$limit, (int)$offset];
    }
    return [$limit, ($page - 1) * $limit];
}

function choice(array $in, string $key, array $values): string
{
    if (!in_array($in[$key] ?? null, $values, true)) {
        fail('INVALID_FIELD', "$key must be one of " . implode(', ', $values) . '.');
    }
    return $in[$key];
}

const PUBLIC_USER = 'user_id, full_name, email, phone, role, status';

function viewer(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = one('SELECT ' . PUBLIC_USER . ' FROM users WHERE user_id=? AND status=\'ACTIVE\'', [$_SESSION['user_id']]);
    if (!$user) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function needRole(string $role): array
{
    $user = viewer() ?? fail('LOGIN_REQUIRED', 'Sign in to continue.', 401);
    if ($user['role'] !== $role) {
        fail('FORBIDDEN', 'This operation requires the ' . strtolower($role) . ' role.', 403);
    }
    return $user;
}

function lockUser(int $id): array
{
    $user = one('SELECT ' . PUBLIC_USER . ' FROM users WHERE user_id=? FOR UPDATE', [$id]);
    if (!$user || $user['status'] !== 'ACTIVE') {
        fail('ACCOUNT_DISABLED', 'An active account is required.', 403);
    }
    return $user;
}
