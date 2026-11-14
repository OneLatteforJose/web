<?php
// What a person may change about their own account. Shared by the customer, manager and admin profile pages.
// Admins edit everyone else's details on admin/account.php. Role, sign-in status and (for managers) the city they
// cover are never changed from here: only an admin changes those.
require_once __DIR__ . '/auth.php';

function profile_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

// Validates and saves the person's name, email and phone, and optionally their barangay and street.
//   $opts['area']             may change barangay (customers)
//   $opts['street']           may change street / house address
//   $opts['street_required']  street must not be empty (customers)
// Changing the email address needs the current password. Returns '' on success, otherwise the error to show.
function save_own_details(PDO $pdo, int $id, array $in, array $opts = []): string {
    $st = $pdo->prepare("SELECT email, password_hash FROM users WHERE id = ? AND is_active = 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return 'Your account could not be found.';
    }
    $name   = trim($in['full_name'] ?? '');
    $email  = trim($in['email'] ?? '');
    $phone  = trim($in['phone'] ?? '');
    $street = trim($in['street'] ?? '');

    if ($name === '' || profile_len($name) > 150) {
        return 'Enter your full name (up to 150 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || profile_len($email) > 150) {
        return 'Enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
        return 'Enter a valid phone number, or leave it empty.';
    }
    if (strcasecmp($email, $row['email']) !== 0 && !password_verify((string)($in['current_password'] ?? ''), $row['password_hash'])) {
        return 'Enter your current password to change your email address.';
    }

    $sets = ['full_name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null];
    if (!empty($opts['area'])) {
        $areaId = (int)($in['area_id'] ?? 0);
        $ok = $pdo->prepare("SELECT 1 FROM areas WHERE id = ?");
        $ok->execute([$areaId]);
        if (!$ok->fetchColumn()) {
            return 'Select your city and barangay.';
        }
        $sets['area_id'] = $areaId;
    }
    if (!empty($opts['street'])) {
        if (!empty($opts['street_required']) && $street === '') {
            return 'Enter your street or house address.';
        }
        if (profile_len($street) > 255) {
            return 'The street address is too long (up to 255 characters).';
        }
        $sets['street'] = $street !== '' ? $street : null;
    }

    try {
        $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($sets)));
        $pdo->prepare("UPDATE users SET $cols WHERE id = ?")->execute([...array_values($sets), $id]);
    } catch (PDOException $ex) {
        return $ex->getCode() === '23000' ? 'That email address belongs to another account.' : 'Could not save your details. Please try again.';
    }
    $_SESSION['user']['name'] = $name;   // so the welcome text and menus show the new name straight away
    return '';
}

// Changes the person's own password after checking the current one. Returns '' on success, otherwise the error to show.
function change_own_password(PDO $pdo, int $id, string $current, string $new, string $confirm): string {
    $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND is_active = 1");
    $st->execute([$id]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($current, $hash)) {
        return 'Your current password is not correct.';
    }
    if (strlen($new) < 8 || strlen($new) > 72) {
        return 'The new password must be 8 to 72 characters.';
    }
    if ($new !== $confirm) {
        return 'The new password and its confirmation do not match.';
    }
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
    session_regenerate_id(true);
    return '';
}
