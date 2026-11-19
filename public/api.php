<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/accounts.php';
require dirname(__DIR__) . '/app/billing.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('form_club');
session_set_cookie_params([
    'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/',
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$accounts = ['auth.session'=>'GET', 'auth.login'=>'POST', 'auth.register'=>'POST','auth.logout'=>'POST',
    'profile.update'=>'POST','profile.password'=>'POST','members.list'=>'GET','members.detail'=>'GET','members.status'=>'POST'];
$billing = ['plans.list'=>'GET','plans.create'=>'POST','plans.status'=>'POST','memberships.list'=>'GET',
    'orders.list'=>'GET','orders.detail'=>'GET','orders.create'=>'POST','orders.cancel'=>'POST','orders.pay'=>'POST','orders.confirm'=>'POST'];
// The C1 branch can run on its own. C2 registers its routes on integration.
$classes = [];
if (is_file(dirname(__DIR__) . '/app/classes.php')) {
    require dirname(__DIR__) . '/app/classes.php';
    $classes = classActions();
}

try {
    $action = $_GET['action'] ?? '';
    $method = is_string($action) ? ($accounts[$action] ?? $billing[$action] ?? $classes[$action] ?? null) : null;
    if (!$method) {
        fail('NOT_FOUND', 'Unknown API operation.', 404);
    }
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        fail('WRONG_METHOD', 'Use ' . $method . ' for this operation.', 405);
    }
    $in = $_GET;
    if ($method === 'POST') {
        if (str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $body = json_decode(file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
            if (!$body instanceof stdClass) {
                fail('INVALID_JSON', 'Send a JSON object.', 400);
            }
            $in = (array)$body;
        } else {
            $in = $_POST;
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $in['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
            fail('CSRF', 'Reload your session and try again.', 403);
        }
    }
    if (isset($accounts[$action])) {
        $data = accountRoutes($action, $in);
    } elseif (isset($billing[$action])) {
        $data = billingRoutes($action, $in);
    } else {
        $data = classRoutes($action, $in);
    }
    $payload = ['ok'=>true, 'data'=>$data];
} catch (Throwable $e) {
    if ($e instanceof BusinessError) {
        [$status,$code,$message] = [$e->http,$e->reason,$e->getMessage()];
    } elseif ($e instanceof JsonException) {
        [$status,$code,$message] = [400,'INVALID_JSON','Malformed JSON body.'];
    } elseif ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        [$status,$code,$message] = [409,'DUPLICATE','That record or request key already exists.'];
    } else {
        error_log('FORM Club request failure: ' . get_class($e) . ' ' . $e->getCode());
        [$status,$code,$message] = [500,'SERVER_ERROR','The request could not be completed.'];
    }
    http_response_code($status);
    $payload = ['ok'=>false,'error'=>['code'=>$code,'message'=>$message]];
}
$payload['db_ms'] = round(($GLOBALS['db_ns'] ?? 0) / 1e6, 3);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
