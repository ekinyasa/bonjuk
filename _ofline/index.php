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
 * - Set ADMIN_PASSWORD; (optional) PUSHOVER_USER/TOKEN
 * - Open /schedule.php (public) and /schedule.php?admin=1 (admin)
 */

// === Settings ===
const ADMIN_PASSWORD = '13010010';
const DEFAULT_HOURS = ['10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00'];
const DATA_FILE  = __DIR__ . '/data.json';           // JSON fallback store
const DB_FILE    = __DIR__ . '/schedule.db';         // SQLite file (auto)
const CONTENT_FILE = __DIR__ . '/content.json';      // Editable content (nav labels, texts, photo)
// Pushover (optional)
const PUSHOVER_USER = 'u5x77gvh9yniz2ccy8zx929z6ppo2n';
const PUSHOVER_TOKEN = 'azep72gh6houi1huqoyt2skmucdtrn';

session_start();

// --- helpers ---
function today_iso() { return (new DateTime('now'))->format('Y-m-d'); }
function iso_date($s) { $d = DateTime::createFromFormat('Y-m-d', $s ?: ''); return $d ? $d->format('Y-m-d') : today_iso(); }
function norm_phone($p){ return preg_replace('/[^0-9+]/','', $p ?? ''); }
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function base_url(){ $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'; $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; $path = strtok($_SERVER['REQUEST_URI'],'?'); return $proto.'://'.$host.$path; }
function post($k,$d=''){ return $_POST[$k] ?? $d; }

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
      $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM slots WHERE date=?'); $stmt->execute([$date]);
      if ((int)$stmt->fetchColumn() === 0){ $ins = $this->pdo->prepare('INSERT OR IGNORE INTO slots(date,hour,name,whatsapp) VALUES(?,?,"","")'); foreach($hours as $h){ $ins->execute([$date,$h]); } }
    } else {
      $data = $this->readJson(); if (!isset($data[$date])){ $data[$date] = []; foreach($hours as $h){ $data[$date][$h] = ["name"=>"","whatsapp"=>""]; } $this->writeJson($data); }
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
  public function adminUpdate($id,$name,$wa){ if ($this->useSqlite){ $u=$this->pdo->prepare('UPDATE slots SET name=?,whatsapp=? WHERE id=?'); $u->execute([$name,$wa,$id]); return true; } [$date,$hour]=explode('|',$id,2); $data=$this->readJson(); $data[$date][$hour]=['name'=>$name,'whatsapp'=>$wa]; $this->writeJson($data); return true; }
  public function adminDeleteHour($id){ if ($this->useSqlite){ $d=$this->pdo->prepare('DELETE FROM slots WHERE id=?'); $d->execute([$id]); return true; } [$date,$hour]=explode('|',$id,2); $data=$this->readJson(); unset($data[$date][$hour]); $this->writeJson($data); return true; }
  public function adminAddHour($date,$hour){ if ($this->useSqlite){ $i=$this->pdo->prepare('INSERT OR IGNORE INTO slots(date,hour,name,whatsapp) VALUES(?,?,"","")'); $i->execute([$date,$hour]); return true; } $data=$this->readJson(); if(!isset($data[$date])) $data[$date]=[]; if(!isset($data[$date][$hour])) $data[$date][$hour]=['name'=>'','whatsapp'=>'']; $this->writeJson($data); return true; }
  private function readJson(){ if (!file_exists(DATA_FILE)) return []; $j=file_get_contents(DATA_FILE); return $j? json_decode($j,true):[]; }
  private function writeJson($arr){ file_put_contents(DATA_FILE, json_encode($arr,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
}
$store = new Store();

// --- content settings (editable) ---
$defaultContent = [
  'profilePhotoUrl' => 'https://placehold.co/200x200',
  'nav' => ['book' => 'Book', 'sessions' => 'Sessions', 'about' => 'About'],
  'sections' => [
    'sessions' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vivamus a dui vitae libero.',
    'about'    => 'Short bio goes here. Replace from Admin → Content.'
  ]
];
if (!file_exists(CONTENT_FILE)) file_put_contents(CONTENT_FILE, json_encode($defaultContent, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$content = json_decode(@file_get_contents(CONTENT_FILE), true) ?: $defaultContent;

// --- notifications ---
function notify_pushover($title,$message){ if (!PUSHOVER_USER || !PUSHOVER_TOKEN) return; $ch=curl_init('https://api.pushover.net/1/messages.json'); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>['token'=>PUSHOVER_TOKEN,'user'=>PUSHOVER_USER,'title'=>$title,'message'=>$message]]); curl_exec($ch); curl_close($ch); }

// --- admin auth ---
function is_admin(){ return !empty($_SESSION['is_admin']); }
if (post('action')==='login'){ if (post('password')===ADMIN_PASSWORD){ $_SESSION['is_admin']=true; header('Location: ?admin=1'); exit; } $login_error='Wrong password'; }
if (isset($_GET['logout'])){ $_SESSION=[]; session_destroy(); header('Location: ?'); exit; }

// --- routes-like ---
$date = iso_date($_GET['date'] ?? post('date') ?? today_iso());
$store->ensureDay($date, DEFAULT_HOURS);
$isAdminView = isset($_GET['admin']);
$tab = $_GET['tab'] ?? 'calendar';

// --- API-ish actions ---
if (post('action')==='book'){
  header('Content-Type: application/json; charset=UTF-8'); $hour=post('hour'); $name=trim(post('name')); $wa=norm_phone(post('whatsapp')); if(!$hour||!$name||!$wa){ echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; } [$ok,$err]=$store->book($date,$hour,$name,$wa); if($ok){ notify_pushover('New booking', "$date $hour • $name • $wa"); echo json_encode(['ok'=>true]); } else { echo json_encode(['ok'=>false,'error'=>$err]); } exit;
}
if ($isAdminView && is_admin() && post('action')==='admin_update'){
  header('Content-Type: application/json; charset=UTF-8'); $id=post('id'); $name=post('name'); $wa=norm_phone(post('whatsapp')); $store->adminUpdate($id,$name,$wa); echo json_encode(['ok'=>true]); exit;
}
if ($isAdminView && is_admin() && post('action')==='admin_delete'){
  header('Content-Type: application/json; charset=UTF-8'); $id=post('id'); $store->adminDeleteHour($id); echo json_encode(['ok'=>true]); exit;
}
if ($isAdminView && is_admin() && post('action')==='admin_add'){
  header('Content-Type: application/json; charset=UTF-8'); $hour=post('hour'); if(!$hour){ echo json_encode(['ok'=>false,'error'=>'Hour required']); exit; } $store->adminAddHour($date,$hour); echo json_encode(['ok'=>true]); exit;
}
if ($isAdminView && is_admin() && post('action')==='save_content'){
  header('Content-Type: application/json; charset=UTF-8');
  $content['profilePhotoUrl'] = trim(post('profilePhotoUrl')) ?: $content['profilePhotoUrl'];
  $content['nav']['book']     = trim(post('nav_book'))     ?: $content['nav']['book'];
  $content['nav']['sessions'] = trim(post('nav_sessions')) ?: $content['nav']['sessions'];
  $content['nav']['about']    = trim(post('nav_about'))    ?: $content['nav']['about'];
  $content['sections']['sessions'] = post('txt_sessions');
  $content['sections']['about']    = post('txt_about');
  file_put_contents(CONTENT_FILE, json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>true]); exit;
}

$rows = $store->getDay($date);
$base = base_url();

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Sessions<?php echo $isAdminView? ' – Admin':''; ?></title>
<style>
  :root{ --bg:#0b0c10; --card:#0f1117; --ink:#e6e6e6; --muted:#a6aabb; --line:#1f2533; --accent:#4f79ff }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  a{color:#9ab3ff;text-decoration:none}
  .wrap{max-width:920px;margin:0 auto;padding:0 16px}
  .header{position:sticky;top:0;z-index:10;background:rgba(11,12,16,.8);backdrop-filter:saturate(140%) blur(8px);}
  .bar{display:grid;grid-template-columns:1fr auto;align-items:center;min-height:16.66vh;padding:8px 0}
  .profile{display:flex;align-items:center;gap:12px}
  .pfp{width:64px;height:64px;border-radius:50%;object-fit:cover;border:1px solid var(--line)}
  .nav{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
  .nav a{padding:10px 12px;border-radius:999px;background:#1a1d27}
  .section{padding:18px 0}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
  .row{display:grid;grid-template-columns:110px 1fr;gap:12px;align-items:center;padding:10px 12px;border-radius:12px;background:#111319;margin-bottom:8px}
  .hour{font-weight:600}
  .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .btn{appearance:none;border:none;border-radius:10px;padding:10px 14px;font-weight:600;cursor:pointer}
  .btn.primary{background:var(--accent);color:#fff}
  .btn.ghost{background:#1a1d27;color:var(--ink)}
  .btn.danger{background:#b94b4b;color:#fff}
  input,textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid #2a2f3d;background:#0f1117;color:var(--ink)}
  textarea{min-height:140px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .muted{color:var(--muted)}
  .pill{background:#1a1d27;padding:6px 10px;border-radius:999px;font-size:12px}
  .empty{opacity:.7}
  .topline{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px}
  .sticky-pad{height:10px}
  /* floating to top */
  .toTop{position:fixed;right:16px;bottom:16px;width:46px;height:46px;border-radius:999px;border:1px solid var(--line);background:#0f1117;display:none;align-items:center;justify-content:center;font-size:20px}
  .toTop.show{display:flex}
  /* footer */
  .footer{padding:30px 0;color:var(--muted);text-align:center}
  .gear{opacity:.25;font-size:18px;margin-left:6px}
  @media(min-width:720px){ .pfp{width:80px;height:80px} .row{grid-template-columns:120px 1fr} }
</style>
</head>
<body>
<header class="header">
  <div class="wrap">
    <div class="bar">
      <div class="profile">
        <img class="pfp" src="<?php echo h($content['profilePhotoUrl']); ?>" alt="profile" id="pfp" />
        <div>
          <div style="font-weight:700">Ekin Yasa</div>
          <div class="muted" style="font-size:14px">Bodywork • Grinberg Method</div>
        </div>
      </div>
      <nav class="nav">
        <a href="#schedule" id="navBook"><?php echo h($content['nav']['book']); ?></a>
        <a href="#sessions" id="navSessions"><?php echo h($content['nav']['sessions']); ?></a>
        <a href="#about" id="navAbout"><?php echo h($content['nav']['about']); ?></a>
      </nav>
    </div>
  </div>
</header>
<div class="sticky-pad"></div>

<main class="wrap">
  <!-- Schedule section -->
  <section id="schedule" class="section">
    <div class="topline">
      <div>
        <h2 style="margin:0 0 4px">Today's sessions</h2>
        <div class="muted" id="dateLabel"></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="date" id="datePick" value="<?php echo h($date); ?>" />
        <button class="btn ghost" id="btnQR">QR</button>
      </div>
    </div>
    <div id="list"></div>
    <div class="muted" style="margin-top:8px">Tap an empty slot and enter your name + WhatsApp number (e.g., +90xxxxxxxxxx)</div>
  </section>

  <!-- Sessions section -->
  <section id="sessions" class="section">
    <h2 style="margin:0 0 8px">Sessions</h2>
    <div class="card">
      <div id="sessionsText"><?php echo nl2br(h($content['sections']['sessions'])); ?></div>
    </div>
  </section>

  <!-- About section -->
  <section id="about" class="section">
    <h2 style="margin:0 0 8px">About</h2>
    <div class="card">
      <div id="aboutText"><?php echo nl2br(h($content['sections']['about'])); ?></div>
    </div>
  </section>
</main>

<footer id="footer" class="footer">
  <div class="wrap">
    <div>
      © <?php echo date('Y'); ?> Ekin Yasa · <a href="https://instagram.com/" target="_blank">Instagram</a>
      <!-- hidden-ish admin link -->
      <a href="?admin=1" class="gear" title="Admin">⚙︎</a>
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
        <div><label>Date</label><input id="inpDate" disabled value="<?php echo h($date); ?>" /></div>
      </div>
      <div class="grid2" style="margin-top:8px">
        <div><label>Full name *</label><input id="inpName" placeholder="Your name" required /></div>
        <div><label>WhatsApp *</label><input id="inpWA" placeholder="+90xxxxxxxxxx" required /></div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn ghost" value="cancel">Cancel</button>
        <button class="btn primary" id="btnBook">Save</button>
      </div>
    </div>
  </form>
</dialog>

<?php if($isAdminView): ?>
  <div class="wrap" style="padding:16px">
    <div class="card">
      <div class="topline">
        <div><strong>Admin</strong></div>
        <div>
          <?php if(!is_admin()): ?>
            <form method="post" style="display:flex;gap:8px;align-items:center">
              <input type="password" name="password" placeholder="Password" />
              <input type="hidden" name="action" value="login" />
              <button class="btn primary">Login</button>
            </form>
          <?php else: ?>
            <a class="btn ghost" href="?logout=1">Logout</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if(is_admin()): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
          <a class="btn <?php echo $tab==='calendar'?'primary':'ghost'; ?>" href="?admin=1&tab=calendar&date=<?php echo h($date); ?>">Calendar</a>
          <a class="btn <?php echo $tab==='content'?'primary':'ghost'; ?>" href="?admin=1&tab=content&date=<?php echo h($date); ?>">Content</a>
        </div>

        <?php if($tab==='calendar'): ?>
          <div class="grid2">
            <input id="newHour" placeholder="Add hour (e.g., 19:30)" />
            <button class="btn primary" id="btnAddHour">Add</button>
          </div>
          <div id="adminList" style="margin-top:12px"></div>
        <?php else: ?>
          <div class="grid2">
            <div>
              <label>Profile photo URL</label>
              <input id="c_photo" value="<?php echo h($content['profilePhotoUrl']); ?>" />
            </div>
            <div class="grid2">
              <div><label>Nav – Book</label><input id="c_nav_book" value="<?php echo h($content['nav']['book']); ?>" /></div>
              <div><label>Nav – Sessions</label><input id="c_nav_sessions" value="<?php echo h($content['nav']['sessions']); ?>" /></div>
            </div>
            <div><label>Nav – About</label><input id="c_nav_about" value="<?php echo h($content['nav']['about']); ?>" /></div>
          </div>
          <div class="grid2" style="margin-top:8px">
            <div>
              <label>Sessions text</label>
              <textarea id="c_txt_sessions"><?php echo h($content['sections']['sessions']); ?></textarea>
            </div>
            <div>
              <label>About text</label>
              <textarea id="c_txt_about"><?php echo h($content['sections']['about']); ?></textarea>
            </div>
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
  const dateLabel = $('#dateLabel');
  const toTop = $('#toTop');

  // pretty date label
  dateLabel.textContent = new Date(date+'T00:00:00').toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });

  // change date → keep admin param if present
  datePick && datePick.addEventListener('change', ()=>{
    const v = datePick.value; const url = new URL(window.location.href); url.searchParams.set('date', v); window.location.href = url.toString();
  });

  // booking dialog
  function openBook(hour, date){ $('#inpHour').value = hour; $('#inpDate').value = date; $('#inpName').value=''; $('#inpWA').value=''; $('#bookDlg').showModal(); }
  $('#btnBook') && $('#btnBook').addEventListener('click', async (e)=>{
    e.preventDefault(); const hour=$('#inpHour').value, name=$('#inpName').value.trim(), wa=$('#inpWA').value.trim(); if(!name||!wa){ alert('Required'); return; }
    const form = new FormData(); form.append('action','book'); form.append('date',date); form.append('hour',hour); form.append('name',name); form.append('whatsapp',wa);
    const res = await fetch(location.href, { method:'POST', body:form }); const out = await res.json(); if(out.ok){ $('#bookDlg').close(); alert('Booked. Thank you!'); location.reload(); } else alert(out.error||'Error');
  });

  // QR (placeholder canvas label to avoid extra lib on shared hosts)
  $('#btnQR') && $('#btnQR').addEventListener('click', ()=>{
    const u = new URL('<?php echo h($base); ?>'); u.searchParams.set('date', date); u.searchParams.delete('admin');
    const dlg = document.createElement('dialog'); dlg.innerHTML = '<div style="padding:16px;background:#0f1117;border-radius:16px"><h3 style="margin:0 0 8px">QR</h3><div id="qr" style="background:#fff;width:220px;height:220px"></div><div class="muted" style="margin-top:8px">'+u.toString()+'</div><div style="margin-top:12px;display:flex;justify-content:flex-end"><button class="btn ghost" id="qrc">Close</button></div></div>'; document.body.appendChild(dlg); dlg.showModal();
    const c = document.createElement('canvas'); c.width=c.height=220; const ctx=c.getContext('2d'); ctx.fillStyle='#000'; ctx.font='12px monospace'; ctx.fillText('Scan URL:',10,20); ctx.fillText(u.toString().slice(0,24)+'...',10,40); document.getElementById('qr').appendChild(c);
    dlg.querySelector('#qrc').addEventListener('click',()=>dlg.close());
  });

  // scroll to top button
  window.addEventListener('scroll', ()=>{ if (window.scrollY>200) toTop.classList.add('show'); else toTop.classList.remove('show'); });
  toTop.addEventListener('click', ()=>window.scrollTo({top:0,behavior:'smooth'}));

  // Admin tabs dynamic pieces
  <?php if($isAdminView && is_admin()): ?>
    <?php if($tab==='calendar'): ?>
      (function(){
        const adminList=document.getElementById('adminList');
        const rows = <?php echo json_encode($rows); ?>;
        adminList.innerHTML='';
        rows.forEach(r=>{
          const div=document.createElement('div'); div.className='row';
          div.innerHTML=`<div class="hour">${r.hour}</div>
            <div>
              <div class="grid2">
                <input value="${r.name||''}" data-id="${r.id}" data-f="name" placeholder="Name" />
                <input value="${r.whatsapp||''}" data-id="${r.id}" data-f="whatsapp" placeholder="WhatsApp (+90...)" />
              </div>
              <div style="margin-top:8px;display:flex;gap:8px">
                <button class="btn primary" data-id="${r.id}" data-act="save">Save</button>
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
          const res=await fetch(location.href,{method:'POST',body:form}); const out=await res.json(); if(out.ok) location.reload(); else alert(out.error||'Error');
        });
        document.getElementById('btnAddHour').addEventListener('click', async()=>{ const hour=document.getElementById('newHour').value.trim(); if(!hour) return; let f=new FormData(); f.append('action','admin_add'); f.append('date',date); f.append('hour',hour); const r=await fetch(location.href,{method:'POST',body:f}); const o=await r.json(); if(o.ok) location.reload(); else alert(out.error||'Error'); });
      })();
    <?php else: ?>
      (function(){
        document.getElementById('btnSaveContent').addEventListener('click', async()=>{
          let f=new FormData(); f.append('action','save_content');
          f.append('profilePhotoUrl', document.getElementById('c_photo').value.trim());
          f.append('nav_book', document.getElementById('c_nav_book').value.trim());
          f.append('nav_sessions', document.getElementById('c_nav_sessions').value.trim());
          f.append('nav_about', document.getElementById('c_nav_about').value.trim());
          f.append('txt_sessions', document.getElementById('c_txt_sessions').value);
          f.append('txt_about', document.getElementById('c_txt_about').value);
          const r = await fetch(location.href,{method:'POST',body:f}); const o = await r.json(); if(o.ok) { alert('Saved'); location.reload(); } else alert('Error');
        });
      })();
    <?php endif; ?>
  <?php endif; ?>
</script>
</body>
</html>