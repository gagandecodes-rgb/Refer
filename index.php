<?php
error_reporting(0);
ini_set("display_errors", 0);

/* ================= CONFIG ================= */
define("POINTS_PER_COUPON", 3);
define("TG_TIMEOUT", 6);

/* ================= ENV ================= */
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_ID  = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { echo "OK"; exit; }
$API = "https://api.telegram.org/bot$BOT_TOKEN";

/* ================= DB ================= */
try {
  $pdo = new PDO(
    "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
    $DB_USER, $DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  $pdo = null;
}
function db(){ global $pdo; return $pdo; }

/* ================= TELEGRAM ================= */
function tg($m,$d=[]){
  global $API;
  $c = curl_init("$API/$m");
  curl_setopt_array($c,[
    CURLOPT_RETURNTRANSFER=>1,
    CURLOPT_POST=>1,
    CURLOPT_POSTFIELDS=>$d,
    CURLOPT_TIMEOUT=>TG_TIMEOUT
  ]);
  $r = curl_exec($c);
  curl_close($c);
  return json_decode($r,true);
}
function sendMessage($id,$t,$k=null){
  $d=["chat_id"=>$id,"text"=>$t,"parse_mode"=>"HTML","disable_web_page_preview"=>true];
  if($k)$d["reply_markup"]=json_encode($k);
  tg("sendMessage",$d);
}
function answerCb($id,$t="",$a=false){
  tg("answerCallbackQuery",[
    "callback_query_id"=>$id,
    "text"=>$t,
    "show_alert"=>$a?"true":"false"
  ]);
}

/* ================= HELPERS ================= */
function isAdmin($id){ return (string)$id === (string)getenv("ADMIN_ID"); }
function channel(){ return "@".ltrim(getenv("FORCE_JOIN_1"),"@"); }

/* ================= UI ================= */
function mainMenu($admin=false){
  $k=[
    [
      ["text"=>"📊 Stats","callback_data"=>"stats"],
      ["text"=>"🎁 Withdraw","callback_data"=>"withdraw"]
    ],
    [
      ["text"=>"🔗 My Referral Link","callback_data"=>"reflink"]
    ],
    [
      ["text"=>"🏆 Leaderboard","callback_data"=>"leaderboard"]
    ]
  ];
  if($admin)$k[]=[[ "text"=>"🛠 Admin Panel","callback_data"=>"admin_panel" ]];
  return ["inline_keyboard"=>$k];
}

function joinMarkup(){
  return ["inline_keyboard"=>[
    [[ "text"=>"✅ Join Channel","url"=>"https://t.me/".ltrim(channel(),"@") ]],
    [[ "text"=>"✅ Check Verification","callback_data"=>"check_join" ]]
  ]];
}

function adminPanel(){
  return ["inline_keyboard"=>[
    [
      ["text"=>"➕ Add Coupon","callback_data"=>"admin_add"],
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
      ["text"=>"⬅️ Back","callback_data"=>"back"]
    ]
  ]];
}

/* ================= USERS ================= */
function getUser($id){
  $s=db()->prepare("SELECT * FROM users WHERE tg_id=:i");
  $s->execute([":i"=>$id]);
  return $s->fetch(PDO::FETCH_ASSOC);
}
function addUser($id,$ref=null){
  db()->prepare(
    "INSERT INTO users (tg_id,referred_by)
     VALUES (:i,:r) ON CONFLICT DO NOTHING"
  )->execute([":i"=>$id,":r"=>$ref]);
}

/* ================= JOIN CHECK ================= */
function joined($uid){
  $r=tg("getChatMember",["chat_id"=>channel(),"user_id"=>$uid]);
  return isset($r["result"]["status"]) &&
    in_array($r["result"]["status"],["member","administrator","creator"]);
}

/* ================= STATE (ADMIN) ================= */
function stateFile($id){ return __DIR__."/state_$id.txt"; }
function setState($id,$s){ file_put_contents(stateFile($id),$s); }
function getState($id){ return file_exists(stateFile($id))?trim(file_get_contents(stateFile($id))):""; }
function clearState($id){ @unlink(stateFile($id)); }

/* ================= WEBHOOK ================= */
$u=json_decode(file_get_contents("php://input"),true);
if(!$u){ echo "OK"; exit; }

/* ================= MESSAGE ================= */
if(isset($u["message"])){
  $m=$u["message"];
  $cid=$m["chat"]["id"];
  $uid=$m["from"]["id"];
  $t=trim($m["text"]??"");

  /* ADMIN STATES */
  if(isAdmin($uid)){
    if(getState($uid)==="add_coupon"){
      $codes=preg_split("/[\s,]+/",$t);
      $added=0;
      foreach($codes as $c){
        if(!$c)continue;
        try{
          db()->prepare(
            "INSERT INTO coupons (code,added_by) VALUES (:c,:a)"
          )->execute([":c"=>$c,":a"=>$uid]);
          $added++;
        }catch(Exception $e){}
      }
      clearState($uid);
      sendMessage($cid,"✅ Added <b>$added</b> coupons",adminPanel());
      exit;
    }
    if(getState($uid)==="remove_coupon"){
      db()->prepare("DELETE FROM coupons WHERE code=:c")
        ->execute([":c"=>$t]);
      clearState($uid);
      sendMessage($cid,"🗑 Coupon removed (if existed)",adminPanel());
      exit;
    }
    if(getState($uid)==="broadcast"){
      $rows=db()->query("SELECT tg_id FROM users")->fetchAll();
      foreach($rows as $r){
        sendMessage($r["tg_id"],"📢 <b>Announcement</b>\n\n".$t);
      }
      clearState($uid);
      sendMessage($cid,"📢 Broadcast sent",adminPanel());
      exit;
    }
  }

  /* START */
  if(strpos($t,"/start")===0){
    $ref=null;
    if(strpos($t," ")!==false){
      [, $r]=explode(" ",$t,2);
      if(ctype_digit($r))$ref=(int)$r;
    }

    if(!getUser($uid)){
      addUser($uid,$ref);
      if($ref && $ref!=$uid){
        db()->prepare(
          "UPDATE users SET points=points+1,total_referrals=total_referrals+1
           WHERE tg_id=:r"
        )->execute([":r"=>$ref]);
      }
    }

    if(joined($uid) || isAdmin($uid)){
      sendMessage($cid,"🎉 <b>Welcome!</b>",mainMenu(isAdmin($uid)));
    }else{
      sendMessage($cid,"👉 Join channel first",joinMarkup());
    }
    exit;
  }
}

/* ================= CALLBACKS ================= */
if(isset($u["callback_query"])){
  $c=$u["callback_query"];
  $cid=$c["message"]["chat"]["id"];
  $uid=$c["from"]["id"];
  $d=$c["data"];

  /* JOIN */
  if($d==="check_join"){
    answerCb($c["id"],"Checking...");
    if(joined($uid) || isAdmin($uid)){
      sendMessage($cid,"✅ Verified!",mainMenu(isAdmin($uid)));
    }else{
      sendMessage($cid,"❌ Join channel first",joinMarkup());
    }
    exit;
  }

  /* ADMIN */
  if($d==="admin_panel" && isAdmin($uid)){
    answerCb($c["id"]);
    sendMessage($cid,"🛠 <b>Admin Panel</b>",adminPanel());
    exit;
  }
  if($d==="admin_add" && isAdmin($uid)){
    setState($uid,"add_coupon");
    sendMessage($cid,"➕ Send coupon codes",adminPanel());
    exit;
  }
  if($d==="admin_remove" && isAdmin($uid)){
    setState($uid,"remove_coupon");
    sendMessage($cid,"➖ Send coupon code to remove",adminPanel());
    exit;
  }
  if($d==="admin_broadcast" && isAdmin($uid)){
    setState($uid,"broadcast");
    sendMessage($cid,"📢 Send broadcast message",adminPanel());
    exit;
  }
  if($d==="admin_stock" && isAdmin($uid)){
    $a=db()->query("SELECT COUNT(*) FROM coupons WHERE used=false")->fetchColumn();
    $u=db()->query("SELECT COUNT(*) FROM coupons WHERE used=true")->fetchColumn();
    sendMessage($cid,"📦 Stock\n\n✅ Available: $a\n🧾 Used: $u",adminPanel());
    exit;
  }
  if($d==="admin_redeems" && isAdmin($uid)){
    $r=db()->query(
      "SELECT tg_id,coupon_code,created_at FROM withdrawals ORDER BY id DESC LIMIT 10"
    )->fetchAll();
    $t="🗂 <b>Last Redeems</b>\n\n";
    foreach($r as $x){
      $t.="👤 {$x['tg_id']}\n🎟 {$x['coupon_code']}\n🕒 {$x['created_at']}\n\n";
    }
    sendMessage($cid,$t ?: "No redeems",adminPanel());
    exit;
  }
  if($d==="back"){
    sendMessage($cid,"🏠 Main Menu",mainMenu(isAdmin($uid)));
    exit;
  }

  /* USER */
  if($d==="stats"){
    $u=getUser($uid);
    sendMessage($cid,
      "⭐ Points: <b>{$u['points']}</b>\n👥 Referrals: <b>{$u['total_referrals']}</b>\n\n🎁 3 points = 1 coupon",
      mainMenu(isAdmin($uid))
    );
    exit;
  }

  if($d==="reflink"){
    sendMessage($cid,"🔗 <code>https://t.me/$BOT_USERNAME?start=$uid</code>",mainMenu(isAdmin($uid)));
    exit;
  }

  if($d==="leaderboard"){
    $rows=db()->query(
      "SELECT tg_id,total_referrals FROM users
       ORDER BY total_referrals DESC LIMIT 10"
    )->fetchAll();
    $t="🏆 <b>Top 10 Referrers</b>\n\n";
    $i=1;
    foreach($rows as $r){
      $t.="$i️⃣ <code>{$r['tg_id']}</code> — {$r['total_referrals']}\n";
      $i++;
    }
    sendMessage($cid,$t,mainMenu(isAdmin($uid)));
    exit;
  }

  if($d==="withdraw"){
    $u=getUser($uid);
    if($u["points"]<POINTS_PER_COUPON){
      sendMessage($cid,"❌ Need 3 points",mainMenu(isAdmin($uid)));
      exit;
    }

    db()->beginTransaction();
    $coup=db()->query("SELECT * FROM coupons WHERE used=false LIMIT 1 FOR UPDATE")->fetch();
    if(!$coup){ db()->rollBack(); sendMessage($cid,"Out of stock"); exit; }

    db()->prepare("UPDATE users SET points=points-3 WHERE tg_id=:i")->execute([":i"=>$uid]);
    db()->prepare("UPDATE coupons SET used=true,used_by=:i,used_at=NOW() WHERE id=:id")
      ->execute([":i"=>$uid,":id"=>$coup["id"]]);
    db()->prepare(
      "INSERT INTO withdrawals (tg_id,coupon_code,points_deducted)
       VALUES (:i,:c,3)"
    )->execute([":i"=>$uid,":c"=>$coup["code"]]);
    db()->commit();

    sendMessage($cid,"🎉 <b>Your Coupon</b>\n<code>{$coup['code']}</code>",mainMenu(isAdmin($uid)));
    sendMessage($ADMIN_ID,"✅ Coupon redeemed by $uid\n{$coup['code']}");
    exit;
  }
}

echo "OK";
