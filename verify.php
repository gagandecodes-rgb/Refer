<?php
error_reporting(0);
ini_set("display_errors", 0);

/* === SAME DB CREDS AS index.php === */
$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

try {
  $pdo = new PDO(
    "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
    $DB_USER,$DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
  );
} catch(Exception $e){ $pdo=null; }

$uid = (int)($_GET["uid"] ?? 0);

if ($uid && $pdo) {
  $pdo->prepare(
    "UPDATE users SET verified=true WHERE tg_id=:i"
  )->execute([":i"=>$uid]);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verification</title>
<style>
body{font-family:Arial;background:#0f172a;color:#fff;
display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
.box{background:#111827;padding:22px;border-radius:14px;
max-width:360px;width:92%;text-align:center}
.btn{display:inline-block;margin-top:14px;background:#22c55e;
color:#000;padding:12px 16px;border-radius:10px;
text-decoration:none;font-weight:700}
.small{opacity:.8;font-size:13px;margin-top:8px}
code{background:#0b1220;padding:4px 8px;border-radius:8px}
</style>
</head>
<body>
<div class="box">
  <h2>✅ Verified</h2>
  <?php if(!$uid): ?>
    <p>Invalid request.</p>
  <?php else: ?>
    <p>Your verification is complete.</p>
    <div class="small">UID: <code><?=htmlspecialchars($uid)?></code></div>
    <a class="btn" href="https://t.me/<?=htmlspecialchars(getenv("BOT_USERNAME"))?>">
      🔙 Return to Telegram
    </a>
  <?php endif; ?>
</div>
</body>
</html>
