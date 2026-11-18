<?php
declare(strict_types=1);

function memberPeriods(int $userId, array $in): array
{
    [$limit,$offset] = pageArgs($in);
    return rows("SELECT m.*, p.name AS plan_name, p.duration_days,
        CASE WHEN m.ends_at<=NOW() THEN 'EXPIRED' WHEN m.starts_at>NOW() THEN 'FUTURE' ELSE 'ACTIVE' END AS status
        FROM memberships m JOIN orders o ON o.order_id=m.order_id JOIN membership_plans p ON p.plan_id=o.plan_id
        WHERE o.user_id=? AND o.status='PAID' ORDER BY m.starts_at DESC LIMIT $limit OFFSET $offset", [$userId]);
}

function orderInfo(int $id, array $actor): array
{
    $order = one('SELECT o.order_id,o.user_id,o.plan_id,o.method,o.status,o.created_at,
        p.name AS plan_name,p.price,p.duration_days,p.version FROM orders o
        JOIN membership_plans p ON p.plan_id=o.plan_id WHERE o.order_id=?
        AND (?=\'ADMIN\' OR o.user_id=?)', [$id, $actor['role'], $actor['user_id']])
        ?? fail('NOT_FOUND', 'Order not found.', 404);
    return [
        'order' => $order,
        'payments' => rows('SELECT payment_id,status,paid_at,attempted_at FROM payments WHERE order_id=? ORDER BY payment_id', [$id]),
        'membership' => one('SELECT membership_id,order_id,starts_at,ends_at FROM memberships WHERE order_id=?', [$id]),
    ];
}

function requestKey(array $in, string $name): string
{
    $key = field($in, $name, 100);
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $key)) {
        fail('INVALID_KEY', 'Use letters, numbers, underscores or hyphens in the request key.');
    }
    return $key;
}

function createOrder(array $in, array $member): array
{
    $id = (int)$member['user_id'];
    $plan = positive($in, 'plan_id');
    $method = choice($in, 'method', ['SIMULATED','OFFLINE']);
    $key = requestKey($in, 'request_key');
    return tx(function () use ($id,$plan,$method,$key,$member) {
        lockUser($id);
        $existing = one('SELECT order_id,plan_id,method FROM orders WHERE user_id=? AND request_key=?', [$id,$key]);
        if ($existing) {
            if ((int)$existing['plan_id'] !== $plan || $existing['method'] !== $method) {
                fail('KEY_REUSED', 'This request key belongs to a different order.', 409);
            }
            return orderInfo((int)$existing['order_id'], $member);
        }
        $version = one('SELECT status FROM membership_plans WHERE plan_id=? FOR UPDATE', [$plan]);
        if (!$version || $version['status'] !== 'ACTIVE') {
            fail('PLAN_UNAVAILABLE', 'Choose an active plan.', 409);
        }
        run("INSERT INTO orders(user_id,plan_id,method,request_key,status) VALUES (?,?,?,?,'PENDING')", [$id,$plan,$method,$key]);
        return orderInfo((int)db()->lastInsertId(), $member);
    });
}

function changeOrder(string $action, array $in): array
{
    $actor = $action === 'orders.confirm' ? needRole('ADMIN') : needRole('MEMBER');
    $id = positive($in, 'order_id');
    // Ownership and plan references cannot change. Resolve the member before taking any locks.
    $initial = orderInfo($id, $actor)['order'];
    $method = $action === 'orders.confirm' ? 'OFFLINE' : 'SIMULATED';
    $outcome = $action === 'orders.pay' ? choice($in + ['result'=>'SUCCEEDED'], 'result', ['SUCCEEDED','FAILED']) : 'SUCCEEDED';
    $key = isset($in['attempt_key']) ? requestKey($in, 'attempt_key') : bin2hex(random_bytes(16));
    return tx(function () use ($id,$actor,$action,$initial,$method,$outcome,$key) {
        lockUser((int)$initial['user_id']);
        $order = one('SELECT status,method,plan_id,user_id FROM orders WHERE order_id=? FOR UPDATE', [$id])
            ?? fail('NOT_FOUND', 'Order not found.', 404);
        if ($action === 'orders.cancel') {
            if ($order['status'] === 'PAID') {
                fail('ORDER_PAID', 'A settled order cannot be cancelled.', 409);
            }
            run("UPDATE orders SET status='CANCELLED' WHERE order_id=? AND status='PENDING'", [$id]);
            return orderInfo($id, $actor);
        }
        if ($order['method'] !== $method) {
            fail('WRONG_METHOD', 'This payment method requires a different confirmation route.', 409);
        }
        if ($order['status'] === 'CANCELLED') {
            fail('ORDER_CANCELLED', 'This order was cancelled.', 409);
        }
        $prior = one('SELECT status FROM payments WHERE order_id=? AND attempt_key=?', [$id,$key]);
        if ($prior && $prior['status'] !== $outcome) {
            fail('KEY_REUSED', 'This payment key was used with a different result.', 409);
        }
        if ($prior || $order['status'] === 'PAID') {
            return orderInfo($id, $actor);
        }
        if ($outcome === 'FAILED') {
            run("INSERT INTO payments(order_id,attempt_key,status,paid_at) VALUES (?,?,'FAILED',NULL)", [$id,$key]);
            return orderInfo($id, $actor);
        }
        $paidAt = one('SELECT NOW() AS at')['at'];
        $period = one("SELECT GREATEST(?,COALESCE(MAX(m.ends_at),?)) AS start_at
            FROM orders o JOIN memberships m ON m.order_id=o.order_id
            WHERE o.user_id=? AND o.status='PAID'", [$paidAt,$paidAt,$order['user_id']]);
        run("INSERT INTO payments(order_id,attempt_key,status,paid_at) VALUES (?,?,'SUCCEEDED',?)", [$id,$key,$paidAt]);
        run("UPDATE orders SET status='PAID' WHERE order_id=?", [$id]);
        run('INSERT INTO memberships(order_id,starts_at,ends_at)
            SELECT ?,?,DATE_ADD(?, INTERVAL duration_days DAY) FROM membership_plans WHERE plan_id=?',
            [$id,$period['start_at'],$period['start_at'],$order['plan_id']]);
        return orderInfo($id, $actor);
    });
}

function billingRoutes(string $action, array $in): mixed
{
    switch ($action) {
        case 'plans.list':
            [$limit,$offset] = pageArgs($in);
            $all = ($in['all'] ?? '') === '1';
            if ($all) {
                needRole('ADMIN');
            }
            $where = $all ? '' : "WHERE status='ACTIVE'";
            return rows("SELECT plan_id,name,version,price,duration_days,status FROM membership_plans $where
                ORDER BY duration_days,name,version DESC LIMIT $limit OFFSET $offset");
        case 'plans.create':
            needRole('ADMIN');
            $name = field($in, 'name', 100);
            $days = positive($in, 'duration_days');
            $price = $in['price'] ?? null;
            if (!is_scalar($price) || !preg_match('/^\d{1,8}(\.\d{1,2})?$/D', (string)$price)
                || (float)$price <= 0 || $days > 3650) {
                fail('INVALID_PLAN', 'Supply a positive price with up to two decimals and a duration of 1 to 3650 days.');
            }
            return tx(function () use ($name,$days,$price) {
                $v = one('SELECT COALESCE(MAX(version),0)+1 AS next_version FROM membership_plans WHERE name=?', [$name]);
                run("INSERT INTO membership_plans(name,version,price,duration_days,status) VALUES (?,?,?,?,'ACTIVE')",
                    [$name,$v['next_version'],$price,$days]);
                return one('SELECT * FROM membership_plans WHERE plan_id=?', [db()->lastInsertId()]);
            });
        case 'plans.status':
            needRole('ADMIN');
            $id = positive($in, 'plan_id');
            run('UPDATE membership_plans SET status=? WHERE plan_id=?', [choice($in,'status',['ACTIVE','INACTIVE']),$id]);
            return one('SELECT * FROM membership_plans WHERE plan_id=?', [$id]) ?? fail('NOT_FOUND','Plan not found.',404);
        case 'memberships.list':
            return memberPeriods((int)needRole('MEMBER')['user_id'], $in);
        case 'orders.create':
            return createOrder($in, needRole('MEMBER'));
        case 'orders.pay':
        case 'orders.confirm':
        case 'orders.cancel':
            return changeOrder($action, $in);
        case 'orders.detail':
        case 'orders.list':
            $actor = viewer() ?? fail('LOGIN_REQUIRED','Sign in to see orders.',401);
            if ($action === 'orders.detail') {
                return orderInfo(positive($in, 'order_id'), $actor);
            }
            [$limit,$offset] = pageArgs($in);
            $where = [];
            $params = [];
            if ($actor['role'] === 'MEMBER' || isset($in['user_id'])) {
                $where[] = 'o.user_id=?';
                $params[] = $actor['role'] === 'MEMBER' ? $actor['user_id'] : positive($in,'user_id');
            }
            if (!empty($in['status'])) {
                $where[] = 'o.status=?';
                $params[] = choice($in,'status',['PENDING','PAID','CANCELLED']);
            }
            if (!empty($in['method'])) {
                $where[] = 'o.method=?';
                $params[] = choice($in,'method',['SIMULATED','OFFLINE']);
            }
            foreach (['from'=>'>=', 'until'=>'<'] as $field => $comparison) {
                if (isset($in[$field])) {
                    $d = field($in, $field, 10);
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
                    if (!$date || $date->format('Y-m-d') !== $d) {
                        fail('INVALID_DATE', 'Use YYYY-MM-DD for date filters.');
                    }
                    $where[] = "o.created_at $comparison ?";
                    $params[] = $d;
                }
            }
            $filter = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            return rows("SELECT o.order_id,o.user_id,o.plan_id,o.created_at,o.status,o.method,p.name AS plan_name,p.price
                FROM orders o JOIN membership_plans p ON p.plan_id=o.plan_id $filter
                ORDER BY o.order_id DESC LIMIT $limit OFFSET $offset", $params);
    }
    fail('NOT_FOUND', 'Unknown billing operation.', 404);
}
