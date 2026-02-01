<?php
error_reporting(0);
ini_set("display_errors", 0);

/* ================= CONFIG ================= */
define("POINTS_PER_WITHDRAW", 3);
define("VERIFY_TOKEN_MINUTES", 10);
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 6);

/* ================= ENV ================= */
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { echo "OK"; exit; }
$API = "https://api.telegram.org/bot{$BOT_TOKEN}";

/* ================= DB ================= */
try {
  $pdo = new PDO(
    "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
    $DB_USER,
    $DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  $pdo = null;
}

function dbReady(){ global $pdo; return $pdo instanceof PDO; }

/* ================= TELEGRAM ================= */
function tg($m,$d=[]){
  global $API;
  $c=curl_init($API."/".$m);
  curl_setopt_array($c,[
    CURLOPT_RETURNTRANSFER=>1,
    CURLOPT_POST=>1,
    CURLOPT_POSTFIELDS=>$d,
    CURLOPT_CONNECTTIMEOUT=>TG_CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT=>TG_TIMEOUT
  ]);
  $r=curl_exec($c);
  curl_close($c);
  return $r?json_decode($r,true):null;
}

function sendMessage($id,$t,$k=null){
  $d=["chat_id"=>$id,"text"=>$t,"parse_mode"=>"HTML","disable_web_page_preview"=>true];
  if($k)$d["reply_markup"]=json_encode($k);
  tg("sendMessage",$d);
}

function answerCallback($id,$t="",$a=false){
  tg("answerCallbackQuery",[
    "callback_query_id"=>$id,
    "text"=>$t,
    "show_alert"=>$a?"true":"false"
  ]);
}

/* ================= HELPERS ================= */
function isAdmin($id){ global $ADMIN_ID; return (string)$id===(string)$ADMIN_ID; }
function normalizeChannel($c){ $c=trim($c); if($c && $c[0]!=="@")$c="@".$c; return $c; }
function channel(){ return normalizeChannel(getenv("FORCE_JOIN_1")); }

function mainMenu($admin=false){
  $k=[
    [
      ["text"=>"📊 Stats","callback_data"=>"stats"],
      ["text"=>"🎁 Withdraw","callback_data"=>"withdraw"]
    ],
    [
      ["text"=>"🔗 My Referral Link","callback_data"=>"reflink"]
    ]
  ];
  if($admin)$k[]=[[ "text"=>"🛠 Admin Panel","callback_data"=>"admin" ]];
  return ["inline_keyboard"=>$k];
}

function joinMarkup(){
  $ch=channel();
  return ["inline_keyboard"=>[
    [[ "text"=>"✅ Join Channel","url"=>"https://t.me/".ltrim($ch,"@") ]],
    [[ "text"=>"✅ Check Verification","callback_data"=>"check_join" ]]
  ]];
}

/* ================= USERS ================= */
function getUser($id){
  global $pdo;
  $s=$pdo->prepare("SELECT * FROM users WHERE tg_id=:i");
  $s->execute([":i"=>$id]);
  return $s->fetch(PDO::FETCH_ASSOC);
}

function upsertUser($id,$ref=null){
  global $pdo;
  $pdo->prepare(
    "INSERT INTO users (tg_id,referred_by)
     VALUES (:i,:r) ON CONFLICT (tg_id) DO NOTHING"
  )->execute([":i"=>$id,":r"=>$ref]);
}

function isVerified($id){
  $u=getUser($id);
  return $u && $u["verified"];
}

/* ================= JOIN CHECK ================= */
function checkMember($uid,$chat){
  $r=tg("getChatMember",["chat_id"=>$chat,"user_id"=>$uid]);
  return isset($r["result"]["status"]) &&
    in_array($r["result"]["status"],["member","administrator","creator"]);
}

/* ================= VERIFY LINK ================= */
function baseUrl(){
  $p="https";
  if(!empty($_SERVER["HTTP_X_FORWARDED_PROTO"]))$p=$_SERVER["HTTP_X_FORWARDED_PROTO"];
  $h=$_SERVER["HTTP_X_FORWARDED_HOST"]??$_SERVER["HTTP_HOST"];
  return "$p://$h".$_SERVER["SCRIPT_NAME"];
}

function makeVerifyLink($uid){
  global $pdo;
  $t=bin2hex(random_bytes(16));
  $pdo->prepare(
    "UPDATE users SET verify_token=:t,
     verify_token_expires=NOW()+INTERVAL '10 minutes'
     WHERE tg_id=:i"
  )->execute([":t"=>$t,":i"=>$uid]);
  return baseUrl()."?mode=verify&uid=$uid&token=$t";
}

/* ================= WEBSITE VERIFY ================= */
if($_SERVER["REQUEST_METHOD"]==="GET" && ($_GET["mode"]??"")==="verify"){
  if(!$pdo){ echo "DB Error"; exit; }
  $uid=(int)($_GET["uid"]??0);
  $token=$_GET["token"]??"";
  $step=$_GET["step"]??"";

  if(!$step){
    echo "<h2>Verify</h2><a href='?mode=verify&uid=$uid&token=$token&step=do'>Verify Now</a>";
    exit;
  }

  $u=getUser($uid);
  if(!$u || $u["verify_token"]!==$token){ echo "Invalid"; exit; }

  $dt=$_COOKIE["device"]??bin2hex(random_bytes(16));
  setcookie("device",$dt,time()+31536000,"/","",true,true);

  $pdo->prepare(
    "INSERT INTO device_links (device_token,tg_id)
     VALUES (:d,:i)
     ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id"
  )->execute([":d"=>$dt,":i"=>$uid]);

  $pdo->prepare(
    "UPDATE users SET verified=true,verified_at=NOW(),
     verify_token=NULL,verify_token_expires=NULL WHERE tg_id=:i"
  )->execute([":i"=>$uid]);

  header("Location: https://t.me/".ltrim($GLOBALS["BOT_USERNAME"],"@"));
  exit;
}

/* ================= WEBHOOK ================= */
$u=json_decode(file_get_contents("php://input"),true);
if(!$u){ echo "OK"; exit; }

/* ================= MESSAGES ================= */
if(isset($u["message"])){
  $m=$u["message"];
  $cid=$m["chat"]["id"];
  $uid=$m["from"]["id"];
  $t=trim($m["text"]??"");

  if(strpos($t,"/start")===0){
    $ref=null;
    if(strpos($t," ")!==false){
      [, $r]=explode(" ",$t,2);
      if(ctype_digit($r))$ref=(int)$r;
    }

    if(!getUser($uid)){
      upsertUser($uid,$ref);
      if($ref && $ref!=$uid){
        $pdo->prepare(
          "UPDATE users SET points=points+1,
           total_referrals=total_referrals+1 WHERE tg_id=:r"
        )->execute([":r"=>$ref]);
      }
    }

    if(isVerified($uid)){
      sendMessage($cid,"🎉 <b>Welcome!</b>",mainMenu(isAdmin($uid)));
    }else{
      sendMessage($cid,"👉 Join channel then verify",joinMarkup());
    }
    exit;
  }

  sendMessage($cid,"Use /start");
  exit;
}

/* ================= CALLBACKS ================= */
if(isset($u["callback_query"])){
  $c=$u["callback_query"];
  $cid=$c["message"]["chat"]["id"];
  $uid=$c["from"]["id"];
  $d=$c["data"];

  if($d==="check_join"){
    answerCallback($c["id"],"Checking...");
    if(checkMember($uid,channel())){
      $url=makeVerifyLink($uid);
      sendMessage($cid,"🔐 Verify yourself",[
        "inline_keyboard"=>[
          [[ "text"=>"✅ Verify Now","url"=>$url ]],
          [[ "text"=>"✅ Check Verification","callback_data"=>"check_verified" ]]
        ]
      ]);
    }else{
      sendMessage($cid,"❌ Join channel first",joinMarkup());
    }
    exit;
  }

  if($d==="check_verified"){
    if(isVerified($uid)){
      sendMessage($cid,"✅ Verified!",mainMenu(isAdmin($uid)));
    }else{
      sendMessage($cid,"❌ Not verified yet");
    }
    exit;
  }

  if(!isVerified($uid)){
    answerCallback($c["id"],"Verify first",true);
    exit;
  }

  if($d==="stats"){
    $u=getUser($uid);
    sendMessage($cid,"⭐ Points: {$u['points']}\n👥 Referrals: {$u['total_referrals']}",mainMenu(isAdmin($uid)));
    exit;
  }

  if($d==="reflink"){
    $link="https://t.me/{$BOT_USERNAME}?start=$uid";
    sendMessage($cid,"🔗 <code>$link</code>",mainMenu(isAdmin($uid)));
    exit;
  }

  if($d==="withdraw"){
    $u=getUser($uid);
    if($u["points"]<3){
      sendMessage($cid,"❌ Need 3 points",mainMenu(isAdmin($uid)));
      exit;
    }

    $pdo->beginTransaction();
    $coup=$pdo->query("SELECT * FROM coupons WHERE used=false LIMIT 1 FOR UPDATE")->fetch();
    if(!$coup){ $pdo->rollBack(); sendMessage($cid,"Out of stock"); exit; }

    $pdo->prepare("UPDATE users SET points=points-3 WHERE tg_id=:i")->execute([":i"=>$uid]);
    $pdo->prepare("UPDATE coupons SET used=true,used_by=:i,used_at=NOW() WHERE id=:id")
        ->execute([":i"=>$uid,":id"=>$coup["id"]]);
    $pdo->prepare("INSERT INTO withdrawals (tg_id,coupon_code,points_deducted)
                   VALUES (:i,:c,3)")
        ->execute([":i"=>$uid,":c"=>$coup["code"]]);
    $pdo->commit();

    sendMessage($cid,"🎉 Coupon:\n<code>{$coup['code']}</code>",mainMenu(isAdmin($uid)));
    sendMessage($ADMIN_ID,"Coupon redeemed by $uid\n{$coup['code']}");
    exit;
  }
}

echo "OK";
