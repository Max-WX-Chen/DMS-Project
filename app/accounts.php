<?php
declare(strict_types=1);

function csrfToken(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function changeIdentity(?int $id): array
{
    session_regenerate_id(true);
    $_SESSION = $id ? ['user_id' => $id] : [];
    return ['user' => viewer(), 'csrf_token' => csrfToken()];
}

function passwordInput(array $in, string $key, bool $new = false): string
{
    $s = $in[$key] ?? null;
    if (!is_string($s) || $s === '' || str_contains($s, "\0") ||
        strlen($s) > 72 || ($new && mb_strlen($s, 'UTF-8') < 8)) {
        fail('INVALID_PASSWORD', 'Passwords must contain 8 or more characters when set, and fit within 72 bytes.');
    }
    return $s;
}

function emailInput(array $in): string
{
    $email = strtolower(field($in, 'email', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('INVALID_EMAIL', 'Enter a valid email address.');
    }
    return $email;
}

function phoneInput(array $in): ?string
{
    if (!isset($in['phone']) || $in['phone'] === '') {
        return null;
    }
    $phone = field($in, 'phone', 30);
    if (!preg_match('/^[0-9+ ()-]{5,30}$/D', $phone)) {
        fail('INVALID_PHONE', 'Enter a phone number including the area code.');
    }
    return $phone;
}

function accountRoutes(string $action, array $in): mixed
{
    switch ($action) {
        case 'auth.session':
            return ['user' => viewer(), 'csrf_token' => csrfToken()];
        case 'auth.register':
            $name = field($in, 'full_name', 100);
            $email = emailInput($in);
            $phone = phoneInput($in);
            $hash = password_hash(passwordInput($in, 'password', true), PASSWORD_DEFAULT);
            run("INSERT INTO users(full_name,email,phone,password_hash,role,status) VALUES (?,?,?,?,'MEMBER','ACTIVE')",
                [$name,$email,$phone,$hash]);
            return changeIdentity((int)db()->lastInsertId());
        case 'auth.login':
            $row = one('SELECT user_id,password_hash,status FROM users WHERE email=?', [emailInput($in)]);
            $password = passwordInput($in, 'password');
            if (!$row || !password_verify($password, $row['password_hash']) || $row['status'] !== 'ACTIVE') {
                fail('LOGIN_FAILED', 'Check your credentials and account status.', 401);
            }
            return changeIdentity((int)$row['user_id']);
        case 'auth.logout':
            return changeIdentity(null);
        case 'profile.update':
            $member = needRole('MEMBER');
            run('UPDATE users SET full_name=?,phone=? WHERE user_id=? AND status=\'ACTIVE\'',
                [field($in, 'full_name', 100), phoneInput($in), $member['user_id']]);
            return viewer();
        case 'profile.password':
            $member = needRole('MEMBER');
            $current = passwordInput($in, 'current_password');
            $new = passwordInput($in, 'password', true);
            tx(function () use ($member, $new, $current) {
                lockUser((int)$member['user_id']);
                $row = one('SELECT password_hash FROM users WHERE user_id=?', [$member['user_id']]);
                if (!password_verify($current, $row['password_hash'])) {
                    fail('PASSWORD_MISMATCH', 'Current password is incorrect.', 403);
                }
                run('UPDATE users SET password_hash=? WHERE user_id=?', [password_hash($new, PASSWORD_DEFAULT),$member['user_id']]);
            });
            return changeIdentity((int)$member['user_id']);
        case 'members.list':
            needRole('ADMIN');
            [$limit,$offset] = pageArgs($in);
            $q = isset($in['q']) && $in['q'] !== '' ? field($in, 'q', 190) : '';
            $like = '%' . strtr($q, ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
            $numeric = preg_replace('/^(?:MEMBER[- #]*|M[- #]*)/i', '', $q);
            return rows('SELECT ' . PUBLIC_USER . " FROM users WHERE role='MEMBER'
                AND (full_name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR user_id=?)
                ORDER BY user_id LIMIT $limit OFFSET $offset", [$like,$like,ctype_digit($numeric) ? (int)$numeric : 0]);
        case 'members.detail':
        case 'members.status':
            needRole('ADMIN');
            $id = positive($in, 'user_id');
            $member = one('SELECT ' . PUBLIC_USER . " FROM users WHERE role='MEMBER' AND user_id=?", [$id])
                ?? fail('NOT_FOUND', 'Member not found.', 404);
            if ($action === 'members.detail') {
                return ['user'=>$member, 'periods'=>memberPeriods($id, $in)];
            }
            $status = choice($in, 'status', ['ACTIVE','DISABLED']);
            run("UPDATE users SET status=? WHERE user_id=? AND role='MEMBER'", [$status,$id]);
            return ['user_id'=>$id, 'status'=>$status];
    }
    fail('NOT_FOUND', 'Unknown account operation.', 404);
}
