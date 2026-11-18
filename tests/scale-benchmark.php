<?php
// CLI only. Point FORM_CONFIG at a disposable, scaled test database.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

function measureSql(PDO $pdo, string $sql, array $args, bool $fetch = false): array
{
    $start = hrtime(true);
    $s = $pdo->prepare($sql);
    $s->execute($args);
    $result = $fetch ? $s->fetchAll() : $s->rowCount();
    return [(hrtime(true) - $start) / 1e6, $result];
}

function distribution(array $values): array
{
    sort($values);
    $n = count($values);
    return [
        'n' => $n,
        'median_ms' => round(($values[intdiv($n - 1, 2)] + $values[intdiv($n, 2)]) / 2, 3),
        'p95_ms' => round($values[(int)ceil(0.95 * $n) - 1], 3),
        'max_ms' => round(max($values), 3),
    ];
}

$pdo = db();
$tables = [
    'users' => 'user_id', 'membership_plans' => 'plan_id', 'orders' => 'order_id',
    'payments' => 'payment_id', 'memberships' => 'membership_id', 'coaches' => 'coach_id',
    'venues' => 'venue_id', 'courses' => 'course_id', 'class_sessions' => 'session_id',
    'bookings' => 'booking_id', 'attendance' => 'attendance_id',
];
$report = ['environment' => one('SELECT VERSION() AS db_version, @@tx_isolation AS isolation_level,
    @@innodb_buffer_pool_size AS buffer_pool_bytes, @@innodb_flush_log_at_trx_commit AS flush_on_commit'),
    'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'rows' => []];
foreach ($tables as $table => $pk) {
    $report['rows'][$table] = (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
$report['mean_rows'] = array_sum($report['rows']) / count($tables);
if ($report['mean_rows'] < 10000 || count(array_filter($report['rows'], fn($n) => $n > 50000)) < 2) {
    throw new RuntimeException('Load form_club.sql and scale_fixture.sql into the disposable benchmark database first.');
}
$report['integrity'] = [];
$checks = [
    'sale' => "SELECT COUNT(*) FROM orders o WHERE
        (o.status='PAID') <> EXISTS(SELECT 1 FROM payments p WHERE p.order_id=o.order_id AND p.status='SUCCEEDED')
        OR (o.status='PAID') <> EXISTS(SELECT 1 FROM memberships m WHERE m.order_id=o.order_id)",
    'period_length' => 'SELECT COUNT(*) FROM memberships m JOIN orders o USING(order_id)
        JOIN membership_plans p USING(plan_id) WHERE m.ends_at <> m.starts_at + INTERVAL p.duration_days DAY',
    'role' => "SELECT COUNT(*) FROM users u WHERE u.role <> 'MEMBER' AND
        (EXISTS(SELECT 1 FROM orders o WHERE o.user_id=u.user_id)
         OR EXISTS(SELECT 1 FROM bookings b WHERE b.user_id=u.user_id))",
    'period_coverage' => "WITH periods AS (
        SELECT o.user_id,m.starts_at,m.ends_at,MAX(m.ends_at) OVER (
          PARTITION BY o.user_id ORDER BY m.starts_at,m.membership_id
          ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) AS preceding_end,m.membership_id
        FROM memberships m JOIN orders o USING(order_id) WHERE o.status='PAID'
      ), groups_ AS (
        SELECT periods.*, SUM(IF(preceding_end IS NULL OR preceding_end<starts_at,1,0)) OVER
          (PARTITION BY user_id ORDER BY starts_at,membership_id) AS grp FROM periods
      ), coverage AS (
        SELECT user_id,MIN(starts_at) AS start_,MAX(ends_at) AS end_ FROM groups_ GROUP BY user_id,grp
      ) SELECT COUNT(*) FROM bookings b JOIN class_sessions s USING(session_id)
        LEFT JOIN coverage c ON c.user_id=b.user_id AND c.start_<=s.starts_at AND c.end_>=s.ends_at
        WHERE b.status='CONFIRMED' AND c.user_id IS NULL",
    'coach_overlap' => "SELECT COUNT(*) FROM class_sessions a JOIN class_sessions b ON
        b.coach_id=a.coach_id AND b.starts_at>=a.starts_at AND b.starts_at<a.ends_at AND b.ends_at>a.starts_at
        AND (b.starts_at>a.starts_at OR b.session_id>a.session_id)
        WHERE a.status<>'CANCELLED' AND b.status<>'CANCELLED'",
    'venue_overlap' => "SELECT COUNT(*) FROM class_sessions a JOIN class_sessions b ON
        b.venue_id=a.venue_id AND b.starts_at>=a.starts_at AND b.starts_at<a.ends_at AND b.ends_at>a.starts_at
        AND (b.starts_at>a.starts_at OR b.session_id>a.session_id)
        WHERE a.status<>'CANCELLED' AND b.status<>'CANCELLED'",
    'member_overlap' => "SELECT COUNT(*) FROM (SELECT s.starts_at,MAX(s.ends_at) OVER
        (PARTITION BY b.user_id ORDER BY s.starts_at,b.booking_id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING)
        AS previous_end FROM bookings b JOIN class_sessions s USING(session_id) WHERE b.status='CONFIRMED') t
        WHERE previous_end > starts_at",
    'attendance' => "SELECT COUNT(*) FROM attendance a JOIN bookings b USING(booking_id)
        JOIN class_sessions s USING(session_id) WHERE b.status<>'CONFIRMED' OR s.status='CANCELLED'
        OR (a.status='ABSENT' AND (s.status<>'COMPLETED' OR s.ends_at>NOW() OR a.checked_at IS NOT NULL))
        OR (a.status='PRESENT' AND (a.checked_at IS NULL OR a.checked_at<s.starts_at-INTERVAL 30 MINUTE
                                     OR a.checked_at>s.starts_at+INTERVAL 15 MINUTE))",
    'unfinalized' => "SELECT COUNT(*) FROM bookings b JOIN class_sessions s USING(session_id)
        LEFT JOIN attendance a USING(booking_id) WHERE b.status='CONFIRMED'
        AND s.status='COMPLETED' AND a.attendance_id IS NULL",
    'capacity' => "SELECT COUNT(*) FROM class_sessions s JOIN venues v USING(venue_id)
        WHERE s.capacity>v.capacity OR (SELECT COUNT(*) FROM bookings b
        WHERE b.session_id=s.session_id AND b.status='CONFIRMED')>s.capacity",
];
foreach ($checks as $name => $sql) {
    $report['integrity'][$name] = (int)$pdo->query($sql)->fetchColumn();
    if ($report['integrity'][$name]) {
        throw new RuntimeException("Invalid dataset: $name");
    }
}

// FK order is deliberate. The complete graph represents yesterday's finished class,
// a paid member and a recorded visit. Every round gets its own disposable rows.
$statementMs = [];
$committedBatchMs = [];
$demoHash = $pdo->query("SELECT password_hash FROM users WHERE user_id=2")->fetchColumn();
for ($round = 0; $round < 10; ++$round) {
    $tag = 'bench-' . bin2hex(random_bytes(8));
    $ids = [];
    $insert = function(string $table, string $sql, array $args = []) use ($pdo, &$ids, &$statementMs) {
        [$ms, $changed] = measureSql($pdo, $sql, $args);
        if ($changed !== 1) {
            throw new RuntimeException("Expected one inserted row in $table");
        }
        $ids[$table] = (int)$pdo->lastInsertId();
        $statementMs[$table]['insert'][] = $ms;
    };
    try {
        $start = hrtime(true);
        $pdo->beginTransaction();
        $insert('users', 'INSERT INTO users(email,password_hash,full_name) VALUES (?,?,?)', ["$tag@form.test",$demoHash,$tag]);
        $insert('membership_plans', 'INSERT INTO membership_plans(name,price,duration_days) VALUES (?,299,30)', [$tag]);
        $insert('orders', "INSERT INTO orders(user_id,plan_id,method,request_key,status,created_at)
            VALUES (?,?,'SIMULATED',?,'PAID',CURRENT_DATE - INTERVAL 4 DAY)", [$ids['users'],$ids['membership_plans'],$tag]);
        $insert('payments', "INSERT INTO payments(order_id,status,attempt_key,attempted_at,paid_at)
            VALUES (?,'SUCCEEDED',?,CURRENT_DATE-INTERVAL 4 DAY,CURRENT_DATE-INTERVAL 4 DAY)", [$ids['orders'],$tag]);
        $insert('memberships', "INSERT INTO memberships(order_id,starts_at,ends_at)
            VALUES (?,CURRENT_DATE-INTERVAL 4 DAY,CURRENT_DATE+INTERVAL 26 DAY)", [$ids['orders']]);
        $insert('coaches', 'INSERT INTO coaches(name) VALUES (?)', [$tag]);
        $insert('venues', "INSERT INTO venues(name,location,capacity) VALUES (?,'Test floor',8)", [$tag]);
        $insert('courses', "INSERT INTO courses(name,description,duration_minutes) VALUES (?,'Timed fixture',60)", [$tag]);
        $insert('class_sessions', "INSERT INTO class_sessions(course_id,coach_id,venue_id,starts_at,ends_at,capacity,status)
            VALUES (?,?,?,CURRENT_DATE-INTERVAL 1 DAY,CURRENT_DATE-INTERVAL 23 HOUR,8,'COMPLETED')",
            [$ids['courses'],$ids['coaches'],$ids['venues']]);
        $insert('bookings', 'INSERT INTO bookings(user_id,session_id,booked_at) VALUES (?,?,CURRENT_DATE-INTERVAL 2 DAY)',
            [$ids['users'],$ids['class_sessions']]);
        $insert('attendance', "INSERT INTO attendance(booking_id,status,checked_at)
            VALUES (?,'PRESENT',CURRENT_DATE-INTERVAL 1 DAY)", [$ids['bookings']]);
        $pdo->commit();
        $committedBatchMs['insert'][] = (hrtime(true) - $start) / 1e6;
        foreach ($tables as $table => $pk) {
            [$ms, $matches] = measureSql($pdo,"SELECT * FROM $table WHERE $pk=?",[$ids[$table]],true);
            if (count($matches) !== 1) {
                throw new RuntimeException("Expected one match in $table");
            }
            $statementMs[$table]['find'][] = $ms;
        }
        $start = hrtime(true);
        $pdo->beginTransaction();
        foreach (array_reverse($tables, true) as $table => $pk) {
            [$ms, $changed] = measureSql($pdo,"DELETE FROM $table WHERE $pk=?",[$ids[$table]]);
            if ($changed !== 1) {
                throw new RuntimeException("Expected one deleted row in $table");
            }
            $statementMs[$table]['delete'][] = $ms;
        }
        $pdo->commit();
        $committedBatchMs['delete'][] = (hrtime(true) - $start) / 1e6;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
$thresholds = ['insert'=>1000, 'delete'=>2000, 'find'=>2000];
$report['within_course_thresholds'] = true;
foreach ($statementMs as $table => $operations) {
    foreach ($operations as $op => $samples) {
        $report['table_ms'][$table][$op] = distribution($samples);
        if (max($samples) >= $thresholds[$op]) {
            $report['within_course_thresholds'] = false;
        }
    }
}
foreach ($committedBatchMs as $op => $values) {
    $report['all_11_rows_in_one_committed_batch'][$op] = distribution($values);
}
$queries = [
    'schedule' => "SELECT session_id, starts_at FROM class_sessions WHERE status='SCHEDULED'
        AND starts_at>=CURRENT_DATE ORDER BY starts_at,session_id LIMIT 20",
    'member_bookings' => 'SELECT b.booking_id,s.starts_at,c.name FROM bookings b JOIN class_sessions s USING(session_id)
        JOIN courses c USING(course_id) WHERE b.user_id=1005 ORDER BY s.starts_at DESC LIMIT 20',
    'roster' => 'SELECT b.booking_id,u.full_name,a.status FROM bookings b JOIN users u USING(user_id)
        LEFT JOIN attendance a USING(booking_id) WHERE b.session_id=2050 ORDER BY b.booking_id',
    'membership' => "SELECT m.starts_at,m.ends_at FROM orders o JOIN memberships m USING(order_id)
        WHERE o.user_id=1005 AND o.status='PAID' ORDER BY m.starts_at",
    'receipts' => "SELECT SUM(mp.price) FROM payments p JOIN orders o USING(order_id) JOIN membership_plans mp USING(plan_id)
        WHERE p.status='SUCCEEDED' AND p.paid_at>=CURRENT_DATE-INTERVAL 1 YEAR",
    'attendance_summary' => "SELECT c.category,COUNT(*),SUM(a.status='PRESENT') FROM class_sessions s
        JOIN courses c USING(course_id) JOIN bookings b USING(session_id) JOIN attendance a USING(booking_id)
        WHERE s.status='COMPLETED' AND s.starts_at>=CURRENT_DATE-INTERVAL 30 DAY AND b.status='CONFIRMED' GROUP BY c.category",
];
foreach ($queries as $name => $sql) {
    $times = [];
    for ($n=0; $n<10; ++$n) {
        [$times[]] = measureSql($pdo, $sql, [], true);
    }
    $report['business_queries'][$name] = distribution($times);
    $report['plans'][$name] = $pdo->query('EXPLAIN ' . $sql)->fetchAll();
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($report['within_course_thresholds'] ? 0 : 1);
