<?php
error_reporting(0);
ini_set("display_errors", 0);

/* ================= CONFIG ================= */
define("POINTS_PER_COUPON", 3);
define("TG_TIMEOUT", 6);

/* ================= ALWAYS OK ================= */
function finish_ok() { http_response_code(200); echo "OK"; exit; }

/* ================= URL HELPERS ================= */
function baseUrl() {
  $proto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ((!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http");
  $host  = $_SERVER["HTTP_X_FORWARDED_HOST"] ?? $_SERVER["HTTP_HOST"];
  $path  = strtok($_SERVER["REQUEST_URI"], "?"); // /index.php
  return $proto . "://" . $host . $path;
}

/* ================= ENV ================= */
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) finish_ok();
$API = "https://api.telegram.org/bot".$BOT_TOKEN;

/* ================= DB ================= */
try {
  $pdo = new PDO(
    "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
    $DB_USER, $DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch(Exception $e){ $pdo = null; }

function db(){ global $pdo; return $pdo; }

/* ================= TELEGRAM ================= */
function tg($method, $data = []) {
  global $API;
  $ch = curl_init($API."/".$method);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $data,
    CURLOPT_TIMEOUT        => TG_TIMEOUT
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function sendMessage($chat_id, $text, $markup=null){
  $data = [
    "chat_id"=>$chat_id,
    "text"=>$text,
    "parse_mode"=>"HTML",
    "disable_web_page_preview"=>true
  ];
  if($markup) $data["reply_markup"] = json_encode($markup);
  tg("sendMessage",$data);
}

function answerCb($id,$text="",$alert=false){
  tg("answerCallbackQuery",[
    "callback_query_id"=>$id,
    "text"=>$text,
    "show_alert"=>$alert ? "true" : "false"
  ]);
}

/* ================= CORE HELPERS ================= */
function isAdmin($uid){ return (string)$uid === (string)getenv("ADMIN_ID"); }

function forceChannel() {
  $ch = getenv("FORCE_JOIN_1");
  if (!$ch) return "";
  $ch = trim($ch);
  if ($ch && $ch[0] !== "@") $ch = "@".$ch;
  return $ch;
}

function joinedChannel($uid){
  $ch = forceChannel();
  if(!$ch) return true;

  $r = tg("getChatMember",["chat_id"=>$ch,"user_id"=>$uid]);
  if(!$r || empty($r["ok"])) return false;
  $s = $r["result"]["status"] ?? "";
  return in_array($s, ["member","administrator","creator"], true);
}

/* ================= DB: USERS ================= */
function ensureUserNoRef($uid){
  $pdo=db(); if(!$pdo) return;
  $pdo->prepare("INSERT INTO users (tg_id) VALUES (:i) ON CONFLICT (tg_id) DO NOTHING")
      ->execute([":i"=>$uid]);
}

function getUser($uid){
  $pdo=db(); if(!$pdo) return null;
  $st=$pdo->prepare("SELECT * FROM users WHERE tg_id=:i");
  $st->execute([":i"=>$uid]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function isVerified($uid){
  $u=getUser($uid);
  return $u && !empty($u["verified"]);
}

function setVerified($uid){
  $pdo=db(); if(!$pdo) return;
  $pdo->prepare("UPDATE users SET verified=true WHERE tg_id=:i")->execute([":i"=>$uid]);
}

function setAdminState($uid,$state){
  $pdo=db(); if(!$pdo) return;
  $pdo->prepare("UPDATE users SET admin_state=:s WHERE tg_id=:i")->execute([":s"=>$state,":i"=>$uid]);
}

function getAdminState($uid){
  $u=getUser($uid);
  return $u ? trim((string)($u["admin_state"] ?? "")) : "";
}

/* ================= UI ================= */
function joinMarkup(){
  $ch = ltrim(forceChannel(), "@");
  return [
    "inline_keyboard"=>[
      [[ "text"=>"✅ Join Channel", "url"=>"https://t.me/".$ch ]],
      [[ "text"=>"✅ Check Verification", "callback_data"=>"check_join" ]]
    ]
  ];
}

function verifyMarkup($uid){
  $url = baseUrl()."?mode=verify&uid=".$uid;
  return [
    "inline_keyboard"=>[
      [[ "text"=>"✅ Verify Now", "url"=>$url ]],
      [[ "text"=>"🔁 Check Verification", "callback_data"=>"check_verified" ]]
    ]
  ];
}

function mainMenu($admin=false){
  $kb = [
    [
      ["text"=>"📊 Stats","callback_data"=>"stats"],
      ["text"=>"🎁 Redeem Coupon","callback_data"=>"redeem"]
    ],
    [
      ["text"=>"🔗 My Referral Link","callback_data"=>"reflink"],
      ["text"=>"🏆 Leaderboard","callback_data"=>"leaderboard"]
    ]
  ];
  if($admin) $kb[] = [[ "text"=>"🛠 Admin Panel","callback_data"=>"admin_panel" ]];
  return ["inline_keyboard"=>$kb];
}

function adminPanel(){
  return [
    "inline_keyboard"=>[
      [
        ["text"=>"➕ Add Coupons","callback_data"=>"admin_add"],
        ["text"=>"➖ Remove Coupon","callback_data"=>"admin_remove"]
      ],
      [
        ["text"=>"📦 Stock","callback_data"=>"admin_stock"],
        ["text"=>"🗂 Redeems","callback_data"=>"admin_redeems"]
      ],
      [
        ["text"=>"📢 Broadcast","callback_data"=>"admin_broadcast"]
      ],
      [
        ["text"=>"⬅️ Back","callback_data"=>"back_main"]
      ]
    ]
  ];
}

/* ================= WEBSITE VERIFY (same file) ================= */
if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["mode"] ?? "") === "verify") {
  $uid = (int)($_GET["uid"] ?? 0);

  if ($uid > 0 && db()) {
    ensureUserNoRef($uid);
    setVerified($uid);
  }

  $botLink = $BOT_USERNAME ? ("https://t.me/".ltrim($BOT_USERNAME,"@")) : "#";
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verification</title>
    <style>
      body{font-family:Arial,sans-serif;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
      .box{background:#111827;padding:22px;border-radius:14px;max-width:360px;width:92%;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.4)}
      .btn{display:inline-block;margin-top:14px;background:#22c55e;color:#000;padding:12px 16px;border-radius:10px;text-decoration:none;font-weight:700}
      .small{opacity:.8;font-size:13px;margin-top:8px}
      code{background:#0b1220;padding:4px 8px;border-radius:8px}
    </style>
  </head>
  <body>
    <div class="box">
      <h2>✅ Verification Complete</h2>
      <?php if(!$uid): ?>
        <p>UID missing. Go back and click verify again.</p>
      <?php else: ?>
        <p>You are verified.</p>
        <div class="small">UID: <code><?php echo htmlspecialchars((string)$uid); ?></code></div>
        <a class="btn" href="<?php echo htmlspecialchars($botLink); ?>">🔙 Return to Telegram</a>
        <div class="small">Now go back to Telegram and tap <b>🔁 Check Verification</b>.</div>
      <?php endif; ?>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ================= WEBHOOK INPUT ================= */
$update = json_decode(file_get_contents("php://input"), true);
if(!$update) finish_ok();

/* ================= MESSAGE HANDLER ================= */
if(isset($update["message"])) {
  $m = $update["message"];
  $cid = $m["chat"]["id"];
  $uid = $m["from"]["id"];
  $text = trim($m["text"] ?? "");

  /* ADMIN STATE INPUTS */
  if (isAdmin($uid) && db()) {
    ensureUserNoRef($uid);
    $state = getAdminState($uid);

    if ($state === "add") {
      $codes = preg_split("/[\s,]+/", $text);
      $added = 0;
      foreach($codes as $c){
        $c = trim($c);
        if(!$c) continue;
        try{
          db()->prepare("INSERT INTO coupons (code,added_by) VALUES (:c,:a)")
             ->execute([":c"=>$c,":a"=>$uid]);
          $added++;
        } catch(Exception $e){}
      }
      setAdminState($uid, null);
      sendMessage($cid,"✅ Added <b>$added</b> coupons", adminPanel());
      finish_ok();
    }

    if ($state === "remove") {
      db()->prepare("DELETE FROM coupons WHERE code=:c")->execute([":c"=>$text]);
      setAdminState($uid, null);
      sendMessage($cid,"🗑 Coupon removed (if existed).", adminPanel());
      finish_ok();
    }

    if ($state === "broadcast") {
      $rows = db()->query("SELECT tg_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
      $sent = 0;
      foreach($rows as $r){
        if(!empty($r["tg_id"])) { sendMessage($r["tg_id"], "📢 <b>Announcement</b>\n\n".$text); $sent++; }
      }
      setAdminState($uid, null);
      sendMessage($cid,"📢 Broadcast sent to <b>$sent</b> users.", adminPanel());
      finish_ok();
    }
  }

  /* /start with referral (FIXED) */
  if (strpos($text, "/start") === 0) {

    // parse ref id
    $ref = null;
    if (strpos($text, " ") !== false) {
      [, $p] = explode(" ", $text, 2);
      if (ctype_digit($p)) $ref = (int)$p;
    }

    // Insert user ONLY here and detect first-time insert
    $isNew = false;
    if (db()) {
      $stmt = db()->prepare(
        "INSERT INTO users (tg_id, referred_by)
         VALUES (:i, :r)
         ON CONFLICT (tg_id) DO NOTHING
         RETURNING tg_id"
      );
      $stmt->execute([":i"=>$uid, ":r"=>$ref]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $isNew = (bool)$row;

      // award ref ONLY if new user
      if ($isNew && $ref && $ref != $uid) {
        db()->prepare(
          "UPDATE users
           SET points = points + 1,
               total_referrals = total_referrals + 1
           WHERE tg_id = :r"
        )->execute([":r"=>$ref]);
      }
    }

    // access control
    if (isAdmin($uid) || isVerified($uid)) {
      sendMessage($cid, "🎉 <b>Welcome!</b>", mainMenu(isAdmin($uid)));
    } else {
      sendMessage($cid, "👉 Join channel then verify", joinMarkup());
    }

    finish_ok();
  }

  // other messages: do nothing, but ensure user exists (no referral)
  ensureUserNoRef($uid);
  finish_ok();
}

/* ================= CALLBACK HANDLER ================= */
if(isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $cid = $cq["message"]["chat"]["id"];
  $uid = $cq["from"]["id"];
  $data = $cq["data"] ?? "";

  ensureUserNoRef($uid);

  // check join
  if ($data === "check_join") {
    answerCb($cq["id"], "Checking...");
    if (joinedChannel($uid) || isAdmin($uid)) {
      sendMessage($cid, "🔐 <b>Verify Yourself</b>\nClick Verify Now:", verifyMarkup($uid));
    } else {
      sendMessage($cid, "❌ You have not joined the channel yet.", joinMarkup());
    }
    finish_ok();
  }

  // check verified
  if ($data === "check_verified") {
    answerCb($cq["id"], "Checking...");
    if (isAdmin($uid) || isVerified($uid)) {
      sendMessage($cid, "✅ <b>Verified Successfully!</b>", mainMenu(isAdmin($uid)));
    } else {
      sendMessage($cid, "❌ Not verified yet. Click Verify Now first.", verifyMarkup($uid));
    }
    finish_ok();
  }

  // block non-verified users from everything else (admin bypass)
  if (!isAdmin($uid) && !isVerified($uid)) {
    answerCb($cq["id"], "Verify first", true);
    sendMessage($cid, "🔐 Please verify first.", verifyMarkup($uid));
    finish_ok();
  }

  // stats
  if ($data === "stats") {
    $u = getUser($uid);
    $points = (int)($u["points"] ?? 0);
    $refs   = (int)($u["total_referrals"] ?? 0);
    $ver    = !empty($u["verified"]) ? "✅ Yes" : "❌ No";

    $redeems = 0;
    if (db()) {
      $st = db()->prepare("SELECT COUNT(*) FROM withdrawals WHERE tg_id=:i");
      $st->execute([":i"=>$uid]);
      $redeems = (int)$st->fetchColumn();
    }

    sendMessage(
      $cid,
      "📊 <b>Your Stats</b>\n\n".
      "⭐ Points: <b>$points</b>\n".
      "👥 Referrals: <b>$refs</b>\n".
      "🎟 Redeemed: <b>$redeems</b>\n".
      "🔐 Verified: <b>$ver</b>\n\n".
      "🎁 Need <b>".POINTS_PER_COUPON."</b> points per coupon.",
      mainMenu(isAdmin($uid))
    );
    finish_ok();
  }

  // referral link
  if ($data === "reflink") {
    $bn = ltrim((string)$GLOBALS["BOT_USERNAME"], "@");
    $link = $bn ? ("https://t.me/".$bn."?start=".$uid) : "Set BOT_USERNAME in Render ENV";
    sendMessage($cid, "🔗 <b>Your Referral Link</b>\n<code>$link</code>", mainMenu(isAdmin($uid)));
    finish_ok();
  }

  // leaderboard
  if ($data === "leaderboard") {
    $rows = db()->query("SELECT tg_id,total_referrals FROM users ORDER BY total_referrals DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $txt = "🏆 <b>Top 10 Referrers</b>\n\n";
    $i=1;
    foreach($rows as $r){
      $txt .= $i.". <code>".$r["tg_id"]."</code> — ".$r["total_referrals"]."\n";
      $i++;
    }
    sendMessage($cid, $txt, mainMenu(isAdmin($uid)));
    finish_ok();
  }

  // redeem
  if ($data === "redeem") {
    $u = getUser($uid);
    $points = (int)($u["points"] ?? 0);

    if ($points < POINTS_PER_COUPON) {
      sendMessage($cid, "❌ Not enough points.\nYou need <b>".POINTS_PER_COUPON."</b> points.", mainMenu(isAdmin($uid)));
      finish_ok();
    }

    db()->beginTransaction();
    $c = db()->query("SELECT * FROM coupons WHERE used=false LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);

    if(!$c){
      db()->rollBack();
      sendMessage($cid, "❌ Out of stock. Try later.", mainMenu(isAdmin($uid)));
      finish_ok();
    }

    db()->prepare("UPDATE users SET points=points-".POINTS_PER_COUPON." WHERE tg_id=:i")->execute([":i"=>$uid]);
    db()->prepare("UPDATE coupons SET used=true, used_by=:i, used_at=NOW() WHERE id=:id")->execute([":i"=>$uid,":id"=>$c["id"]]);
    db()->prepare("INSERT INTO withdrawals (tg_id,coupon_code,points_deducted) VALUES (:i,:c,:p)")
       ->execute([":i"=>$uid,":c"=>$c["code"],":p"=>POINTS_PER_COUPON]);

    db()->commit();

    sendMessage($cid, "🎉 <b>Congratulations!</b>\n\nYour Coupon:\n<code>".$c["code"]."</code>", mainMenu(isAdmin($uid)));
    if ($ADMIN_ID) sendMessage($ADMIN_ID, "✅ Coupon redeemed by <code>$uid</code>\n🎟 <code>".$c["code"]."</code>");
    finish_ok();
  }

  /* ================= ADMIN PANEL ================= */
  if ($data === "admin_panel" && isAdmin($uid)) {
    sendMessage($cid, "🛠 <b>Admin Panel</b>", adminPanel());
    finish_ok();
  }

  if ($data === "admin_add" && isAdmin($uid)) {
    setAdminState($uid, "add");
    sendMessage($cid, "➕ Send coupon codes (space / new line / comma).", adminPanel());
    finish_ok();
  }

  if ($data === "admin_remove" && isAdmin($uid)) {
    setAdminState($uid, "remove");
    sendMessage($cid, "➖ Send the coupon code to remove.", adminPanel());
    finish_ok();
  }

  if ($data === "admin_broadcast" && isAdmin($uid)) {
    setAdminState($uid, "broadcast");
    sendMessage($cid, "📢 Send broadcast message (will go to ALL users).", adminPanel());
    finish_ok();
  }

  if ($data === "admin_stock" && isAdmin($uid)) {
    $a = (int)db()->query("SELECT COUNT(*) FROM coupons WHERE used=false")->fetchColumn();
    $u = (int)db()->query("SELECT COUNT(*) FROM coupons WHERE used=true")->fetchColumn();
    sendMessage($cid, "📦 <b>Stock</b>\n\n✅ Available: <b>$a</b>\n🧾 Used: <b>$u</b>", adminPanel());
    finish_ok();
  }

  if ($data === "admin_redeems" && isAdmin($uid)) {
    $rows = db()->query("SELECT tg_id,coupon_code,created_at FROM withdrawals ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $txt = "🗂 <b>Last 10 Redeems</b>\n\n";
    if(!$rows) $txt .= "No redeems yet.";
    foreach($rows as $r){
      $txt .= "👤 <code>".$r["tg_id"]."</code>\n🎟 <code>".$r["coupon_code"]."</code>\n🕒 ".$r["created_at"]."\n\n";
    }
    sendMessage($cid, $txt, adminPanel());
    finish_ok();
  }

  if ($data === "back_main") {
    sendMessage($cid, "🏠 Main Menu", mainMenu(true));
    finish_ok();
  }

  answerCb($cq["id"], "");
  finish_ok();
}

finish_ok();
