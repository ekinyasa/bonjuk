<?php
/**
 * Schedule Board – PHP (responsive header + sections + content editor)
 * -------------------------------------------------------------------
 * What’s new in v2
 * - Sticky header with profile photo + tiny nav (Book • Sessions • About)
 * - Public page anchors: #schedule, #sessions, #about, #footer
 * - Mobile-first layout, responsive for tablet/desktop
 * - Floating “scroll to top” button appears after scroll
 * - Hidden admin link (tiny gear icon in footer with low opacity)
 * - Admin area now has two tabs: Calendar & Content
 *   • Calendar: same features as before (add hour, edit/clear/delete)
 *   • Content: edit profile photo URL, nav labels, sessions/about texts (saved to content.json)
 * - Default route without ?date opens today
 * - Pushover support preserved
 *
 * How to use
 * - Upload as schedule.php
 * - Set ADMIN_PASSWORD_HASH in .env; (optional) PUSHOVER_USER/TOKEN
 * - Open /schedule.php (public) and /schedule.php?admin=1 (admin)
 */

// === Environment ===

function loadEnv($path)
{
  if (!is_readable($path)) {
    return;
  }

  $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
  if (!is_array($parsed)) {
    return;
  }

  foreach ($parsed as $name => $value) {
    if (!array_key_exists($name, $_ENV)) {
      $_ENV[$name] = $value;
    }
    if (!array_key_exists($name, $_SERVER)) {
      $_SERVER[$name] = $value;
    }
    putenv($name . '=' . $value);
  }
}

function env($key, $default = null)
{
  if (array_key_exists($key, $_ENV)) {
    return $_ENV[$key];
  }
  if (array_key_exists($key, $_SERVER)) {
    return $_SERVER[$key];
  }
  $value = getenv($key);
  if ($value !== false) {
    return $value;
  }
  return $default;
}

loadEnv(__DIR__ . '/.env');

// === Settings ===

const DEFAULT_HOURS = ['10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00'];
const DATA_FILE  = __DIR__ . '/data.json';           // JSON fallback store
const DB_FILE    = __DIR__ . '/schedule.db';         // SQLite file (auto)
const CONTENT_FILE = __DIR__ . '/content.json';      // Editable content (nav labels, texts, photo)
// Pushover (optional)
define('ADMIN_PASSWORD_HASH', (string)env('ADMIN_PASSWORD_HASH', ''));
define('PUSHOVER_USER', (string)env('PUSHOVER_USER', ''));
define('PUSHOVER_TOKEN', (string)env('PUSHOVER_TOKEN', ''));

date_default_timezone_set('Europe/Istanbul');

session_set_cookie_params([
  'path' => '/',          // kökten geçerli
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();
// --- language (en/tr) ---
$lang = 'en';
if (isset($_GET['lang'])){
  $l = (strtolower($_GET['lang'])==='tr') ? 'tr' : 'en';
  $_SESSION['lang'] = $l; $lang = $l;
} elseif (!empty($_SESSION['lang'])) { $lang = $_SESSION['lang']; }

// helper to build a toggle link preserving current query
function lang_toggle_href($to){ $q = $_GET; $q['lang'] = $to; return '?' . http_build_query($q); }

// --- helpers ---
function today_iso() { return (new DateTime('now'))->format('Y-m-d'); }
function iso_date($s) { $d = DateTime::createFromFormat('Y-m-d', $s ?: ''); return $d ? $d->format('Y-m-d') : today_iso(); }
function norm_phone($p){ return preg_replace('/[^0-9+]/','', $p ?? ''); }
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function base_url(){ $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'; $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; $path = strtok($_SERVER['REQUEST_URI'],'?'); return $proto.'://'.$host.$path; }
function post($k,$d=''){ return $_POST[$k] ?? $d; }
function default_busy_hours(){
  $out=[]; $t = DateTime::createFromFormat('H:i','10:00'); $end = DateTime::createFromFormat('H:i','20:00');
  while($t < $end){
    $out[] = $t->format('H:i');
    $t2 = clone $t; $t2->modify('+60 minutes'); if($t2 >= $end) break;
    $t2->modify('+15 minutes'); if($t2 >= $end) break;
    $t = $t2;
  }
  return $out;
}
function gen_hours($startHHMM='12:00',$sessMin=60,$breakMin=15){
  $out=[];
  if (!preg_match('/^(\\d{1,2}):(\\d{2})$/',$startHHMM,$m)) return $out;
  $h=(int)$m[1]; $mm=(int)$m[2];
  $t = $h*60 + $mm;    // dakika
  $limit = 20*60;      // 20:00
  while($t < $limit){
    $H = intdiv($t,60); $M = $t%60;
    $out[] = sprintf('%02d:%02d',$H,$M);
    $t += $sessMin + $breakMin; // seans + mola
  }
  return $out;
}
// --- storage: sqlite or json ---
class Store {
  private $useSqlite = false; private $pdo = null;
  public function __construct(){
    try{
      if (in_array('sqlite', \PDO::getAvailableDrivers(), true)){
        $this->pdo = new PDO('sqlite:'.DB_FILE);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS slots (id INTEGER PRIMARY KEY AUTOINCREMENT, date TEXT, hour TEXT, name TEXT, whatsapp TEXT);\nCREATE UNIQUE INDEX IF NOT EXISTS idx ON slots(date,hour);");
        $this->useSqlite = true;
      }
    }catch(Throwable $e){ $this->useSqlite = false; }
  }
  public function ensureDay($date, $hours){
    if ($this->useSqlite){
      $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM slots WHERE date=?');
      $stmt->execute([$date]);
      if ((int)$stmt->fetchColumn() === 0){
      // default new day: start at 12:00, busy pattern (60 + 15), all blocked
      $hoursNoon = gen_hours('12:00',60,15);
      $ins = $this->pdo->prepare('INSERT OR IGNORE INTO slots(date,hour,name,whatsapp) VALUES(?,?,"","")');
      foreach($hoursNoon as $h){ $ins->execute([$date,$h]); }
      $blk = $this->pdo->prepare('UPDATE slots SET name="Not available" WHERE date=?');
      $blk->execute([$date]);
    }
    } else {
      $data = $this->readJson();
      if (!isset($data[$date])){
        $data[$date] = [];
        $hoursNoon = gen_hours('12:00',60,15);
        foreach($hoursNoon as $h){
          $data[$date][$h] = ["name"=>"Not available","whatsapp"=>""];
        }
        $this->writeJson($data);
      }
    }
  }
  public function getDay($date){
    if ($this->useSqlite){ $rows = $this->pdo->prepare('SELECT id,date,hour,name,whatsapp FROM slots WHERE date=? ORDER BY hour'); $rows->execute([$date]); return $rows->fetchAll(PDO::FETCH_ASSOC); }
    $data = $this->readJson(); $out=[]; foreach(($data[$date] ?? []) as $h=>$v){ $out[]=['id'=>$date.'|'.$h,'date'=>$date,'hour'=>$h,'name'=>$v['name'],'whatsapp'=>$v['whatsapp']]; } return $out;
  }
  public function book($date,$hour,$name,$wa){
    if ($this->useSqlite){ $sel = $this->pdo->prepare('SELECT id,name FROM slots WHERE date=? AND hour=?'); $sel->execute([$date,$hour]); $row = $sel->fetch(PDO::FETCH_ASSOC); if(!$row) return [false,'Not found']; if(!empty($row['name'])) return [false,'Taken']; $upd = $this->pdo->prepare('UPDATE slots SET name=?, whatsapp=? WHERE id=?'); $upd->execute([$name,$wa,$row['id']]); return [true,null]; }
    $data = $this->readJson(); if(!isset($data[$date][$hour])) return [false,'Not found']; if(!empty($data[$date][$hour]['name'])) return [false,'Taken']; $data[$date][$hour] = ['name'=>$name,'whatsapp'=>$wa]; $this->writeJson($data); return [true,null];
  }

  public function selfCancel($date,$hour,$wa){
    $wa = preg_replace('/[^0-9+]/','', $wa ?? '');
    if ($this->useSqlite){
      $sel = $this->pdo->prepare('SELECT id,whatsapp,name FROM slots WHERE date=? AND hour=?');
      $sel->execute([$date,$hour]);
      $row = $sel->fetch(PDO::FETCH_ASSOC);
      if(!$row) return [false,'Not found'];
      if(empty($row['name'])) return [false,'Already empty'];
      $stored = preg_replace('/[^0-9+]/','', $row['whatsapp'] ?? '');
      if($stored==='' || $wa==='' || $stored!==$wa) return [false,'WhatsApp mismatch'];
      $upd = $this->pdo->prepare('UPDATE slots SET name="", whatsapp="" WHERE id=?');
      $upd->execute([$row['id']]);
      return [true,null];
    }
    $data = $this->readJson();
    if(!isset($data[$date][$hour])) return [false,'Not found'];
    if(empty($data[$date][$hour]['name'])) return [false,'Already empty'];
    $stored = preg_replace('/[^0-9+]/','', $data[$date][$hour]['whatsapp'] ?? '');
    if($stored==='' || $wa==='' || $stored!==$wa) return [false,'WhatsApp mismatch'];
    $data[$date][$hour] = ['name'=>'','whatsapp'=>''];
    $this->writeJson($data);
    return [true,null];
  }
  public function adminUpdate($id,$name,$wa){ if ($this->useSqlite){ $u=$this->pdo->prepare('UPDATE slots SET name=?,whatsapp=? WHERE id=?'); $u->execute([$name,$wa,$id]); return true; } [$date,$hour]=explode('|',$id,2); $data=$this->readJson(); $data[$date][$hour]=['name'=>$name,'whatsapp'=>$wa]; $this->writeJson($data); return true; }
  public function adminDeleteHour($id){ if ($this->useSqlite){ $d=$this->pdo->prepare('DELETE FROM slots WHERE id=?'); $d->execute([$id]); return true; } [$date,$hour]=explode('|',$id,2); $data=$this->readJson(); unset($data[$date][$hour]); $this->writeJson($data); return true; }
  public function adminAddHour($date,$hour){ if ($this->useSqlite){ $i=$this->pdo->prepare('INSERT OR IGNORE INTO slots(date,hour,name,whatsapp) VALUES(?,?,"","")'); $i->execute([$date,$hour]); return true; } $data=$this->readJson(); if(!isset($data[$date])) $data[$date]=[]; if(!isset($data[$date][$hour])) $data[$date][$hour]=['name'=>'','whatsapp'=>'']; $this->writeJson($data); return true; }
  public function replaceDayWithHours($date,$hours){
    if ($this->useSqlite){
      $this->pdo->beginTransaction();
      try{
        $del = $this->pdo->prepare('DELETE FROM slots WHERE date=?');
        $del->execute([$date]);
        $ins = $this->pdo->prepare('INSERT INTO slots(date,hour,name,whatsapp) VALUES(?,?,"","")');
        foreach ($hours as $h){ $ins->execute([$date,$h]); }
        $this->pdo->commit();
      } catch (Throwable $e){
        $this->pdo->rollBack();
        throw $e;
      }
    } else {
      $data = $this->readJson();
      $data[$date] = [];
      foreach($hours as $h){ $data[$date][$h] = ['name'=>'','whatsapp'=>'']; }
      $this->writeJson($data);
    }
    return true;
  }
  private function readJson(){ if (!file_exists(DATA_FILE)) return []; $j=file_get_contents(DATA_FILE); return $j? json_decode($j,true):[]; }
  private function writeJson($arr){ file_put_contents(DATA_FILE, json_encode($arr,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
}
$store = new Store();

// --- content settings (editable) ---
$defaultContent = [
    'wa' => '',
    'waLabel' => 'WhatsApp',
  'profilePhotoUrl' => 'https://placehold.co/200x200',
  'pfp_w' => 96,
  'pfp_h' => 96,
  'nav' => ['book' => 'Book', 'sessions' => 'Sessions', 'about' => 'About'],
  'titles' => ['sessions' => 'Sessions', 'about' => 'About'],
  'titles_tr' => ['sessions' => 'Seanslar', 'about' => 'Ekin Yaşa'],
  'sections' => [
    'sessions' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vivamus a dui vitae libero.',
    'about'    => 'Short bio goes here. Replace from Admin → Content.'
  ],
  // TR labels/text
  'nav_tr' => ['book' => 'Randevu', 'sessions' => 'Seanslar', 'about' => 'Hakkında'],
  'sections_tr' => [
    'sessions' => 'Seanslar hakkında bilgiler buraya gelecek.',
    'about'    => 'Kısa biyografi metni buraya gelecek.'
  ]
];
if (!file_exists(CONTENT_FILE)) file_put_contents(CONTENT_FILE, json_encode($defaultContent, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$content = json_decode(@file_get_contents(CONTENT_FILE), true) ?: $defaultContent;

$navL = ($lang==='tr' && !empty($content['nav_tr'])) ? $content['nav_tr'] : $content['nav'];
$titlesL = ($lang==='tr' && !empty($content['titles_tr'])) ? $content['titles_tr'] : $content['titles'];

$sectionsL = ($lang==='tr' && !empty($content['sections_tr'])) ? $content['sections_tr'] : $content['sections'];

// --- notifications ---
function notify_pushover($title,$message,$isHtml=false){
  if (!PUSHOVER_USER || !PUSHOVER_TOKEN) return;
  $fields = ['token'=>PUSHOVER_TOKEN,'user'=>PUSHOVER_USER,'message'=>$message];
  if ($title!=='') $fields['title']=$title; // boş ise başlık ekleme
  if ($isHtml) $fields['html']=1;
  $ch=curl_init('https://api.pushover.net/1/messages.json');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>$fields]);
  curl_exec($ch); curl_close($ch);
}

// --- admin auth ---
function is_admin(){ return !empty($_SESSION['is_admin']); }

function admin_password_is_configured()
{
  if (ADMIN_PASSWORD_HASH !== '') {
    return true;
  }
  return (string)env('ADMIN_PASSWORD', '') !== '';
}

function verify_admin_password($plain)
{
  $plain = (string)($plain ?? '');
  $hash = ADMIN_PASSWORD_HASH;
  if ($hash !== '') {
    $info = password_get_info($hash);
    if (($info['algo'] ?? 0) !== 0) {
      return password_verify($plain, $hash);
    }
    return hash_equals($hash, $plain);
  }
  $legacy = (string)env('ADMIN_PASSWORD', '');
  if ($legacy !== '') {
    return hash_equals($legacy, $plain);
  }
  return false;
}

if (post('action')==='login'){
  $password = post('password','');
  if (!admin_password_is_configured()){
    $login_error='Admin password not configured';
  } elseif (verify_admin_password($password)){
    $_SESSION['is_admin']=true;
    header('Location: ?admin=1');
    exit;
  } else {
    $login_error='Wrong password';
  }
}
if (isset($_GET['logout'])){ $_SESSION=[]; session_destroy(); header('Location: ?'); exit; }

// --- routes-like ---
$date = iso_date($_GET['date'] ?? post('date') ?? today_iso());
if (!isset($_GET['admin'])){
  $today = new DateTime(today_iso());
  $dobj  = DateTime::createFromFormat('Y-m-d', $date) ?: clone $today;
  $minD  = clone $today;                 // today
  $maxD  = (clone $today)->modify('+2 days'); // today + 2
  if ($dobj < $minD) $dobj = $minD;
  if ($dobj > $maxD) $dobj = $maxD;
  $date = $dobj->format('Y-m-d');
}
$store->ensureDay($date, default_busy_hours());
$isAdminView = isset($_GET['admin']);
$tab = $_GET['tab'] ?? 'calendar';

// --- API-ish actions ---
if (post('action')==='book'){
  header('Content-Type: application/json; charset=UTF-8');
  $hour=post('hour');
  $name=trim(post('name'));
  $wa=norm_phone(post('whatsapp'));
  if(!$hour||!$name||!$wa){ echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
  [$ok,$err]=$store->book($date,$hour,$name,$wa);
  if($ok){
    $isToday = ($date===today_iso());
    if ($isToday){
      // Example: 15:00 NAME SURNAME
      $msg = $hour.' '.strtoupper($name);
    } else {
      // Example: 15 Aug Fri 15.00 Name Surname
      $dt = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);
      $pretty = $dt->format('j M D');
      $timeDot = str_replace(':','.', $hour);
      $msg = $pretty.' '.$timeDot.' '.$name;
    }
    notify_pushover($msg,$wa, true);
    echo json_encode(['ok'=>true]);
  } else {
    echo json_encode(['ok'=>false,'error'=>$err]);
  }
  exit;
}

if (post('action')==='self_cancel'){
  header('Content-Type: application/json; charset=UTF-8');
  $hour = post('hour');
  $wa   = norm_phone(post('whatsapp'));
  if(!$hour||!$wa){ echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
  [$ok,$err] = $store->selfCancel($date,$hour,$wa);
  if($ok){ echo json_encode(['ok'=>true]); }
  else   { echo json_encode(['ok'=>false,'error'=>$err]); }
  exit;
}
if ($isAdminView && is_admin() && post('action')==='admin_update'){
  header('Content-Type: application/json; charset=UTF-8'); $id=post('id'); $name=post('name'); $wa=norm_phone(post('whatsapp')); $store->adminUpdate($id,$name,$wa); echo json_encode(['ok'=>true]); exit;
}
if ($isAdminView && is_admin() && post('action')==='admin_delete'){
  header('Content-Type: application/json; charset=UTF-8'); $id=post('id'); $store->adminDeleteHour($id); echo json_encode(['ok'=>true]); exit;
}
if ($isAdminView && is_admin() && post('action')==='admin_add'){
  header('Content-Type: application/json; charset=UTF-8');
  $hour = trim(post('hour'));
  if(!$hour){ echo json_encode(['ok'=>false,'error'=>'Hour required']); exit; }
  $hour = str_replace('.',':',$hour);
  if (!preg_match('/^\\d{1,2}:\\d{2}$/',$hour)) { echo json_encode(['ok'=>false,'error'=>'Format HH:MM']); exit; }
  [$H,$M] = explode(':',$hour,2); $H = str_pad((string)(int)$H,2,'0',STR_PAD_LEFT); $hour = $H.':'.$M;
  $store->adminAddHour($date,$hour);
  echo json_encode(['ok'=>true]); exit;
}
if ($isAdminView && is_admin() && post('action')==='admin_apply_pattern'){
  header('Content-Type: application/json; charset=UTF-8');
  $pattern = strtoupper(trim(post('pattern','A')));
  // start saatini normalize et: 10.00 → 10:00, zero-pad
  $start   = trim(post('start','10:00'));
  $start   = str_replace('.',':',$start);
  if (!preg_match('/^\d{1,2}:\d{2}$/',$start)) { $start = '10:00'; }
  [$H,$M] = explode(':',$start,2); $H = str_pad((string)(int)$H,2,'0',STR_PAD_LEFT); $start = $H.':'.$M;

    // slot üretici (dakika bazlı, 20:00'e kadar)
  $build = function($startHHMM, $sessMin, $breakMin){
    $out=[];
    if (!preg_match('/^(\\d{1,2}):(\\d{2})$/',$startHHMM,$m)) return $out;
    $h = (int)$m[1]; $mm = (int)$m[2];
    $start = $h*60 + $mm;          // başlangıç dakika
    $limit = 20*60;                // 20:00 (dakika)
    while ($start < $limit){
      $H = intdiv($start,60); $M = $start%60;
      $out[] = sprintf('%02d:%02d', $H, $M);
      $start += $sessMin + $breakMin; // seans + mola → yeni başlangıç
    }
    return $out;
  };

    $hours = ($pattern==='A') ? $build($start,60,15) : $build($start,90,15);
  $hours = array_values(array_unique($hours));
  sort($hours, SORT_STRING);

  try {
    $store->replaceDayWithHours($date, $hours);
    echo json_encode(['ok'=>true,'hours'=>$hours]);
  } catch (Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}
if ($isAdminView && is_admin() && post('action')==='save_content'){
  header('Content-Type: application/json; charset=UTF-8');
  $content['profilePhotoUrl'] = trim(post('profilePhotoUrl')) ?: $content['profilePhotoUrl'];
  $content['nav']['book']     = trim(post('nav_book'))     ?: $content['nav']['book'];
  $content['nav']['sessions'] = trim(post('nav_sessions')) ?: $content['nav']['sessions'];
  $content['nav']['about']    = trim(post('nav_about'))    ?: $content['nav']['about'];
  $content['titles']['sessions'] = trim(post('title_sessions')) ?: ($content['titles']['sessions'] ?? 'Sessions');
  $content['titles']['about']    = trim(post('title_about'))    ?: ($content['titles']['about'] ?? 'About');
  $content['sections']['sessions'] = post('txt_sessions');
  $content['titles_tr']['sessions'] = trim(post('title_tr_sessions')) ?: ($content['titles_tr']['sessions'] ?? 'Seanslar');
  $content['titles_tr']['about']    = trim(post('title_tr_about'))    ?: ($content['titles_tr']['about'] ?? 'Ekin Yaşa');
  $content['sections']['about']    = post('txt_about');
  $content['wa'] = trim(post('wa'));
  $content['waLabel'] = trim(post('wa_label')) ?: ($content['waLabel'] ?? 'WhatsApp');
  $w = (int)(post('pfp_w') ?? 0); if ($w > 0) $content['pfp_w'] = $w;
  $h = (int)(post('pfp_h') ?? 0); if ($h > 0) $content['pfp_h'] = $h;

  // TR labels/texts
  $content['nav_tr']['book']     = trim(post('nav_tr_book'))     ?: ($content['nav_tr']['book'] ?? 'Randevu');
  $content['nav_tr']['sessions'] = trim(post('nav_tr_sessions')) ?: ($content['nav_tr']['sessions'] ?? 'Seanslar');
  $content['nav_tr']['about']    = trim(post('nav_tr_about'))    ?: ($content['nav_tr']['about'] ?? 'Hakkında');
  $content['sections_tr']['sessions'] = post('txt_sessions_tr');
  $content['sections_tr']['about']    = post('txt_about_tr');
  file_put_contents(CONTENT_FILE, json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>true]); exit;
}

// Get and sort rows for the day
$rows = $store->getDay($date);
if (is_array($rows)) {
  usort($rows, function($a,$b){ return strcmp($a['hour'],$b['hour']); });
} else {
  $rows = [];
}
 $base = base_url();
// Detect session length for display (60 or 90) using first gap minus 15m break
$sessLen = 60; // default
if (count($rows) >= 2) {
  $h0 = DateTime::createFromFormat('H:i', $rows[0]['hour']);
  $h1 = DateTime::createFromFormat('H:i', $rows[1]['hour']);
  if ($h0 && $h1) {
    $gap = (int)(($h1->getTimestamp() - $h0->getTimestamp())/60); // minutes
    $cand = max(30, $gap - 15);
    $sessLen = ($cand >= 85) ? 90 : 60;
  }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo h($titlesL['sessions']); ?><?php echo $isAdminView? ' – Admin':''; ?></title>
<style>
  :root{ --bg:#0b0c10; --card:#0f1117; --ink:#e6e6e6; --muted:#a6aabb; --line:#1f2533; --accentOld:#4f79ff; --accent:rgba(79, 121, 255, 0.66); --accent2:rgba(79, 121, 255, 0.32); --hdrH:16.66vh }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  a{color:#9ab3ff;text-decoration:none}
  .wrap{max-width:920px;margin:0 auto;padding:0 16px}
  .header{position:sticky;top:0;z-index:10;background:rgba(11,12,16,.8);backdrop-filter:saturate(140%) blur(8px);}
  :root { --hdrH: 16.66vh; }               /* header yüksekliği değişkeni */
  .bar{display:grid;grid-template-columns:1fr auto;align-items:center;min-height:16.66vh;padding:8px 0}
  .profile{display:flex;align-items:center;gap:12px}
  .pfp{border-radius:12px;object-fit:cover}
  .nav{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
  .nav a:not(.btn){padding:8px 10px;border-radius:999px;background:#1a1d27}
  .section{padding:18px 0;scroll-margin-top: calc(var(--hdrH) + 12px);}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px}
  .row{display:grid;grid-template-columns:110px 1fr;gap:10px;align-items:center;padding:8px 10px;border-radius:10px;background:#111319;margin-bottom:6px}
  .hour{font-weight:600}
  .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .btn{appearance:none;border:none;border-radius:8px;padding:8px 12px;font-weight:600;cursor:pointer}
  .btn.primary{background:var(--accent);color:#fff}
  .btn.secondary{background:var(--accent2);color:#fff}
  .btn.ghost{background:#1a1d27;color:var(--ink)}
  .btn.danger{background:rgba(185, 75, 75, 0.58);color:#fff}

  /* Segmented date buttons & safety guards */
  .seg{ display:flex; gap:6px; flex-wrap:wrap; justify-content:center; width:100%; position:relative; }
  .seg .btn{ display:inline-flex; align-items:center; justify-content:center; }
  .seg a{ flex: 0 0 auto; }
 
  .tabbtn{width: 48%; text-align: center}
  label { color:var(--muted)}
  #contentchanger label { display: block;
margin: 11px 0px 6px 7px;}
  input,textarea{
    width:100%;
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #2a2f3d;
    background:#0f1117;
    color:var(--ink);
    font-size:16px;         /* iOS zoom fix */
    line-height:1.3;
  }
  select,button{ font-size:16px }  /* iOS zoom fix (dialog butonları vs.) */
  textarea{min-height:140px}
  .grid1{display:grid;grid-template-columns:1fr;gap:8px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px}
  .muted{color:var(--muted)}
  .pill{background:#1a1d27;padding:4px 8px;border-radius:999px;font-size:11px}
  .badge-available{background:#12351f;color:#7fdb9b;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:600}
  .badge-booked{background:#36161a;color:#ff9aa2;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}
  .empty{opacity:.7}
  .topline{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px}
  .sticky-pad{height:10px}
  /* floating to top */
  .toTop{position:fixed;right:16px;bottom:16px;width:46px;height:46px;border-radius:999px;border:1px solid var(--line);background:#0f1117;display:none;align-items:center;justify-content:center;font-size:20px}
  .toTop.show{display:flex}
  /* dialog */
  dialog{ border:none; padding:0; background:transparent; }
  dialog::backdrop{ background: rgba(0,0,0,.8); }
  .dlg{ box-shadow:0 10px 30px rgba(0,0,0,.45); color:white}
  .btn[disabled]{ opacity:.5; cursor:not-allowed }
  .date-ctl{ display:inline-block }
  .date-overlay{ position:absolute; inset:0; opacity:0; cursor:pointer; }
  /* footer */
  .footer{padding:30px 0;color:var(--muted);text-align:center;font-size:12px}
  .footer a{color:var(--muted)}

  .gear{opacity:.25;font-size:18px;margin-left:6px}
  @media(min-width:720px){ .row{grid-template-columns:120px 1fr} }
</style>
</head>
<body>
<header class="header">
  <div class="wrap">
    <div class="bar">
      <div class="profile">
        <img class="pfp" src="<?php echo h($content['profilePhotoUrl']); ?>" alt="profile" id="pfp" style="width:<?php echo (int)($content['pfp_w'] ?? 96); ?>px;height:<?php echo (int)($content['pfp_h'] ?? 96); ?>px" />
      </div>
      <nav class="nav"> 
        <a href="#schedule" id="navBook" class="btn ghost"><?php echo h($navL['book']); ?></a>
        <a href="#sessions" id="navSessions" class="btn ghost"><?php echo h($navL['sessions']); ?></a>
        <a href="#about"    id="navAbout"  class="btn ghost"><?php echo h($navL['about']); ?></a>
        <?php if (!empty($content['wa'])): ?>
          <a href="https://wa.me/<?php echo h(preg_replace('/[^0-9]/','',$content['wa'])); ?>" target="_blank"><?php echo h($content['waLabel'] ?? 'WhatsApp'); ?></a>
        <?php endif; ?>
          
        <a href="https://www.instagram.com/bedenine.odaklan" target="_blank" class="pill"
           style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;">
          Instagram
        </a>
          
        <?php $to = ($lang==='tr'?'en':'tr'); $lab = strtoupper($to); ?>
        <a href="<?php echo h(lang_toggle_href($to)); ?>" title="Change language" class="pill" style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;text-transform:uppercase"><?php echo h($lab); ?></a>
      </nav>
    </div>
  </div>
</header>
<div class="sticky-pad"></div>

<main class="wrap">
  <!-- Schedule section -->
  <section id="schedule" class="section">
    <div class="topline">
      <?php if ($isAdminView): ?>
      <div class="date-ctl" style="position:relative;display:inline-block">
        <?php $year = date('Y'); $min = $year.'-01-01'; $max = $year.'-12-31'; ?>
        <button class="btn ghost" id="btnDate" type="button" title="Change day"></button>
        <input type="date" id="datePick" value="<?php echo h($date); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" class="date-overlay" />
      </div>
    <?php else: ?>
      <?php
        $t0 = new DateTime(today_iso());
        $t1 = (clone $t0)->modify('+1 day');
        $t2 = (clone $t0)->modify('+2 days');
        $lab0 = ($lang==='tr') ? 'Bugün' : 'Today';
        $lab1 = ($lang==='tr') ? 'Yarın' : 'Tomorrow';
        $trDays = ['Monday'=>'Pazartesi','Tuesday'=>'Salı','Wednesday'=>'Çarşamba','Thursday'=>'Perşembe','Friday'=>'Cuma','Saturday'=>'Cumartesi','Sunday'=>'Pazar'];
        $dayEn = $t2->format('l');
        $lab2 = ($lang==='tr') ? ($trDays[$dayEn] ?? $dayEn) : $dayEn; // Friday or Cuma
        $d0 = $t0->format('Y-m-d'); $d1 = $t1->format('Y-m-d'); $d2 = $t2->format('Y-m-d');
        $is0 = ($date === $d0); $is1 = ($date === $d1); $is2 = ($date === $d2);
      ?>
      <?php $segBtns = [ [$d0,$lab0,$is0], [$d1,$lab1,$is1], [$d2,$lab2,$is2] ]; ?>
      <div class="seg" role="tablist" aria-label="Choose day">
        <?php foreach($segBtns as [$d,$lab,$active]): $cls = $active ? 'primary' : 'ghost'; ?>
          <a class="btn <?php echo $cls; ?>" href="?date=<?php echo h($d); ?>"><?php echo h($lab); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    </div>
      
    <div class="muted" style="margin:8px 0; padding-left:16px"><small>
      <?php if($lang==='tr'): ?>
        Boş bir saate dokun, Ad Soyad ve telefon numaranla rezervasyon yap.(Rezervasyon iptali için ismine dokun).
      <?php else: ?>
        Tap an empty slot to book with your name surname and phone number. (Tap your name to cancel).
      <?php endif; ?>
        </small>
    </div>
      
    <div id="list">
      <?php foreach($rows as $r): 
            $wa = $r['whatsapp'] ?? ''; 
            $waLink = $wa ? 'https://wa.me/'.preg_replace('/[^0-9]/','',$wa) : ''; ?>
        <div class="row"
            <?php if(!$isAdminView): ?>
              <?php if(empty($r['name'])): ?>
                data-book-hour="<?php echo h($r['hour']); ?>" style="cursor:pointer"
              <?php else: ?>
                data-cancel-hour="<?php echo h($r['hour']); ?>" style="cursor:pointer"
              <?php endif; ?>
            <?php endif; ?>>
          <?php 
            $startDT = DateTime::createFromFormat('H:i',$r['hour']);
            $endStr = $startDT ? (clone $startDT)->modify('+' . (int)$sessLen . ' minutes')->format('H:i') : $r['hour'];
          ?>
          <div class="hour"><?php echo h($r['hour'].' – '.$endStr); ?></div>
          <div class="name">
            <?php if(!empty($r['name'])): ?>
              <span class="badge-booked"><?php echo h($r['name']); ?></span>
            <?php else: ?>
              <span class="badge-available">Add your name</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    
  </section>

  <!-- Sessions section -->
  <section id="sessions" class="section">
    <h2 style="margin:0 0 8px"><?php echo h($titlesL['sessions']); ?></h2>
   
    <div class="card">
      <div id="sessionsText"><?php echo nl2br(h($sectionsL['sessions'])); ?></div>
    </div>
  </section>

  <!-- About section -->
  <section id="about" class="section">
    <h2 style="margin:0 0 8px"><?php echo h($titlesL['about']); ?></h2>
    <div class="card">
      <div id="aboutText"><?php echo nl2br(h($sectionsL['about'])); ?></div>
    </div>
  </section>
</main>

<footer id="footer" class="footer">
  <div class="wrap">
    <div>
      <a href="?admin=1" title="Admin">© <?php echo date('Y'); ?></a>
      · <a href="https://www.ekinyasa.online" target="_blank" rel="noopener">ekinyasa.online</a>
      · <a href="https://www.instagram.com/bedenine.odaklan" target="_blank" rel="noopener">Instagram @bedenine.odaklan</a>
      · <a href="http://www.grinbergmethod.com.tr/index-en.html" target="_blank" rel="noopener">Grinberg Method</a>
    </div>
  </div>
</footer>

<button class="toTop" id="toTop" aria-label="Top">↑</button>

<!-- Booking dialog -->
<dialog id="bookDlg">
  <form method="dialog">
    <div class="dlg" style="padding:16px;background:#0f1117;border-radius:16px">
      <h3 style="margin:0 0 8px">Book a slot</h3>
      <div class="grid2">
        <div><label>Hour</label><input id="inpHour" disabled /></div>
      </div>
      <div class="grid2" style="margin-top:8px">
        <div><label>First name *</label><input id="inpFirst" placeholder="First name" /></div>
        <div><label>Last name *</label><input id="inpLast" placeholder="Last name" /></div>
      </div>
      <div style="margin-top:8px">
        <label>Phone Nr *</label><input id="inpWA" placeholder="+90xxxxxxxxxx" />
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn ghost" type="button" id="btnCancel">Cancel</button>
        <button class="btn primary" id="btnBook" type="button" disabled>Save</button>
      </div>
    </div>
  </form>
</dialog>

<!-- Self-cancel dialog -->
<dialog id="cancelDlg">
  <form method="dialog">
    <div class="dlg" style="padding:16px;background:#0f1117;border-radius:16px">
      <h3 style="margin:0 0 8px">Cancel your booking</h3>
      <div class="grid2">
        <div><label>Hour</label><input id="cancHour" disabled /></div>
      </div>
      <div style="margin-top:8px">
        <label>Confirm your Phone Nr *</label>
        <input id="cancWA" placeholder="+90xxxxxxxxxx" />
      </div>
      <div class="muted" style="margin-top:8px">Enter the same WhatsApp number you used when booking to confirm.</div>
      <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn ghost" type="button" id="btnCancelCancel">Keep booking</button>
        <button class="btn danger" id="btnDoCancel" type="button" disabled>Cancel booking</button>
      </div>
    </div>
  </form>
</dialog>

<?php if($isAdminView): ?>
  <div class="wrap" style="padding:16px">
    <div class="card">
      <div class="topline">
        <div><strong>Admin</strong></div>
        <div style="margin: 8px 0px 0px 0px;">
          <?php if(!is_admin()): ?>
            <form method="post" style="display:flex;gap:8px;align-items:center">
              <input type="password" name="password" placeholder="Password" />
              <input type="hidden" name="action" value="login" />
              <button class="btn primary">Login</button>
            </form>
          <?php else: ?>
            <a class="btn ghost"  href="?logout=1">Logout</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if(is_admin()): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px; padding-top: 20px">
          <a class="btn tabbtn <?php echo $tab==='calendar'?'primary':'ghost'; ?>" href="?admin=1&tab=calendar&date=<?php echo h($date); ?>">Calendar</a>
          <a class="btn tabbtn <?php echo $tab==='content'?'primary':'ghost'; ?>" href="?admin=1&tab=content&date=<?php echo h($date); ?>">Content</a>
        </div>

        <?php if($tab==='calendar'): ?>
          <div class="card" style="margin-bottom:10px">
            <div class="grid2">
              <div style="margin: 22px 0px 0px 0px;">
                <label class="muted">Pattern</label>
                <select id="patternSel" style="padding: 10px 12px;border-radius: 10px;border: 1px solid #2a2f3d;background: #0f1117;color: var(--ink);font-size: 16px;line-height: 1.3;height: 38px;">
                  <option value="A">Busy day (60m + 15m break)</option>
                  <option value="B">Easy day (90m + 15m break)</option>
                </select>
              </div>
              <div>
                <label class="muted">Start time</label>
                <input id="patternStart" placeholder="HH:MM" value="<?php echo h($rows[0]['hour'] ?? '10:00'); ?>" />
              </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
              <button class="btn secondary" id="btnApplyPattern" type="button">Apply to this day</button>
            </div>
          </div>
          <div class="grid2">
            <input id="newHour" placeholder="Add hour (e.g., 19:30)" />
            <button class="btn secondary" id="btnAddHour">Add</button>
          </div>
        
          <div id="adminList" style="margin-top:12px"></div>

        <?php else: ?>
          <div class="grid1" id="contentchanger">
            <div>
              <label>Photo URL</label>
              <input id="c_photo" value="<?php echo h($content['profilePhotoUrl']); ?>" />
            </div>
            <div class="grid2" style="margin-top:8px">
              <div>
                <label>Photo width</label>
                <input id="c_pfp_w" type="number" min="40" max="240" value="<?php echo (int)($content['pfp_w'] ?? 96); ?>" />
              </div>
              <div>
                <label>height (px)</label>
                <input id="c_pfp_h" type="number" min="40" max="240" value="<?php echo (int)($content['pfp_h'] ?? 96); ?>" />
              </div>
            </div>
            <div class="grid1" style="margin-top:8px">
                <div style="margin-top:8px">
                  <input id="c_wa_label" value="<?php echo h($content['waLabel'] ?? 'WhatsApp'); ?>" />
                </div>
                <div>
                  <input id="c_wa" value="<?php echo h($content['wa'] ?? ''); ?>" placeholder="+90xxxxxxxxxx" />
                </div>
            </div>
            <div class="grid1">
                
                <div><label>Calendar nav label</label><input id="c_nav_book" value="<?php echo h($content['nav']['book']); ?>" /></div>
                <div><label>Takvim  nav label</label><input id="c_nav_tr_book" value="<?php echo h($content['nav_tr']['book'] ?? 'Randevu'); ?>" /></div>
                
                
                <div><label>Text 1  nav label</label><input id="c_nav_sessions" value="<?php echo h($content['nav']['sessions']); ?>" /></div>
                <div><label>Text 1 title</label><input id="c_title_sessions" value="<?php echo h($content['titles']['sessions'] ?? 'Sessions'); ?>" /></div>
                <div><label>Text 1</label><textarea id="c_txt_sessions"><?php echo h($content['sections']['sessions']); ?></textarea></div>
                
                
                <div><label>Text 2  nav label</label><input id="c_nav_about" value="<?php echo h($content['nav']['about']); ?>" /></div>
                <div><label>Text 2 title</label><input id="c_title_about" value="<?php echo h($content['titles']['about'] ?? 'About'); ?>" /></div>
                <div><label>Text 2</label><textarea id="c_txt_about"><?php echo h($content['sections']['about']); ?></textarea></div>
                
                <div><label>Metin 1  nav label</label><input id="c_nav_tr_sessions" value="<?php echo h($content['nav_tr']['sessions'] ?? 'Seanslar'); ?>" /></div>
                <div><label>Metin 1 title</label><input id="c_title_tr_sessions" value="<?php echo h($content['titles_tr']['sessions'] ?? 'Seanslar'); ?>" /></div>
                <div><label>Metin 1 </label><textarea id="c_txt_sessions_tr"><?php echo h($content['sections_tr']['sessions'] ?? ''); ?></textarea></div>
                
                <div><label>Metin 2  nav label</label><input id="c_nav_tr_about" value="<?php echo h($content['nav_tr']['about'] ?? 'Hakkında'); ?>" /></div>
                <div><label>Metin 2 title</label><input id="c_title_tr_about" value="<?php echo h($content['titles_tr']['about'] ?? 'Ekin Yaşa'); ?>" /></div>
                <div><label>Metin 2</label><textarea id="c_txt_about_tr"><?php echo h($content['sections_tr']['about'] ?? ''); ?></textarea></div>
                
                
                

                


                
                
              
            </div>
     
          <div style="margin-top:8px"><button class="btn primary" id="btnSaveContent">Save content</button></div>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<script>
  const $ = (s)=>document.querySelector(s);
  const date = '<?php echo h($date); ?>';
  const list = $('#list');
  const datePick = $('#datePick');
  const toTop = $('#toTop');
  
  const header = document.querySelector('.header');
  document.querySelectorAll('.nav a').forEach(a=>{
    a.addEventListener('click', (e)=>{
      // dahili anchor ise default davranışı durdur
      const href = a.getAttribute('href') || '';
      if (href.startsWith('#')) {
        e.preventDefault();
        const target = document.querySelector(href);
        if (!target) return;
        const y = target.getBoundingClientRect().top + window.scrollY - header.offsetHeight - 12;
        window.scrollTo({ top: y, behavior: 'smooth' });
      }
    });
  });
    
  // --- ScrollSpy: aktif bölüm mavi (btn primary), diğerleri ghost ---
    (function(){
      const headerEl = document.querySelector('.header');
      const headerH  = headerEl ? headerEl.offsetHeight : 0;

      const linkById = {
        schedule: document.getElementById('navBook'),
        sessions: document.getElementById('navSessions'),
        about:    document.getElementById('navAbout')
      };

      function setActive(sectionId){
        for (const [id, a] of Object.entries(linkById)){
          if (!a) continue;
          if (id === sectionId){ a.classList.add('primary'); a.classList.remove('ghost'); }
          else { a.classList.add('ghost'); a.classList.remove('primary'); }
        }
      }

      // Açılışta: hash varsa ona göre, yoksa schedule
      const hash = (location.hash || '').replace('#','');
      if (hash && linkById[hash]) setActive(hash);
      else setActive('schedule');

      const observer = new IntersectionObserver((entries)=>{
        // En görünür olanı seç
        let best = { id:null, ratio:0 };
        for (const e of entries){
          if (!e.isIntersecting) continue;
          const id = e.target.id;
          if (e.intersectionRatio > best.ratio) best = { id, ratio: e.intersectionRatio };
        }
        if (best.id) setActive(best.id);
      }, {
        root: null,
        // Sticky header’ı hesaba kat → üstten offset kadar negatif margin
        rootMargin: `-${headerH + 12}px 0px -55% 0px`,
        threshold: [0.15, 0.33, 0.5, 0.66, 0.85]
      });

      ['schedule','sessions','about'].forEach(id=>{
        const el = document.getElementById(id);
        if (el) observer.observe(el);
      });

      // Butona tıklanınca da anında aktifliği güncelle
      Object.entries(linkById).forEach(([id, a])=>{
        if (!a) return;
        a.addEventListener('click', ()=>{
          setActive(id);
        });
      });
    })();

  // Delegated click handling for booking/cancel actions on the list
  if (list) {
    list.addEventListener('click', (ev)=>{
      const target = ev.target.closest('[data-book-hour],[data-cancel-hour]');
      if (!target) return;
      const bookH = target.getAttribute('data-book-hour');
      const cancH = target.getAttribute('data-cancel-hour');
      if (bookH) {
        $('#inpHour').value = bookH;
        $('#inpFirst').value = '';
        $('#inpLast').value  = '';
        $('#inpWA').value    = '';
        $('#btnBook').disabled = true;
        $('#bookDlg').showModal();
      } else if (cancH) {
        $('#cancHour').value = cancH;
        $('#cancWA').value = '';
        $('#btnDoCancel').disabled = true;
        $('#cancelDlg').showModal();
      }
    });
  }

  function validateForm(){
    const fn = ($('#inpFirst')?.value||'').trim();
    const ln = ($('#inpLast')?.value||'').trim();
    const wa = ($('#inpWA')?.value||'').trim();
    const ok = fn.length>0 && ln.length>0 && wa.length>=8;
    const btn = $('#btnBook'); if(btn) btn.disabled = !ok;
  }
  ['#inpFirst','#inpLast','#inpWA'].forEach(sel=>{
    const el=document.querySelector(sel); el && el.addEventListener('input', validateForm);
  });
  $('#btnCancel')?.addEventListener('click', ()=> document.getElementById('bookDlg').close());

  const btnDate = document.getElementById('btnDate');
  function fmtHuman(dStr){
    const d = new Date(dStr+'T00:00:00');
    const day = d.getDate();
    const mon = d.toLocaleString('en-GB',{month:'short'});
    const wk  = d.toLocaleString('en-GB',{weekday:'short'});
    return `${day} ${mon} ${wk}`; // 15 Aug Fri
  }
  if(btnDate){
    btnDate.textContent = fmtHuman(date);
  }

  // set dynamic title: Today / Tomorrow / Weekday sessions
  (function(){
    const el = document.getElementById('titleToday'); if(!el) return;
    const localYmd = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD in local tz
    const today = new Date(localYmd + 'T00:00:00');
    const d = new Date(date+'T00:00:00');
    const diffDays = Math.round((d - today)/(1000*60*60*24));
    if (diffDays === 0) el.textContent = "Today's sessions";
    else if (diffDays === 1) el.textContent = "Tomorrow's sessions";
    else {
      const wk = d.toLocaleString('en-GB',{weekday:'long'}); // Friday
      el.textContent = wk + ' sessions';
    }
  })();

  // change date → keep admin param if present
  datePick && datePick.addEventListener('change', ()=>{
    const v = datePick.value; const url = new URL(window.location.href); url.searchParams.set('date', v); window.location.href = url.toString();
  });

  $('#btnBook') && $('#btnBook').addEventListener('click', async (e)=>{
  e.preventDefault();
  const btn = document.getElementById('btnBook');
  const oldLabel = btn.textContent; btn.textContent = 'Saving…'; btn.disabled = true;

  const hour=$('#inpHour').value;
  const fn=($('#inpFirst')?.value||'').trim();
  const ln=($('#inpLast')?.value||'').trim();
  const wa=($('#inpWA')?.value||'').trim();
  if(!fn||!ln||wa.length<8){ btn.textContent = oldLabel; btn.disabled = false; return; }
  const name = fn + ' ' + ln;
  const form = new FormData();
  form.append('action','book'); form.append('date',date);
  form.append('hour',hour); form.append('name',name); form.append('whatsapp',wa);
  try{
    const res = await fetch(location.href, { method:'POST', body:form });
    const out = await res.json();
    if(out.ok){ document.getElementById('bookDlg').close(); location.reload(); }
    else { btn.textContent = oldLabel; btn.disabled = false; alert(out.error||'Error'); }
  }catch(err){
    btn.textContent = oldLabel; btn.disabled = false; alert('Network error');
  }
});


  // enable cancel when WA seems filled
  $('#cancWA')?.addEventListener('input',()=>{
    const v = ($('#cancWA')?.value||'').trim();
    $('#btnDoCancel').disabled = v.length < 8;
  });
  // close cancel dialog
  $('#btnCancelCancel')?.addEventListener('click', ()=> document.getElementById('cancelDlg').close());
  // perform cancel
  $('#btnDoCancel')?.addEventListener('click', async ()=>{
    const hour = $('#cancHour').value;
    const wa = $('#cancWA').value.trim();
    let f = new FormData(); f.append('action','self_cancel'); f.append('date',date); f.append('hour',hour); f.append('whatsapp',wa);
    const r = await fetch(location.href,{method:'POST',body:f});
    const o = await r.json();
    if(o.ok){ document.getElementById('cancelDlg').close(); location.reload(); }
    else { alert(o.error||'Error'); }
  });

  // scroll to top button
  window.addEventListener('scroll', ()=>{ if (window.scrollY>200) toTop.classList.add('show'); else toTop.classList.remove('show'); });
  toTop.addEventListener('click', ()=>window.scrollTo({top:0,behavior:'smooth'}));

  // Admin tabs dynamic pieces
  <?php if($isAdminView && is_admin()): ?>
    <?php if($tab==='calendar'): ?>
      (function(){
        const adminList=document.getElementById('adminList');
        const rows = <?php echo json_encode($rows ?? []); ?>;
        adminList.innerHTML='';
        rows.forEach(r=>{
          const div=document.createElement('div'); div.className='row';
          div.innerHTML=`<div class="hour">${r.hour}</div>
            <div>
              <div class="grid2">
                <input required value="${r.name||''}" data-id="${r.id}" data-f="name" placeholder="Name" />
                <input required value="${r.whatsapp||''}" data-id="${r.id}" data-f="whatsapp" placeholder="Phone Nr +90..." />
              </div>
              <div style="margin-top:8px;display:flex;gap:8px">
                <button class="btn secondary" data-id="${r.id}" data-act="save">Save</button>
                <button class="btn ghost" data-id="${r.id}" data-act="clear">Clear</button>
                <button class="btn danger" data-id="${r.id}" data-act="delete">Delete hour</button>
              </div>
            </div>`;
          adminList.appendChild(div);
        });
        document.addEventListener('click', async (e)=>{
          const act=e.target.getAttribute('data-act'); if(!act) return; const id=e.target.getAttribute('data-id');
          let form=new FormData(); form.append('date',date);
          if(act==='save'){ const name=document.querySelector(`input[data-id="${id}"][data-f="name"]`).value; const wa=document.querySelector(`input[data-id="${id}"][data-f="whatsapp"]`).value; form.append('action','admin_update'); form.append('id',id); form.append('name',name); form.append('whatsapp',wa); }
          if(act==='clear'){ form.append('action','admin_update'); form.append('id',id); form.append('name',''); form.append('whatsapp',''); }
          if(act==='delete'){ if(!confirm('Delete this hour?')) return; form.append('action','admin_delete'); form.append('id',id); }
          const res=await fetch(location.href,{method:'POST',body:form}); const o=await res.json(); if(o.ok) location.reload(); else alert(o.error||'Error');
        });

        const applyBtn = document.getElementById('btnApplyPattern');
        if (applyBtn) {
          applyBtn.addEventListener('click', async ()=>{
            applyBtn.disabled = true;
            try {
              const sel = document.getElementById('patternSel').value;
              const st  = document.getElementById('patternStart').value.trim() || '10:00';
              let f=new FormData();
              f.append('action','admin_apply_pattern');
              f.append('date',date);
              f.append('pattern',sel);
              f.append('start',st);
              const r=await fetch(location.href,{method:'POST',body:f});
              const t = await r.text();
              let o; try { o = JSON.parse(t); } catch(e){ alert('Server response:\n'+t.slice(0,200)); return; }
              if(o.ok) location.reload(); else alert(o.error||'Error');
            } catch(err){
              alert('Network error');
            } finally {
              applyBtn.disabled = false;
            }
          });
        }

      document.getElementById('btnAddHour').addEventListener('click', async()=>{ const hour=document.getElementById('newHour').value.trim(); if(!hour) return; let f=new FormData(); f.append('action','admin_add'); f.append('date',date); f.append('hour',hour); const r=await fetch(location.href,{method:'POST',body:f}); const o=await r.json(); if(o.ok) location.reload(); else alert(o.error||'Error'); });

      })();
    <?php else: ?>
      (function(){
        document.getElementById('btnSaveContent').addEventListener('click', async()=>{
          let f=new FormData(); f.append('action','save_content');
          f.append('profilePhotoUrl', document.getElementById('c_photo').value.trim());
          f.append('nav_book', document.getElementById('c_nav_book').value.trim());
          f.append('nav_sessions', document.getElementById('c_nav_sessions').value.trim());
          f.append('nav_about', document.getElementById('c_nav_about').value.trim());
          f.append('title_sessions', document.getElementById('c_title_sessions').value.trim());
          f.append('title_about', document.getElementById('c_title_about').value.trim());
          f.append('title_tr_sessions', document.getElementById('c_title_tr_sessions').value.trim());
          f.append('title_tr_about',   document.getElementById('c_title_tr_about').value.trim());
          f.append('txt_sessions', document.getElementById('c_txt_sessions').value);
          f.append('txt_about', document.getElementById('c_txt_about').value);
          f.append('wa', document.getElementById('c_wa').value.trim());
          f.append('wa_label', document.getElementById('c_wa_label').value.trim());
          f.append('pfp_w', document.getElementById('c_pfp_w').value.trim());
          f.append('pfp_h', document.getElementById('c_pfp_h').value.trim());

          // TR fields
          const trBook    = document.getElementById('c_nav_tr_book');
          const trSess    = document.getElementById('c_nav_tr_sessions');
          const trAbout   = document.getElementById('c_nav_tr_about');
          const trTxtSess = document.getElementById('c_txt_sessions_tr');
          const trTxtAbout= document.getElementById('c_txt_about_tr');

          if (trBook)    f.append('nav_tr_book', trBook.value.trim());
          if (trSess)    f.append('nav_tr_sessions', trSess.value.trim());
          if (trAbout)   f.append('nav_tr_about', trAbout.value.trim());
          if (trTxtSess) f.append('txt_sessions_tr', trTxtSess.value);
          if (trTxtAbout)f.append('txt_about_tr', trTxtAbout.value);
          
          const r = await fetch(location.href,{method:'POST',body:f});
          const o = await r.json();
          if(o.ok) { alert('Saved'); location.reload(); } else alert('Error');
        });
      })();
    <?php endif; ?>
  <?php endif; ?>
</script>
</body>
</html>
