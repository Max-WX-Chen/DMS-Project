<?php
declare(strict_types=1);

function classActions(): array
{
    return [
        'sessions.list' => 'GET', 'sessions.detail' => 'GET', 'sessions.roster' => 'GET',
        'bookings.list' => 'GET', 'attendance.list' => 'GET', 'resources.list' => 'GET',
        'reports.summary' => 'GET', 'dashboard.summary' => 'GET',
        'resources.save' => 'POST', 'sessions.save' => 'POST', 'sessions.cancel' => 'POST',
        'sessions.complete' => 'POST', 'bookings.create' => 'POST', 'bookings.cancel' => 'POST',
        'attendance.checkin' => 'POST'
    ];
}

function classRoutes(string $action, array $input): mixed
{
    if ($action === 'sessions.list') {
        return fcSessions($input);
    }
    if ($action === 'sessions.detail') {
        $s = fcSessionDetail(positive($input, 'session_id'));
        $member = viewer();
        $s['can_book'] = false;
        $s['booking_id'] = null;
        $s['booking_reason'] = $member ? 'MEMBER_REQUIRED' : 'LOGIN_REQUIRED';
        if (($member['role'] ?? '') === 'MEMBER') {
            $uid = (int)$member['user_id'];
            $b = one('SELECT booking_id,status FROM bookings WHERE user_id=? AND session_id=?', [$uid,$s['session_id']]);
            $s['booking_id'] = $b['booking_id'] ?? null;
            if (($b['status'] ?? '') === 'CONFIRMED') {
                $s['booking_reason'] = 'ALREADY_BOOKED';
            } else {
                try {
                    fcRequireBookable($uid, $s, fcNow());
                    $s['can_book'] = true;
                    $s['booking_reason'] = null;
                } catch (BusinessError $e) {
                    $s['booking_reason'] = $e->reason;
                }
            }
        }
        return $s;
    }
    if ($action === 'resources.list') {
        return fcResources($input);
    }
    if (in_array($action, ['bookings.list', 'attendance.list', 'bookings.create', 'bookings.cancel'], true)) {
        $uid = (int)needRole('MEMBER')['user_id'];
        return match ($action) {
            'bookings.list' => fcBookings($input, $uid),
            'attendance.list' => fcAttendance($input, $uid),
            'bookings.create' => fcBook($uid, positive($input, 'session_id')),
            'bookings.cancel' => fcCancelBooking($uid, positive($input, 'booking_id')),
        };
    }
    needRole('ADMIN');
    return match ($action) {
        'resources.save' => fcSaveResource($input),
        'sessions.save' => fcSaveSession($input),
        'sessions.cancel' => fcCloseSession(positive($input, 'session_id'), 'CANCELLED'),
        'sessions.complete' => fcCloseSession(positive($input, 'session_id'), 'COMPLETED'),
        'attendance.checkin' => fcCheckin(positive($input, 'booking_id')),
        'sessions.roster' => fcRoster($input),
        'reports.summary' => fcReport($input),
        'dashboard.summary' => fcDashboard(),
        default => fail('NOT_FOUND', 'Unknown class action.', 404)
    };
}

function fcEnum(array $in, string $key, array $allowed, ?string $fallback = null): string
{
    $value = $in[$key] ?? $fallback;
    if (!in_array($value, $allowed, true)) {
        fail('INVALID_INPUT', 'Invalid ' . $key . '.');
    }
    return $value;
}

function fcDate(string $value, bool $withTime = false): string
{
    if ($withTime) {
        $value = str_replace('T', ' ', $value);
        if (strlen($value) === 16) {
            $value .= ':00';
        }
    }
    $format = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';
    $dt = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('Asia/Shanghai'));
    if (!$dt || $dt->format($format) !== $value || (int)$dt->format('Y') < 1000) {
        fail('INVALID_DATE', 'Use a valid local date or date and time in Asia/Shanghai.');
    }
    return $value;
}

function fcNow(): string
{
    return one('SELECT NOW() AS ts')['ts'];
}

function fcPage(array $input, string $sql, array $params): array
{
    [$limit, $offset] = pageArgs($input);
    return ['items' => rows("$sql LIMIT $limit OFFSET $offset", $params), 'limit' => $limit, 'offset' => $offset];
}

function fcSessionSelect(): string
{
    return "SELECT s.session_id,s.course_id,s.coach_id,s.venue_id,s.starts_at,s.ends_at,s.capacity,s.status,
                   c.name AS course_name,c.category,c.description,h.name AS coach_name,
                   v.name AS venue_name,v.location,
                   (SELECT COUNT(*) FROM bookings b WHERE b.session_id=s.session_id AND b.status='CONFIRMED') AS booked
              FROM class_sessions s JOIN courses c ON c.course_id=s.course_id
              JOIN coaches h ON h.coach_id=s.coach_id JOIN venues v ON v.venue_id=s.venue_id";
}

function fcSpots(array $session): array
{
    $session['spots_left'] = $session['status'] === 'SCHEDULED' ? (int)$session['capacity'] - (int)$session['booked'] : 0;
    return $session;
}

function fcSessionDetail(int $sid): array
{
    $s = one(fcSessionSelect() . ' WHERE s.session_id=?', [$sid]);
    if (!$s) {
        fail('NOT_FOUND', 'Session not found.', 404);
    }
    return fcSpots($s);
}

function fcSessions(array $in): array
{
    $scope = fcEnum($in, 'scope', ['upcoming', 'past', 'all'], 'upcoming');
    $where = ['1=1'];
    $args = [];
    if ($scope !== 'all') {
        $where[] = $scope === 'upcoming' ? 's.starts_at >= NOW()' : 's.starts_at < NOW()';
    }
    if (($in['date'] ?? '') !== '') {
        $date = fcDate(field($in, 'date', 10));
        $where[] = 's.starts_at >= ? AND s.starts_at < DATE_ADD(?, INTERVAL 1 DAY)';
        array_push($args, $date, $date);
    }
    foreach (['coach_id','course_id','venue_id'] as $id) {
        if (($in[$id] ?? '') !== '') {
            $where[] = "s.$id=?";
            $args[] = positive($in, $id);
        }
    }
    if (($in['type'] ?? '') !== '') {
        $where[] = 'c.category=?';
        $args[] = field($in, 'type', 80);
    }
    if (($in['status'] ?? '') !== '') {
        $where[] = 's.status=?';
        $args[] = fcEnum($in, 'status', ['SCHEDULED','CANCELLED','COMPLETED']);
    }
    $result = fcPage($in, fcSessionSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.starts_at,s.session_id', $args);
    $result['items'] = array_map('fcSpots', $result['items']);
    return $result;
}

// Table and column identifiers used in SQL come exclusively from this fixed mapping.
function fcResourceSpec(array $in): array
{
    return match (fcEnum($in, 'kind', ['coach','venue','course'])) {
        'coach' => ['coaches','coach_id','specialty'],
        'venue' => ['venues','venue_id','location,capacity'],
        'course' => ['courses','course_id','description,duration_minutes,category']
    };
}

function fcResources(array $in): array
{
    [$table,$pk,$columns] = fcResourceSpec($in);
    $where = (viewer()['role'] ?? '') === 'ADMIN' ? '' : " WHERE status='ACTIVE'";
    return fcPage($in, "SELECT $pk,name,$columns,status FROM $table$where ORDER BY name,$pk", []);
}

function fcSaveResource(array $in): array
{
    [$table,$pk,$details] = fcResourceSpec($in);
    $id = isset($in['id']) && $in['id'] !== '' ? positive($in, 'id') : null;
    $name = field($in, 'name', 120);
    $status = fcEnum($in, 'status', ['ACTIVE','INACTIVE'], 'ACTIVE');
    $columns = ['name','status'];
    $args = [$name,$status];
    foreach (explode(',', $details) as $col) {
        $columns[] = $col;
        if (in_array($col, ['capacity','duration_minutes'], true)) {
            $value = positive($in, $col);
        } elseif ($col === 'category') {
            $value = field($in + ['category' => 'General'], $col, 80);
        } else {
            $value = ($in[$col] ?? '') === '' ? '' : field($in, $col, $col === 'description' ? 16000 : 255);
        }
        $args[] = $value;
    }
    return tx(function () use ($id,$table,$pk,$columns,$args,$status,$in) {
        if ($id !== null) {
            if (!one("SELECT $pk FROM $table WHERE $pk=? FOR UPDATE", [$id])) {
                fail('NOT_FOUND', 'Resource not found.', 404);
            }
            $future = "SELECT 1 AS found FROM class_sessions WHERE $pk=? AND status='SCHEDULED' AND ends_at>NOW()";
            if ($status === 'INACTIVE' && one("$future LIMIT 1", [$id])) {
                fail('RESOURCE_IN_USE', 'Cancel or reassign scheduled sessions before disabling this resource.', 409);
            }
            if ($table === 'venues' && one("$future AND capacity>? LIMIT 1", [$id,$in['capacity']])) {
                fail('RESOURCE_IN_USE', 'A scheduled session exceeds this venue capacity.', 409);
            }
            $updates = implode(',', array_map(static fn($c) => "$c=?", $columns));
            run("UPDATE $table SET $updates WHERE $pk=?", [...$args,$id]);
        } else {
            $placeholders = implode(',', array_fill(0, count($args), '?'));
            run("INSERT INTO $table (" . implode(',', $columns) . ") VALUES ($placeholders)", $args);
            $id = (int)db()->lastInsertId();
        }
        return one("SELECT * FROM $table WHERE $pk=?", [$id]);
    });
}

function fcLockSession(int $sid): array
{
    $session = one('SELECT * FROM class_sessions WHERE session_id=? FOR UPDATE', [$sid]);
    if (!$session) {
        fail('NOT_FOUND', 'Session not found.', 404);
    }
    return $session;
}

function fcSaveSession(array $in): array
{
    $id = isset($in['session_id']) && $in['session_id'] !== '' ? positive($in, 'session_id') : null;
    $course = positive($in, 'course_id');
    $coach = positive($in, 'coach_id');
    $venue = positive($in, 'venue_id');
    $capacity = positive($in, 'capacity');
    $start = fcDate(field($in, isset($in['starts_at']) ? 'starts_at' : 'start_time'), true);
    $end = fcDate(field($in, isset($in['ends_at']) ? 'ends_at' : 'end_time'), true);
    return tx(function () use ($id,$course,$coach,$venue,$capacity,$start,$end) {
        // Resource rows serialize both publication and resource maintenance, even when no sessions exist yet.
        foreach ([['courses','course_id',$course],['coaches','coach_id',$coach],['venues','venue_id',$venue]] as [$table,$pk,$key]) {
            $resource = one("SELECT * FROM $table WHERE $pk=? FOR UPDATE", [$key]);
            if (!$resource || $resource['status'] !== 'ACTIVE') {
                fail('RESOURCE_UNAVAILABLE', 'A selected resource is unavailable.', 409);
            }
            if ($pk === 'venue_id' && $capacity > (int)$resource['capacity']) {
                fail('VENUE_CAPACITY', 'Session capacity exceeds the venue capacity.');
            }
        }
        $old = $id === null ? null : fcLockSession($id);
        $now = fcNow();
        if ($start <= $now || $end <= $start) {
            fail('INVALID_INTERVAL', 'Provide a future session with end after start.');
        }
        if ($old !== null) {
            if ($old['status'] !== 'SCHEDULED' || $old['starts_at'] <= $now) {
                fail('SESSION_CLOSED', 'Only upcoming scheduled sessions can be edited.', 409);
            }
            $changed = $old['starts_at'] !== $start || $old['ends_at'] !== $end
                || (int)$old['course_id'] !== $course || (int)$old['coach_id'] !== $coach
                || (int)$old['venue_id'] !== $venue || (int)$old['capacity'] > $capacity;
            if ($changed && one("SELECT 1 AS found FROM bookings WHERE session_id=? AND status='CONFIRMED' LIMIT 1", [$id])) {
                fail('SESSION_HAS_BOOKINGS', 'Cancel and republish to change a booked session.', 409);
            }
        }
        $overlap = "SELECT session_id FROM class_sessions WHERE status='SCHEDULED' AND session_id<>?
                      AND (coach_id=? OR venue_id=?) AND starts_at<? AND ends_at>? LIMIT 1";
        if (one($overlap, [$id ?? 0,$coach,$venue,$end,$start])) {
            fail('SCHEDULE_CONFLICT', 'Coach or venue already has an overlapping session.', 409);
        }
        $args = [$course,$coach,$venue,$start,$end,$capacity];
        if ($id) {
            run('UPDATE class_sessions SET course_id=?,coach_id=?,venue_id=?,starts_at=?,ends_at=?,capacity=? WHERE session_id=?', [...$args,$id]);
        } else {
            run("INSERT INTO class_sessions(course_id,coach_id,venue_id,starts_at,ends_at,capacity,status) VALUES(?,?,?,?,?,?,'SCHEDULED')", $args);
            $id = (int)db()->lastInsertId();
        }
        return fcSessionDetail($id);
    });
}

function fcLockMember(int $uid): void
{
    if (lockUser($uid)['role'] !== 'MEMBER') {
        fail('ACCESS_DENIED', 'An active member account is required.', 403);
    }
}

function fcCovered(int $uid, string $start, string $end): bool
{
    $periods = rows("SELECT m.starts_at,m.ends_at FROM orders o JOIN memberships m ON m.order_id=o.order_id
        WHERE o.user_id=? AND o.status='PAID' AND m.starts_at<? AND m.ends_at>? ORDER BY m.starts_at,m.ends_at", [$uid,$end,$start]);
    $through = $start;
    foreach ($periods as $period) {
        if ($period['starts_at'] > $through) {
            return false;
        }
        $through = max($through, $period['ends_at']);
        if ($through >= $end) {
            return true;
        }
    }
    return false;
}

function fcBook(int $uid, int $sid): array
{
    return tx(function () use ($uid,$sid) {
        fcLockMember($uid);
        $s = fcLockSession($sid);
        $b = one('SELECT * FROM bookings WHERE user_id=? AND session_id=?', [$uid,$sid]);
        if ($s['status'] !== 'SCHEDULED') {
            fail('SESSION_CLOSED', 'This session is closed.', 409);
        }
        if ($b && $b['status'] === 'CONFIRMED') {
            return $b + ['spots_left' => fcSessionDetail($sid)['spots_left']];
        }
        $now = fcNow();
        $s = fcSessionDetail($sid);
        fcRequireBookable($uid, $s, $now);
        if ($b) {
            if ($b['status'] !== 'CANCELLED_BY_MEMBER') {
                fail('SESSION_CLOSED', 'A club cancellation cannot be restored.', 409);
            }
            run("UPDATE bookings SET status='CONFIRMED',booked_at=?,cancelled_at=NULL WHERE booking_id=?", [$now,$b['booking_id']]);
            $bid = $b['booking_id'];
        } else {
            run("INSERT INTO bookings(user_id,session_id,status,booked_at) VALUES(?,?,'CONFIRMED',?)", [$uid,$sid,$now]);
            $bid = db()->lastInsertId();
        }
        return one('SELECT * FROM bookings WHERE booking_id=?', [$bid]) + ['spots_left' => $s['spots_left'] - 1];
    });
}

function fcRequireBookable(int $uid, array $s, string $now): void
{
    if ($s['status'] !== 'SCHEDULED') {
        fail('SESSION_CLOSED', 'This session is closed.', 409);
    }
    $opens = (new DateTimeImmutable($s['starts_at']))->modify('-30 days')->format('Y-m-d H:i:s');
    if ($now < $opens || $now >= $s['starts_at']) {
        fail('BOOKING_WINDOW', 'Book within 30 days, before class starts.', 409);
    }
    if (!fcCovered($uid, $s['starts_at'], $s['ends_at'])) {
        fail('MEMBERSHIP_COVERAGE', 'Paid membership must cover the entire session.', 409);
    }
    if ((int)$s['spots_left'] < 1) {
        fail('CLASS_FULL', 'No places remain.', 409);
    }
    if (one("SELECT b.booking_id FROM bookings b JOIN class_sessions s ON s.session_id=b.session_id
              WHERE b.user_id=? AND b.status='CONFIRMED' AND s.status='SCHEDULED'
                AND s.starts_at<? AND s.ends_at>? LIMIT 1", [$uid,$s['ends_at'],$s['starts_at']])) {
        fail('BOOKING_CONFLICT', 'You have an overlapping booking.', 409);
    }
}

function fcCancellationOpen(string $sessionStatus, string $startsAt, string $now): bool
{
    $cutoff = (new DateTimeImmutable($startsAt))->modify('-2 hours')->format('Y-m-d H:i:s');
    return $sessionStatus === 'SCHEDULED' && $now <= $cutoff;
}

function fcCancelBooking(int $uid, int $bid): array
{
    return tx(function () use ($uid,$bid) {
        fcLockMember($uid);
        $ref = one('SELECT session_id FROM bookings WHERE booking_id=? AND user_id=?', [$bid,$uid]);
        if (!$ref) {
            fail('NOT_FOUND', 'Booking not found.', 404);
        }
        $s = fcLockSession((int)$ref['session_id']);
        $b = one('SELECT * FROM bookings WHERE booking_id=? AND user_id=?', [$bid,$uid]);
        if ($b['status'] !== 'CONFIRMED') {
            return $b;
        }
        $now = fcNow();
        if (!fcCancellationOpen($s['status'], $s['starts_at'], $now)) {
            fail('CANCEL_WINDOW', 'Cancel at least two hours before a scheduled session.', 409);
        }
        run("UPDATE bookings SET status='CANCELLED_BY_MEMBER',cancelled_at=? WHERE booking_id=?", [$now,$bid]);
        return one('SELECT * FROM bookings WHERE booking_id=?', [$bid]);
    });
}

function fcCheckin(int $bid): array
{
    return tx(function () use ($bid) {
        $ref = one('SELECT session_id FROM bookings WHERE booking_id=?', [$bid]);
        if (!$ref) {
            fail('NOT_FOUND', 'Booking not found.', 404);
        }
        $s = fcLockSession((int)$ref['session_id']);
        $b = one('SELECT status FROM bookings WHERE booking_id=?', [$bid]);
        if ($b['status'] !== 'CONFIRMED' || $s['status'] === 'CANCELLED') {
            fail('INVALID_BOOKING', 'Only confirmed bookings can check in.', 409);
        }
        $existing = one('SELECT * FROM attendance WHERE booking_id=?', [$bid]);
        if ($existing && $existing['status'] === 'PRESENT') {
            return $existing;
        }
        $start = new DateTimeImmutable($s['starts_at']);
        $now = new DateTimeImmutable(fcNow());
        if ($s['status'] !== 'SCHEDULED' || $now < $start->modify('-30 minutes') || $now > $start->modify('+15 minutes')) {
            fail('CHECKIN_WINDOW', 'Check-in opens 30 minutes before and closes 15 minutes after start.', 409);
        }
        run("INSERT INTO attendance(booking_id,status,checked_at) VALUES(?,'PRESENT',?)", [$bid,$now->format('Y-m-d H:i:s')]);
        return one('SELECT * FROM attendance WHERE booking_id=?', [$bid]);
    });
}

function fcCloseSession(int $id, string $target): array
{
    return tx(function () use ($id,$target) {
        $s = fcLockSession($id);
        if ($s['status'] === $target) {
            return fcSessionDetail($id);
        }
        if ($s['status'] !== 'SCHEDULED') {
            fail('SESSION_CLOSED', 'This session is already closed.', 409);
        }
        $now = fcNow();
        if ($target === 'COMPLETED') {
            if ($now < $s['ends_at']) {
                fail('SESSION_IN_PROGRESS', 'Complete the session after its end.', 409);
            }
            run("INSERT INTO attendance(booking_id,status,checked_at)
                 SELECT b.booking_id,'ABSENT',NULL FROM bookings b LEFT JOIN attendance a ON a.booking_id=b.booking_id
                 WHERE b.session_id=? AND b.status='CONFIRMED' AND a.booking_id IS NULL", [$id]);
        } else {
            run("UPDATE bookings SET status='CANCELLED_BY_CENTER',cancelled_at=? WHERE session_id=? AND status='CONFIRMED'", [$now,$id]);
        }
        run('UPDATE class_sessions SET status=? WHERE session_id=?', [$target,$id]);
        return fcSessionDetail($id);
    });
}

function fcBookings(array $in, int $uid): array
{
    $scope = fcEnum($in, 'scope', ['all','upcoming','past','cancelled'], 'all');
    $where = match ($scope) {
        'upcoming' => " AND b.status='CONFIRMED' AND s.starts_at>=NOW()",
        'past' => " AND b.status='CONFIRMED' AND s.starts_at<NOW()",
        'cancelled' => " AND b.status<>'CONFIRMED'",
        default => ''
    };
    $args = [$uid];
    if (($in['status'] ?? '') !== '') {
        $where .= ' AND b.status=?';
        $args[] = fcEnum($in, 'status', ['CONFIRMED','CANCELLED_BY_MEMBER','CANCELLED_BY_CENTER']);
    }
    $result = fcPage($in, "SELECT b.*,s.status AS session_status,s.starts_at,s.ends_at,c.name AS course_name,
                           h.name AS coach_name,v.name AS venue_name,a.status AS attendance_status,a.checked_at
                      FROM bookings b JOIN class_sessions s ON s.session_id=b.session_id JOIN courses c ON c.course_id=s.course_id
                      JOIN coaches h ON h.coach_id=s.coach_id JOIN venues v ON v.venue_id=s.venue_id
                      LEFT JOIN attendance a ON a.booking_id=b.booking_id AND b.status='CONFIRMED' AND s.status<>'CANCELLED'
                     WHERE b.user_id=? $where ORDER BY s.starts_at DESC,b.booking_id DESC", $args);
    $now = fcNow();
    foreach ($result['items'] as &$booking) {
        $booking['can_cancel'] = $booking['status'] === 'CONFIRMED'
            && fcCancellationOpen($booking['session_status'], $booking['starts_at'], $now);
    }
    unset($booking);
    return $result;
}

function fcAttendance(array $in, int $uid): array
{
    return fcPage($in, "SELECT a.*,s.session_id,s.starts_at,s.ends_at,c.name AS course_name
                        FROM bookings b JOIN attendance a ON a.booking_id=b.booking_id
                        JOIN class_sessions s ON s.session_id=b.session_id JOIN courses c ON c.course_id=s.course_id
                        WHERE b.user_id=? AND b.status='CONFIRMED' AND s.status='COMPLETED'
                        ORDER BY s.starts_at DESC,b.booking_id DESC", [$uid]);
}

function fcRoster(array $in): array
{
    $sid = positive($in, 'session_id');
    $session = fcSessionDetail($sid);
    return ['session' => $session] + fcPage($in, "SELECT b.booking_id,b.user_id,u.full_name,b.status AS booking_status,
        CASE WHEN s.status='CANCELLED' AND a.status='PRESENT' THEN 'VOIDED' ELSE a.status END AS attendance_status,
        a.checked_at FROM bookings b JOIN users u ON u.user_id=b.user_id JOIN class_sessions s ON s.session_id=b.session_id
        LEFT JOIN attendance a ON a.booking_id=b.booking_id WHERE b.session_id=? ORDER BY u.full_name,b.booking_id", [$sid]);
}

function fcRate(int $part, int $total): ?float
{
    return $total ? round(100 * $part / $total, 2) : null;
}

function fcReport(array $in): array
{
    $today = substr(fcNow(), 0, 10);
    $from = fcDate(field($in + ['from' => substr($today, 0, 8) . '01'], 'from', 10));
    $through = fcDate(field($in + ['through' => $today], 'through', 10));
    if ($through < $from || $through === '9999-12-31') {
        fail('INVALID_INTERVAL', 'Provide an inclusive report range ending on or after its start and before year 10000.');
    }
    $range = [$from,(new DateTimeImmutable($through))->modify('+1 day')->format('Y-m-d')];
    $plans = rows("SELECT p.plan_id,p.name,p.version,COUNT(*) AS orders_sold,SUM(p.price) AS revenue
                   FROM payments t JOIN orders o ON o.order_id=t.order_id JOIN membership_plans p ON p.plan_id=o.plan_id
                   WHERE o.status='PAID' AND t.status='SUCCEEDED' AND t.paid_at>=? AND t.paid_at<?
                   GROUP BY p.plan_id,p.name,p.version ORDER BY p.name,p.version", $range);
    $popularity = rows("SELECT c.category,COUNT(*) AS eligible,SUM(a.status='PRESENT') AS present,SUM(a.status='ABSENT') AS absent
                     FROM class_sessions s JOIN courses c ON c.course_id=s.course_id
                     JOIN bookings b ON b.session_id=s.session_id AND b.status='CONFIRMED'
                     LEFT JOIN attendance a ON a.booking_id=b.booking_id
                     WHERE s.status='COMPLETED' AND s.starts_at>=? AND s.starts_at<?
                     GROUP BY c.category ORDER BY eligible DESC,c.category", $range);
    $capacity = one("SELECT COALESCE(SUM(s.capacity),0) AS capacity,
                      COALESCE(SUM((SELECT COUNT(*) FROM bookings b WHERE b.session_id=s.session_id AND b.status='CONFIRMED')),0) AS confirmed
                      FROM class_sessions s WHERE s.status<>'CANCELLED' AND s.starts_at>=? AND s.starts_at<?", $range);
    $eligible = $present = $absent = 0;
    foreach ($popularity as &$row) {
        $eligible += (int)$row['eligible'];
        $present += (int)$row['present'];
        $absent += (int)$row['absent'];
        $row['attendance_rate'] = fcRate((int)$row['present'], (int)$row['eligible']);
    }
    unset($row);
    // Sum per-version decimal strings as integer cents; totals retain two decimal places.
    $cents = $orders = 0;
    foreach ($plans as $plan) {
        $cents += (int)str_replace('.', '', $plan['revenue']);
        $orders += (int)$plan['orders_sold'];
    }
    return [
        'from' => $from, 'through' => $through, 'revenue' => intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT),
        'paid_orders' => $orders, 'eligible' => $eligible, 'present' => $present, 'absent' => $absent,
        'attendance_rate' => fcRate($present,$eligible), 'absence_rate' => fcRate($absent,$eligible),
        'capacity' => (int)$capacity['capacity'], 'confirmed' => (int)$capacity['confirmed'],
        'booking_rate' => fcRate((int)$capacity['confirmed'],(int)$capacity['capacity']), 'plans' => $plans, 'popularity' => $popularity
    ];
}

function fcDashboard(): array
{
    $now = fcNow();
    $day = substr($now, 0, 10);
    $sessions = array_map('fcSpots', rows(fcSessionSelect() .
        ' WHERE s.starts_at>=? AND s.starts_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY s.starts_at,s.session_id', [$day,$day]));
    $valid = array_filter($sessions, static fn($s) => $s['status'] !== 'CANCELLED');
    $active = one("SELECT COUNT(DISTINCT o.user_id) AS n FROM memberships m JOIN orders o ON o.order_id=m.order_id
                   JOIN users u ON u.user_id=o.user_id WHERE u.status='ACTIVE' AND o.status='PAID' AND m.starts_at<=? AND m.ends_at>?", [$now,$now]);
    $pending = one("SELECT COUNT(*) AS n FROM orders WHERE method='OFFLINE' AND status='PENDING'");
    return [
        'date' => $day, 'active_members' => (int)$active['n'], 'todays_classes' => count($valid),
        'confirmed' => array_sum(array_column($valid,'booked')), 'capacity' => array_sum(array_column($valid,'capacity')),
        'pending_offline' => (int)$pending['n'], 'sessions' => $sessions
    ];
}
