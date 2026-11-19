<?php
declare(strict_types=1);
// Run with FORM_CONFIG pointing at a seeded, disposable database ending in _test.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/classes.php';

if (PHP_SAPI !== 'cli' || !str_ends_with(one('SELECT DATABASE() AS name')['name'], '_test')) {
    throw new RuntimeException('Use a dedicated CLI test database ending in _test.');
}

function verify(bool $pass, string $case): void
{
    if (!$pass) {
        throw new RuntimeException("Failed: $case");
    }
    echo "PASS $case\n";
}

function clockAt(int $time): void
{
    db()->exec("SET SESSION timestamp=$time");
}

function attempt(int $user, string $action, array $input): array
{
    $_SESSION = ['user_id' => $user];
    try {
        return ['ok' => true, 'data' => classRoutes($action, $input)];
    } catch (BusinessError $e) {
        return ['ok' => false, 'error' => $e->reason];
    }
}

// Each child announces readiness after opening its own connection. The parent
// releases all children together, including on Windows builds without pcntl.
if (($argv[1] ?? '') === 'worker') {
    [$time,$user,$action,$in] = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    clockAt($time);
    echo "READY\n";
    fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'go') {
        exit(2);
    }
    echo json_encode(attempt($user,$action,$in), JSON_THROW_ON_ERROR);
    exit;
}

function race(int $time, array $requests): array
{
    $children = [];
    try {
        foreach ($requests as $request) {
            $payload = base64_encode(json_encode([$time,...$request], JSON_THROW_ON_ERROR));
            $proc = proc_open([PHP_BINARY,__FILE__,'worker',$payload],
                [0 => ['pipe','r'],1 => ['pipe','w'],2 => STDERR], $pipes);
            if (!is_resource($proc)) {
                throw new RuntimeException('Unable to start a PHP worker.');
            }
            $children[] = [$proc,$pipes];
        }
        foreach ($children as [$proc,$pipes]) {
            if (trim((string)fgets($pipes[1])) !== 'READY') {
                throw new RuntimeException('Worker failed to reach the release barrier.');
            }
        }
        foreach ($children as [$proc,$pipes]) {
            fwrite($pipes[0],"go\n");
            fflush($pipes[0]);
        }
        $results = [];
        foreach ($children as [$proc,$pipes]) {
            $results[] = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($children as [$proc,$pipes]) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($proc);
        }
    }
}

function stamp(int $time): string
{
    return date('Y-m-d H:i:s',$time);
}

function makeMember(string $name): int
{
    run("INSERT INTO users(email,password_hash,full_name) VALUES(?,?,'Fixture member')",
        [$name . '@example.test',password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    return (int)db()->lastInsertId();
}

function addPeriod(int $member, int $start): void
{
    $key = bin2hex(random_bytes(8));
    run("INSERT INTO orders(user_id,plan_id,method,request_key,status,created_at) VALUES(?,1,'SIMULATED',?,'PAID',?)",
        [$member,$key,stamp($start)]);
    $oid = (int)db()->lastInsertId();
    run("INSERT INTO payments(order_id,status,paid_at,attempted_at,attempt_key) VALUES(?,'SUCCEEDED',?,?,?)",
        [$oid,stamp($start),stamp($start),$key]);
    run('INSERT INTO memberships(order_id,starts_at,ends_at) VALUES(?,?,?)', [$oid,stamp($start),stamp($start+30*86400)]);
}

$start = strtotime('2032-05-10 18:00:00'); // synthetic local class time, no host clock dependency
$before = $start - 86400;
clockAt($before);
$tag = bin2hex(random_bytes(5));
$a = makeMember("a-$tag");
$b = makeMember("b-$tag");
$c = makeMember("c-$tag");
foreach ([$a,$b] as $member) {
    // Renewals meet halfway through class; neither period alone covers it.
    addPeriod($member,$start + 1800 - 30*86400);
    addPeriod($member,$start + 1800);
}
addPeriod($c,$start + 1800 - 30*86400);
addPeriod($c,$start + 1801);

$course = fcSaveResource(['kind'=>'course','name'=>"Course $tag",'category'=>$tag,'duration_minutes'=>60])['course_id'];
$venues = $coaches = [];
foreach ([0,1,2] as $i) {
    $venues[] = fcSaveResource(['kind'=>'venue','name'=>"Room $tag $i",'capacity'=>8])['venue_id'];
    $coaches[] = fcSaveResource(['kind'=>'coach','name'=>"Coach $tag $i"])['coach_id'];
}
$session = function (int $i, int $start, int $end, int $capacity) use ($course,$venues,$coaches): array {
    return ['course_id'=>$course,'venue_id'=>$venues[$i],'coach_id'=>$coaches[$i],
            'starts_at'=>stamp($start),'ends_at'=>stamp($end),'capacity'=>$capacity];
};
$first = $session(0,$start,$start+3600,1);
$result = race($before, [[1,'sessions.save',$first],[1,'sessions.save',$first]]);
$ok = array_values(array_filter($result, fn($r) => $r['ok']));
verify(count($ok)===1 && in_array('SCHEDULE_CONFLICT',array_column($result,'error'),true), 'simultaneous overlapping publication');
$sid = (int)$ok[0]['data']['session_id'];
$book = ['session_id'=>$sid];
verify(attempt($a,'sessions.roster',$book)['error']==='FORBIDDEN', 'member denied administrator roster');
verify(attempt(0,'bookings.create',$book)['error']==='LOGIN_REQUIRED', 'anonymous write denied');
verify(attempt($c,'bookings.create',$book)['error']==='MEMBERSHIP_COVERAGE', 'one-second gap fails continuous coverage');
clockAt($start-30*86400-1);
verify(attempt($a,'bookings.create',$book)['error']==='BOOKING_WINDOW','one second before the 30-day booking window');
clockAt($start-30*86400);
$boundary = fcBook($a,$sid);
verify($boundary['status']==='CONFIRMED','opening instant is bookable');
fcCancelBooking($a,(int)$boundary['booking_id']);
clockAt($start);
verify(attempt($a,'bookings.create',$book)['error']==='BOOKING_WINDOW','class start closes booking');
clockAt($before);

$result = race($before, [[$a,'bookings.create',$book],[$b,'bookings.create',$book]]);
$wins = array_values(array_filter($result, fn($r) => $r['ok']));
verify(count($wins)===1 && in_array('CLASS_FULL',array_column($result,'error'),true), 'last seat and adjoining paid periods');
$winner = (int)$wins[0]['data']['user_id'];
$other = $winner === $a ? $b : $a;
$bid = (int)$wins[0]['data']['booking_id'];
verify(attempt($other,'bookings.cancel',['booking_id'=>$bid])['error']==='NOT_FOUND', 'cannot cancel another member booking');
verify(attempt(1,'sessions.save',array_merge($first,['session_id'=>$sid,'ends_at'=>stamp($start+3700)]))['error']==='SESSION_HAS_BOOKINGS',
       'confirmed members protect schedule edits');
verify(attempt(1,'resources.save',['kind'=>'venue','id'=>$venues[0],'name'=>"Room $tag 0",'capacity'=>8,'status'=>'INACTIVE'])['error']==='RESOURCE_IN_USE',
       'scheduled session protects venue');
clockAt($start-7199);
verify(attempt($winner,'bookings.cancel',['booking_id'=>$bid])['error']==='CANCEL_WINDOW','one second past cancellation cutoff');
verify(attempt($winner,'bookings.list',[])['data']['items'][0]['can_cancel']===false,
       'booking list closes cancellation one second after the deadline');
clockAt($start-7200);
verify(attempt($winner,'bookings.list',[])['data']['items'][0]['can_cancel']===true,
       'booking list allows cancellation at the exact deadline');
$result = race($start-7200,[[$winner,'bookings.cancel',['booking_id'=>$bid]],[$winner,'bookings.cancel',['booking_id'=>$bid]]]);
verify($result[0]['ok'] && $result[1]['ok'] && fcSessionDetail($sid)['spots_left']===1,'exactly two hours and duplicate cancellations');
verify(attempt($winner,'bookings.list',['scope'=>'cancelled'])['data']['items'][0]['can_cancel']===false,
       'booking list hides cancellation for a cancelled booking');
$result = race($before,[[$winner,'bookings.create',$book],[$winner,'bookings.create',$book]]);
verify($result[0]['ok'] && $result[1]['ok'] && (int)$result[0]['data']['booking_id']===$bid &&
       (int)$result[1]['data']['booking_id']===$bid,'concurrent rebooking reuses same booking');

$second = fcSaveSession($session(1,$start+7200,$start+10800,3))['session_id'];
$third = fcSaveSession($session(2,$start+7200,$start+10800,3))['session_id'];
$result = race($before,[[$other,'bookings.create',['session_id'=>$second]],[$other,'bookings.create',['session_id'=>$third]]]);
verify(count(array_filter($result,fn($r) => $r['ok']))===1 && in_array('BOOKING_CONFLICT',array_column($result,'error'),true),
       'same member cannot concurrently reserve overlapping sessions');
$next = fcSaveSession($session(0,$start+3600,$start+7200,4))['session_id'];
verify(attempt($winner,'bookings.create',['session_id'=>$next])['ok'],'adjacent sessions can both be booked');
$result = race($before, [[$other,'bookings.create',['session_id'=>$next]],[1,'sessions.cancel',['session_id'=>$next]]]);
verify($result[1]['ok'] && ($result[0]['ok'] || $result[0]['error']==='SESSION_CLOSED') &&
       (int)one("SELECT COUNT(*) n FROM bookings WHERE session_id=? AND status='CONFIRMED'",[$next])['n']===0,
       'club cancellation and new booking race preserves terminal state');
$clubBid = one('SELECT booking_id FROM bookings WHERE user_id=? AND session_id=?',[$winner,$next])['booking_id'];
verify(attempt(1,'attendance.checkin',['booking_id'=>$clubBid])['error']==='INVALID_BOOKING','club cancellation rejects check-in');

// Increase capacity without changing booked members' plans; leave the second attendee unchecked.
fcSaveSession(array_merge($first,['session_id'=>$sid,'capacity'=>3]));
fcBook($other,$sid);
clockAt($start-1801);
verify(attempt(1,'attendance.checkin',['booking_id'=>$bid])['error']==='CHECKIN_WINDOW','one second before check-in');
$result = race($start-1800,[[1,'attendance.checkin',['booking_id'=>$bid]],[1,'attendance.checkin',['booking_id'=>$bid]]]);
verify($result[0]['ok'] && $result[1]['ok'] && $result[0]['data']['attendance_id']===$result[1]['data']['attendance_id'],
       'check-in opens at minus thirty minutes and repeats once');
$otherBid = one('SELECT booking_id FROM bookings WHERE user_id=? AND session_id=?',[$other,$sid])['booking_id'];
clockAt($start+901);
verify(attempt(1,'attendance.checkin',['booking_id'=>$otherBid])['error']==='CHECKIN_WINDOW','one second past check-in close');
// A different session verifies the other inclusive endpoint while keeping this attendee absent.
$laterBooking = one("SELECT booking_id,session_id FROM bookings WHERE user_id=? AND session_id IN (?,?) AND status='CONFIRMED'",[$other,$second,$third]);
$otherClassBid = (int)$laterBooking['booking_id'];
clockAt($start+7200+900);
$checked = attempt(1,'attendance.checkin',['booking_id'=>$otherClassBid]);
verify($checked['ok'],'exactly fifteen minutes after start still permits check-in');
$stop = ['session_id'=>(int)$laterBooking['session_id']];
verify(attempt(1,'sessions.cancel',$stop)['ok'],'club can cancel a session after check-in');
$rosterBooking = attempt(1,'sessions.roster',$stop)['data']['items'][0];
$memberBooking = attempt($other,'bookings.list',['scope'=>'cancelled'])['data']['items'][0];
verify($rosterBooking['booking_status']==='CANCELLED_BY_CENTER' && $rosterBooking['attendance_status']==='VOIDED' &&
       $rosterBooking['checked_at']===$checked['data']['checked_at'],'roster marks an earlier check-in void and retains its timestamp');
verify((int)$memberBooking['booking_id']===$otherClassBid && $memberBooking['attendance_status']===null &&
       $memberBooking['checked_at']===null && $memberBooking['can_cancel']===false,
       'member booking suppresses voided check-in and cancellation');
clockAt($start+3599);
verify(attempt(1,'sessions.complete',$book)['error']==='SESSION_IN_PROGRESS','cannot finalize before end');
$result = race($start+3600,[[1,'sessions.complete',$book],[1,'sessions.complete',$book]]);
verify($result[0]['ok'] && $result[1]['ok'],'concurrent completion at exact end');
$history = fcAttendance([],$other)['items'];
verify(count($history)===1 && $history[0]['status']==='ABSENT' && $history[0]['checked_at']===null,'settled absence with null check-in');
$report = fcReport(['from'=>'2032-05-10','through'=>'2032-05-10']);
$group = array_values(array_filter($report['popularity'],fn($row) => $row['category']===$tag))[0];
verify((int)$group['eligible']===2 && (int)$group['present']===1 && (int)$group['absent']===1 && $group['attendance_rate']===50.0,
       'attendance denominator excludes cancelled and unfinished sessions');
verify(fcReport(['from'=>'1901-01-01','through'=>'1901-01-01'])['attendance_rate']===null,'empty attendance denominator');

// Hold the second booking from a separate connection. Bulk cancellation scans
// both rows and must unwind any partial update on every lock-timeout attempt.
clockAt($before);
$lockedClass = fcSaveSession($session(2,$start+14400,$start+18000,4))['session_id'];
fcBook($a,$lockedClass);
$held = fcBook($b,$lockedClass)['booking_id'];
$cfg = require (getenv('FORM_CONFIG') ?: __DIR__ . '/../config/local.php');
$locker = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],$cfg['port'],$cfg['database']),
                  $cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$locker->beginTransaction();
$hold = $locker->prepare('SELECT booking_id FROM bookings WHERE booking_id=? FOR UPDATE');
$hold->execute([$held]);
db()->exec('SET SESSION innodb_lock_wait_timeout=1');
try {
    $blocked = attempt(1,'sessions.cancel',['session_id'=>$lockedClass]);
    verify(($blocked['error'] ?? '')==='BUSY' && fcSessionDetail($lockedClass)['status']==='SCHEDULED' &&
           (int)fcSessionDetail($lockedClass)['booked']===2,'timed-out cancellation rolls back all bookings and session changes');
} finally {
    $locker->rollBack();
}
verify(attempt(1,'sessions.cancel',['session_id'=>$lockedClass])['ok'] && (int)fcSessionDetail($lockedClass)['booked']===0,
       'retry after releasing lock commits club cancellation');
