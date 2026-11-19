<?php
declare(strict_types=1);

// Set FORM_CONFIG to the owner connection for a disposable, seeded *_test schema.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/billing.php';
if (!str_ends_with(one('SELECT DATABASE() AS db')['db'], '_test')) {
    throw new LogicException('Use a disposable database ending in _test.');
}
function verify(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function rejected(callable $operation, int $driverCode): bool
{
    try {
        $operation();
    } catch (PDOException $e) {
        return (int)$e->errorInfo[1] === $driverCode;
    }
    return false;
}

$_SESSION['user_id'] = 2;
$order = createOrder(['plan_id'=>1,'request_key'=>bin2hex(random_bytes(8)),'method'=>'SIMULATED'], viewer())['order'];
$id = (int)$order['order_id'];
run("CREATE TRIGGER qa_billing_abort BEFORE INSERT ON memberships FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected membership failure'");
try {
    verify(rejected(fn()=>changeOrder('orders.pay',['order_id'=>$id]),1644), 'injected error reaches transaction boundary');
    $info = orderInfo($id, viewer());
    verify($info['order']['status'] === 'PENDING' && !$info['payments'] && !$info['membership'],
        'order, receipt and membership all rolled back');
} finally {
    run('DROP TRIGGER qa_billing_abort');
}
verify(changeOrder('orders.pay',['order_id'=>$id])['order']['status'] === 'PAID', 'normal retry settles the rolled-back order');
verify(rejected(fn()=>run('UPDATE membership_plans SET price=price+1 WHERE plan_id=1'),1644), 'referenced plan immutable');
verify(rejected(fn()=>run("UPDATE orders SET status='PENDING' WHERE order_id=?",[$id]),1644), 'paid order cannot be reopened');
verify(rejected(fn()=>run("INSERT INTO payments(order_id,status,attempt_key,paid_at) VALUES (?,'SUCCEEDED','extra',NOW())",[$id]),1062),
    'database rejects second success');
verify(rejected(fn()=>run('DELETE FROM membership_plans WHERE plan_id=1'),1451), 'history foreign key restricts deletion');
verify(rejected(fn()=>run("INSERT INTO attendance(booking_id,status) VALUES (4294967295,'ABSENT')"),1452), 'orphan foreign key rejected');
db()->beginTransaction();
try {
    run('DELETE FROM bookings WHERE booking_id=1');
    verify(!one('SELECT attendance_id FROM attendance WHERE booking_id=1'), 'dependent attendance cascades');
} finally {
    db()->rollBack();
}
verify((bool)one('SELECT attendance_id FROM attendance WHERE booking_id=1'), 'rollback restores cascade fixture');
