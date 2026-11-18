<?php
// Export the version 1 schema, triggers and a consistent snapshot of all 11 tables.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php database/export.php NEW_FILE.sql\n");
    exit(2);
}
$ddl = file_get_contents(__DIR__ . '/form_club.sql');
$marker = strpos($ddl, '-- All dates are relative');
if ($marker === false) {
    throw new RuntimeException('Cannot locate the schema / demo seed boundary.');
}
$pdo = db();
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$tableKeys = ['users'=>'user_id','membership_plans'=>'plan_id','orders'=>'order_id',
    'payments'=>'payment_id','memberships'=>'membership_id','coaches'=>'coach_id','venues'=>'venue_id',
    'courses'=>'course_id','class_sessions'=>'session_id','bookings'=>'booking_id','attendance'=>'attendance_id'];
$file = fopen($argv[1], 'xb');
if (!$file) {
    throw new RuntimeException('Choose a writable output path for a new file.');
}
$write = function(string $s) use ($file) {
    if (fwrite($file, $s) !== strlen($s)) {
        throw new RuntimeException('Could not write the complete SQL export.');
    }
};
try {
    $write(substr($ddl, 0, $marker) . "-- Rows exported from FORM Club. Times are Shanghai DATETIME values.\nSTART TRANSACTION;\n");
    $pdo->beginTransaction();
    foreach ($tableKeys as $table => $pk) {
        $fields = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll();
        $columns = [];
        foreach ($fields as $field) {
            if (!str_contains(strtoupper($field['Extra']), 'GENERATED')) {
                $columns[] = $field['Field'];
            }
        }
        $list = implode(',', array_map(fn($name) => chr(96) . $name . chr(96), $columns));
        $rows = $pdo->query("SELECT $list FROM $table ORDER BY $pk");
        $head = "INSERT INTO $table ($list) VALUES\n";
        $tuples = [];
        $length = 0;
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $tuple = '(' . implode(',', array_map(fn($x) => $x === null ? 'NULL' : $pdo->quote((string)$x), $row)) . ')';
            $tuples[] = $tuple;
            $length += strlen($tuple) + 2;
            if ($length >= 131072) {
                $write($head . implode(",\n", $tuples) . ";\n");
                $tuples = [];
                $length = 0;
            }
        }
        if ($tuples) {
            $write($head . implode(",\n", $tuples) . ";\n");
        }
    }
    $pdo->commit();
    $write("COMMIT;\n");
    fclose($file);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fclose($file);
    unlink($argv[1]); // This invocation created the incomplete file exclusively.
    throw $e;
}
