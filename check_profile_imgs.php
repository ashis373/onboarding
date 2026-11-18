<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/api/db.php';

$currentUid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Profile Images Diagnostics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #0f172a; color: #f8fafc; }
        h1, h2 { color: #38bdf8; }
        table { border-collapse: collapse; width: 100%; margin-top: 15px; background: #1e293b; border-radius: 8px; overflow: hidden; }
        th, td { border: 1px solid #334155; padding: 10px 14px; text-align: left; }
        th { background: #0f172a; color: #94a3b8; font-size: 12px; text-transform: uppercase; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-ok { background: #065f46; color: #6ee7b7; }
        .badge-err { background: #881337; color: #fda4af; }
        img.avatar { width: 50px; height: 50px; border-radius: 12px; object-fit: cover; border: 2px solid #38bdf8; }
        pre { background: #090d16; padding: 12px; border-radius: 6px; overflow-x: auto; color: #a5f3fc; }
    </style>
</head>
<body>
    <h1>Profile Image Path & Session Diagnostic</h1>
    
    <h2>1. Current Logged-in Session</h2>
    <pre>Session user_id: <?php echo var_export($currentUid, true); ?>

Session Data: <?php echo htmlspecialchars(print_r($_SESSION, true)); ?></pre>

    <h2>2. Current User DB Record</h2>
    <?php
    $currentUser = null;
    if ($currentUid) {
        $res = $conn->query("SELECT * FROM user WHERE uid = " . intval($currentUid));
        if ($res && $res->num_rows > 0) {
            $currentUser = $res->fetch_assoc();
            echo "<pre>" . htmlspecialchars(print_r([
                'uid' => $currentUser['uid'],
                'username' => $currentUser['username'],
                'firstname' => $currentUser['firstname'],
                'lastname' => $currentUser['lastname'],
                'profile_img' => $currentUser['profile_img'] ?? 'NOT SET',
                'ofc_mail' => $currentUser['ofc_mail'] ?? 'NOT SET'
            ], true)) . "</pre>";

            $cImg = trim($currentUser['profile_img'] ?? '');
            if ($cImg !== '') {
                $dirCheck = realpath(__DIR__ . '/../dist/img/user');
                $cExists = $dirCheck && file_exists($dirCheck . '/' . $cImg);
                $cEncoded = implode('/', array_map('rawurlencode', explode('/', $cImg)));
                $cUrl = '/internal/hex-timesheet/dist/img/user/' . $cEncoded;
                echo "<p><strong>Profile Image File on Disk:</strong> " . ($cExists ? "<span class='badge badge-ok'>EXISTS ON SERVER</span>" : "<span class='badge badge-err'>NOT FOUND ON SERVER DISK</span>") . "</p>";
                echo "<p><strong>Browser Image URL:</strong> <a href='$cUrl' target='_blank' style='color:#38bdf8; text-decoration: underline;'>$cUrl</a></p>";
                echo "<p><strong>Direct Image Test:</strong><br><img src='$cUrl' class='avatar' style='width:64px; height:64px; border-radius:16px;' onerror=\"this.style.border='2px solid red'; this.alt='FAILED TO LOAD';\" /></p>";
            }
        } else {
            echo "<p style='color: #f87171;'>User not found in user table with uid $currentUid</p>";
        }
    } else {
        echo "<p style='color: #fbbf24;'>No active session user_id</p>";
    }
    ?>

    <h2>3. Disk Path Verification for Common Folders</h2>
    <?php
    $testDirs = [
        '../dist/img/user' => realpath(__DIR__ . '/../dist/img/user'),
        '../img/user' => realpath(__DIR__ . '/../img/user'),
        '../../dist/img/user' => realpath(__DIR__ . '/../../dist/img/user'),
        '../../hex-timesheet/dist/img/user' => realpath(__DIR__ . '/../../hex-timesheet/dist/img/user'),
        '$_SERVER[DOCUMENT_ROOT]/internal/hex-timesheet/dist/img/user' => realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') . '/internal/hex-timesheet/dist/img/user'),
    ];
    echo "<table><tr><th>Candidate Folder Path</th><th>Resolved Absolute Path</th><th>Exists on Disk?</th><th>File Count</th></tr>";
    $validImageDir = null;
    foreach ($testDirs as $label => $resolved) {
        $exists = $resolved && is_dir($resolved);
        $count = $exists ? count(scandir($resolved)) - 2 : 0;
        if ($exists && !$validImageDir) {
            $validImageDir = $resolved;
        }
        echo "<tr>
            <td><code>$label</code></td>
            <td><code>" . ($resolved ?: 'NOT FOUND') . "</code></td>
            <td><span class='badge " . ($exists ? 'badge-ok' : 'badge-err') . "'>" . ($exists ? 'YES' : 'NO') . "</span></td>
            <td>$count files</td>
        </tr>";
    }
    echo "</table>";
    ?>

    <h2>4. Testing Users & Profile Images</h2>
    <table>
        <tr>
            <th>UID</th>
            <th>Username</th>
            <th>Name</th>
            <th>profile_img Column</th>
            <th>File Exists on Disk?</th>
            <th>Direct Browser URL Test</th>
            <th>Preview</th>
        </tr>
        <?php
        $currentUidInt = intval($currentUid ?: 205);
        $q = "SELECT uid, username, firstname, lastname, profile_img FROM user WHERE uid = $currentUidInt 
              UNION 
              (SELECT uid, username, firstname, lastname, profile_img FROM user WHERE uid IN (26, 27, 63, 64, 65, 66, 67, 71, 72, 73) OR (profile_img IS NOT NULL AND profile_img != '') LIMIT 15)";
        $res = $conn->query($q);
        while ($row = $res->fetch_assoc()) {
            $imgName = trim((string)($row['profile_img'] ?? ''));
            $encoded = implode('/', array_map('rawurlencode', explode('/', $imgName)));
            $url1 = '/internal/hex-timesheet/dist/img/user/' . $encoded;
            $url2 = 'https://hexalearn.com/internal/hex-timesheet/dist/img/user/' . $encoded;

            $fileExists = false;
            if ($validImageDir && $imgName) {
                $fileExists = file_exists($validImageDir . '/' . $imgName);
            }

            echo "<tr>
                <td>{$row['uid']}</td>
                <td>{$row['username']}</td>
                <td>{$row['firstname']} {$row['lastname']}</td>
                <td><code>" . ($imgName ?: '<span style="color:#94a3b8">EMPTY</span>') . "</code></td>
                <td><span class='badge " . ($fileExists ? 'badge-ok' : 'badge-err') . "'>" . ($fileExists ? 'EXISTS' : 'NOT FOUND') . "</span></td>
                <td><a href='$url2' target='_blank' style='color:#38bdf8; text-decoration: underline;'>$url1</a></td>
                <td>";
            if ($imgName) {
                echo "<img class='avatar' src='$url2' alt='Avatar' onerror=\"this.style.border='2px solid red'; this.alt='BROKEN';\" />";
            } else {
                echo "<span style='color:#64748b'>No image</span>";
            }
            echo "</td>
            </tr>";
        }
        ?>
    </table>
</body>
</html>
