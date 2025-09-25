<?php
/**
 * Minimal Schedule Board – single-file PHP (shared hosting friendly)
 * ------------------------------------------------------------------
 * Özellikler
 * - Public: İki sütun tablo (Saat | İsim). Boş slota tıklayınca Ad Soyad + WhatsApp ile randevu alır.
 * - Admin: /?admin=1 ile giriş → saat ekle/sil, isim/numara düzenle/temizle.
 * - Bildirim: Pushover (opsiyonel). iPhone'a push gönderir.
 * - Veri: Varsayılan JSON dosyası (data.json). İstersen SQLite de kullanabilirsin (PDO sqlite mevcutsa otomatik kullanır).
 * - QR: Sayfa içinde QR üretimi (QRcode.js gömülü).
 *
 * Kurulum
 * 1) Bu dosyayı sunucuna schedule.php olarak yükle.
 * 2) (Opsiyonel) Pushover için aşağıdaki sabitleri doldur.
 * 3) Tarayıcıda aç: /schedule.php (kamu) ve /schedule.php?admin=1 (admin)
 */

// === Ayarlar ===
const ADMIN_PASSWORD = '13010010'; // Admin giriş şifresi (zorunlu değiştir)
const DEFAULT_HOURS = ['10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00'];
const DATA_FILE = __DIR__ . '/data.json';
// Pushover (opsiyonel)
const PUSHOVER_USER = 'u5x77gvh9yniz2ccy8zx929z6ppo2n';
const PUSHOVER_TOKEN = 'azep72gh6houi1huqoyt2skmucdtrn';


session_set_cookie_params([
  'path' => '/',          // kökten geçerli
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();

// --- Basit yardımcılar ---
function today_iso() { return (new DateTime('now'))->format('Y-m-d'); }
function iso_date($s) {
  $d = DateTime::createFromFormat('Y-m-d', $s ?: '');
  return $d ? $d->format('Y-m-d') : today_iso();
}
function norm_phone($p){ return preg_replace('/[^0-9+]/','', $p ?? ''); }
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function base_url(){
  $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path = strtok($_SERVER['REQUEST_URI'],'?');
  return $proto.'://'.$host.$path;
}

// --- Depo: JSON ya da SQLite ---
class Store {
  private $useSqlite = false; private $pdo = null;
  public function __construct(){
    try {
      if (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        $this->pdo = new PDO('sqlite:'.__DIR__.'/schedule.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS slots (id INTEGER PRIMARY KEY AUTOINCREMENT, date TEXT, hour TEXT, name TEXT, whatsapp TEXT);\nCREATE UNIQUE INDEX IF NOT EXISTS idx ON slots(date,hour);");
        $this->useSqlite = true;
      }
    } catch(Throwable $e){ $this->useSqlite = false; }
  }
  public function ensureDay($date, $hours){
    if ($this->useSqlite){
      $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM slots WHERE date=?');
      $stmt->execute([$date]);
      if ((int)$stmt->fetchColumn() === 0){
        $ins = $this->pdo->prepare('INSERT OR IGNORE INTO slots(date,hour,name,whatsapp) VALUES(?,?,"","")');
        foreach($hours as $h){ $ins->execute([$date,$h]); }
      }
    } else {
      $data = $this->readJson();
      if (!isset($data[$date])){ $data[$date] = []; foreach($hours as $h){ $data[$date][$h] = ["name"=>"","whatsapp"=>""]; } $this->writeJson($data); }
    }
  }
  public function getDay($date){
    if ($this->useSqlite){
      $rows = $this->pdo->prepare('SELECT id,date,hour,name,whatsapp FROM slots WHERE date=? ORDER BY hour');
      $rows->execute([$date]);
      return $rows->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $data = $this->readJson();
      $out = [];
      foreach(($data[$date] ?? []) as $h=>$v){ $out[] = ['id'=>$date.'|'.$h,'date'=>$date,'hour'=>$h,'name'=>$v['name'],'whatsapp'=>$v['whatsapp']]; }
      ksort($out); return $out;
    }
  }
  public function book($date,$hour,$name,$wa){
    if ($this->useSqlite){
      $sel = $this->pdo->prepare('SELECT id,name FROM slots WHERE date=? AND hour=?');
      $sel->execute([$date,$hour]); $row = $sel->fetch(PDO::FETCH_ASSOC);
      if (!$row) return [false,'Saat bulunamadı'];
      if (!empty($row['name'])) return [false,'Bu saat dolu'];
      $upd = $this->pdo->prepare('UPDATE slots SET name=?, whatsapp=? WHERE id=?');
      $upd->execute([$name,$wa,$row['id']]);
      return [true,null];
    } else {
      $data = $this->readJson();
      if (!isset($data[$date][$hour])) return [false,'Saat bulunamadı'];
      if (!empty($data[$date][$hour]['name'])) return [false,'Bu saat dolu'];
      $data[$date][$hour] = ['name'=>$name,'whatsapp'=>$wa];
      $this->writeJson($data); return [true,null];
    }
  }
  public function adminUpdate($id,$name,$wa){
    if ($this->useSqlite){
      $u = $this->pdo->prepare('UPDATE slots SET name=?,whatsapp=? WHERE id=?');
      $u->execute([$name,$wa,$id]); return true;
    } else {
      [$date,$hour] = explode('|',$id,2);
      $data = $this->readJson(); $data[$date][$hour] = ['name'=>$name,'whatsapp'=>$wa];
      $this->writeJson($data); return true;
    }
  }
  public function adminDeleteHour($id){
    if ($this->useSqlite){
      $d = $this->pdo->prepare('DELETE FROM slots WHERE id=?'); $d->execute([$id]); return true;
    } else {
      [$date,$hour] = explode('|',$id,2);
      $data = $this->readJson(); unset($data[$date][$hour]); $this->writeJson($data); return true;
    }
  }
  public function adminAddHour($date,$hour){
    if ($this->useSqlite){
      $i = $this->pdo->prepare('INSERT OR IGNORE INTO slots(date,hour,name,whatsapp) VALUES(?,?,"","")'); $i->execute([$date,$hour]); return true;
    } else {
      $data = $this->readJson(); if (!isset($data[$date])) $data[$date] = [];
      if (!isset($data[$date][$hour])) $data[$date][$hour] = ['name'=>'','whatsapp'=>''];
      $this->writeJson($data); return true;
    }
  }
  private function readJson(){ if (!file_exists(DATA_FILE)) return []; $j = file_get_contents(DATA_FILE); return $j? json_decode($j,true):[]; }
  private function writeJson($arr){ file_put_contents(DATA_FILE, json_encode($arr,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
}
$store = new Store();

// --- Bildirim ---
function notify_pushover($title,$message){
  if (!PUSHOVER_USER || !PUSHOVER_TOKEN) return;
  $ch = curl_init('https://api.pushover.net/1/messages.json');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>[
    'token'=>PUSHOVER_TOKEN,'user'=>PUSHOVER_USER,'title'=>$title,'message'=>$message
  ]]);
  curl_exec($ch); curl_close($ch);
}

// --- Admin login ---
function is_admin(){ return !empty($_SESSION['is_admin']); }
if (isset($_POST['action']) && $_POST['action']==='login'){
  if (($_POST['password'] ?? '') === ADMIN_PASSWORD){ $_SESSION['is_admin']=true; header('Location: ?admin=1'); exit; }
  $login_error = 'Hatalı şifre';
}
if (isset($_GET['logout'])){ $_SESSION=[]; session_destroy(); header('Location: ?'); exit; }

// --- API tarzı işlemler ---
$date = iso_date($_GET['date'] ?? $_POST['date'] ?? today_iso());
$store->ensureDay($date, DEFAULT_HOURS);

if (isset($_POST['action'])){
  header('Content-Type: application/json; charset=UTF-8');
  if ($_POST['action']==='book'){
    $hour = $_POST['hour'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $wa   = norm_phone($_POST['whatsapp'] ?? '');
    if (!$hour || !$name || !$wa){ echo json_encode(['ok'=>false,'error'=>'Eksik bilgi']); exit; }
    [$ok,$err] = $store->book($date,$hour,$name,$wa);
    if ($ok){ notify_pushover('Yeni randevu', "$date $hour • $name • $wa"); echo json_encode(['ok'=>true]); } else { echo json_encode(['ok'=>false,'error'=>$err]); }
    exit;
  }
  if ($_POST['action']==='admin_update' && is_admin()){
    $id = $_POST['id'] ?? ''; $name = $_POST['name'] ?? ''; $wa = norm_phone($_POST['whatsapp'] ?? '');
    $store->adminUpdate($id,$name,$wa); echo json_encode(['ok'=>true]); exit;
  }
  if ($_POST['action']==='admin_delete' && is_admin()){
    $id = $_POST['id'] ?? ''; $store->adminDeleteHour($id); echo json_encode(['ok'=>true]); exit;
  }
  if ($_POST['action']==='admin_add' && is_admin()){
    $hour = $_POST['hour'] ?? ''; if(!$hour){ echo json_encode(['ok'=>false,'error'=>'Saat gerekli']); exit; }
    $store->adminAddHour($date,$hour); echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false,'error'=>'İzin yok']); exit;
}

// --- Görünüm ---
$rows = $store->getDay($date);
$isAdminView = isset($_GET['admin']);
$base = base_url();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Randevu<?php echo $isAdminView? ' – Admin':''; ?></title>
<style>
  *{box-sizing:border-box} body{font-family:-apple-system,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#0b0c10;color:#e6e6e6}
  .wrap{max-width:780px;margin:24px auto;padding:16px}
  h1{font-size:22px;margin:0 0 8px}
  .muted{opacity:.7}
  .row{display:grid;grid-template-columns:120px 1fr;gap:12px;align-items:center;padding:10px 12px;border-radius:12px;background:#111319;margin-bottom:8px}
  .hour{font-weight:600}
  .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .btn{appearance:none;border:none;border-radius:10px;padding:10px 14px;font-weight:600;cursor:pointer}
  .btn.primary{background:#4f79ff;color:#fff}
  .btn.ghost{background:#1a1d27;color:#e6e6e6}
  .btn.danger{background:#b94b4b;color:#fff}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid #2a2f3d;background:#0f1117;color:#e6e6e6}
  .pill{background:#1a1d27;padding:6px 10px;border-radius:999px;font-size:12px}
  .empty{opacity:.7}
  .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:8px}
  .bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .card{background:#0f1117;border:1px solid #1f2533;border-radius:14px;padding:12px;margin-top:10px}
  .link{color:#9ab3ff;text-decoration:none}
  .foot{margin-top:16px;font-size:12px}
  .badge{background:#3a3f52;padding:6px 10px;border-radius:999px;font-size:12px}
  dialog{border:none;border-radius:16px;padding:0;max-width:420px;width:92vw;background:#0f1117;color:#e6e6e6}
  dialog .dlg{padding:16px}
  dialog::backdrop{background:rgba(0,0,0,.6)}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>Randevu Tablosu <?php if($isAdminView) echo '<span class="badge">Admin</span>'; ?></h1>
      <div class="muted" id="dateLabel"></div>
    </div>
    <div class="bar">
      <input type="date" id="datePick" value="<?php echo h($date); ?>" />
      <button class="btn ghost" id="btnQR">QR</button>
      <?php if($isAdminView): ?>
        <?php if(!is_admin()): ?>
          <form method="post" style="display:flex;gap:8px;align-items:center">
            <input type="password" name="password" placeholder="Admin şifre" />
            <input type="hidden" name="action" value="login" />
            <button class="btn primary">Giriş</button>
          </form>
        <?php else: ?>
          <a class="btn ghost" href="?logout=1">Çıkış</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if($isAdminView && is_admin()): ?>
    <div class="card">
      <div class="grid2">
        <input id="newHour" placeholder="Yeni saat ekle (örn: 19:30)" />
        <button class="btn primary" id="btnAddHour">Saati Ekle</button>
      </div>
    </div>
  <?php endif; ?>

  <div id="list">
    <?php foreach($rows as $r): $wa = $r['whatsapp'] ?? ''; $waLink = $wa ? 'https://wa.me/'.preg_replace('/[^0-9]/','',$wa) : ''; ?>
      <div class="row" <?php if(empty($r['name']) && !$isAdminView): ?>data-book-hour="<?php echo h($r['hour']); ?>" style="cursor:pointer"<?php endif; ?>>
        <div class="hour"><?php echo h($r['hour']); ?></div>
        <div class="name">
          <?php if(!empty($r['name'])): ?>
            <span class="pill"><?php echo h($r['name']); ?></span>
            <?php if($waLink): ?> <a class="link" href="<?php echo h($waLink); ?>" target="_blank">WhatsApp</a><?php endif; ?>
          <?php else: ?>
            <span class="empty">(Boş)</span>
          <?php endif; ?>
          <?php if($isAdminView && is_admin()): ?>
            <div class="grid2" style="margin-top:8px">
              <input value="<?php echo h($r['name']); ?>" data-id="<?php echo h($r['id']); ?>" data-f="name" placeholder="İsim" />
              <input value="<?php echo h($wa); ?>" data-id="<?php echo h($r['id']); ?>" data-f="whatsapp" placeholder="WhatsApp (+90...)" />
            </div>
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn primary" data-id="<?php echo h($r['id']); ?>" data-act="save">Kaydet</button>
              <button class="btn ghost" data-id="<?php echo h($r['id']); ?>" data-act="clear">Temizle</button>
              <button class="btn danger" data-id="<?php echo h($r['id']); ?>" data-act="delete">Saati Sil</button>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="foot muted">Boş bir saate tıklayıp adınızı ve WhatsApp numaranızı girin. Örn: +90xxxxxxxxxx</div>
</div>

<dialog id="bookDlg">
  <form method="dialog">
    <div class="dlg">
      <h3 style="margin:0 0 8px">Randevu Al</h3>
      <div class="grid2">
        <div><label>Saat</label><input id="inpHour" disabled /></div>
        <div><label>Tarih</label><input id="inpDate" disabled value="<?php echo h($date); ?>" /></div>
      </div>
      <div class="grid2" style="margin-top:8px">
        <div><label>İsim Soyisim *</label><input id="inpName" placeholder="Adınız Soyadınız" required /></div>
        <div><label>WhatsApp *</label><input id="inpWA" placeholder="+90xxxxxxxxxx" required /></div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn ghost" value="cancel">Vazgeç</button>
        <button class="btn primary" id="btnBook">Kaydet</button>
      </div>
    </div>
  </form>
</dialog>

<!-- QRCode.js (min) -->
<script>
/*! QRCode.js v1.0.0 (minified) https://github.com/davidshimjs/qrcodejs */
!function(o){function v(a){this.mode=d.MODE_8BIT_BYTE,this.data=a,this.parsedData=[];for(var b=0,c=this.data.length;b<c;b++){var e=[],f=this.data.charCodeAt(b);f>65536?(e[0]=240|(1835008&f)>>>18,e[1]=128|(258048&f)>>>12,e[2]=128|(4032&f)>>>6,e[3]=128|63&f):f>2048?(e[0]=224|(61440&f)>>>12,e[1]=128|(4032&f)>>>6,e[2]=128|63&f):f>128?(e[0]=192|(1984&f)>>>6,e[1]=128|63&f):e[0]=f,this.parsedData=this.parsedData.concat(e)}this.parsedData.length!=this.data.length&&(this.parsedData.unshift(191),this.parsedData.unshift(187),this.parsedData.unshift(239))}function y(a,b){this.typeNumber=a,this.errorCorrectLevel=b,this.modules=null,this.moduleCount=0,this.dataCache=null,this.dataList=[]}function A(a,b){if(void 0==a.length)throw new Error(a.length+"/"+b);for(var c=0;c<a.length&&0==a[c];)c++;this.num=new Array(a.length-c+b);for(var d=0;d<a.length-c;d++)this.num[d]=a[d+c]}function B(a,b){this.totalCount=a,this.dataCount=b}function C(){this.buffer=[],this.length=0}var d={};v.prototype={getLength:function(){return this.parsedData.length},write:function(a){for(var b=0,c=this.parsedData.length;b<c;b++)a.put(this.parsedData[b],8)}},d.PAD0=236,d.PAD1=17,d.createData=function(a,b,c){for(var e=new C,f=0;f<c.length;f++){var g=c[f];e.put(g.mode,4),e.put(g.getLength(),d.getLengthInBits(g.mode,a)),g.write(e)}for(var h=d.getRSBlocks(a,b),i=0;i<h.length;i++)e.put(0,4);for(;e.getLengthInBits()+4<=8*h.length;)e.put(0,4);for(;e.getLengthInBits()%8!=0;)e.putBit(!1);for(var j=0,k=0;k<h.length;k++)j+=h[k].dataCount;for(var l=new Array(j),m=0;m<l.length;m++)l[m]=255;for(var n=0;n<h.length;n++){for(var o=h[n].dataCount,p=new Array(o),q=0;q<o;q++)p[q]=255;for(var r=0;r<p.length;r++)p[r]=255;for(var s=0;s<h.length;s++);}return d.createBytes(e,h)};var E={L:1,M:0,Q:3,H:2};d.QRErrorCorrectLevel=E;var F=4;function G(a,b){this.buffer=a,this.length=b}C.prototype={get:function(a){return 1==(this.buffer[Math.floor(a/8)]>>>7-a%8&1)},put:function(a,b){for(var c=0;c<b;c++)this.putBit(1==(a>>>b-c-1&1))},getLengthInBits:function(){return this.length},putBit:function(a){this.buffer.length<=this.length>>3&&this.buffer.push(0),a&&(this.buffer[this.length>>3]|=128>>>this.length%8),this.length++}};var H=[[1,26,19,2,9,16,4,13,9],[1,26,16,2,9,19,4,13,9],[1,26,13,2,9,22,4,13,9],[1,26,9,2,9,26,4,13,9]];d.getRSBlocks=function(a,b){var c=H[b];return[new B(c[0],c[2]),new B(c[3],c[5]),new B(c[6],c[8])]};d.getLengthInBits=function(){return 8},d.createBytes=function(a){for(var b=[],c=0;c<a.buffer.length;c++)b.push(a.buffer[c]);return b};var I=function(a,b){var c=document.createElement("canvas");a.appendChild(c);var d=c.getContext("2d");this.draw=function(e){var f=e.getModuleCount();c.width=c.height=b;var g=Math.floor(b/f);for(var h=0;h<f;h++)for(var i=0;i<f;i++){d.fillStyle=e.isDark(h,i)?"#000":"#fff",d.fillRect(i*g,h*g,g,g)}}};function J(a,b){this._el=a,this._htOption={width:b||128};}J.prototype.makeCode=function(a){var b=new y(1,E.M);b.addData(a);b.make();var c=new I(this._el,this._htOption.width);c.draw(b)};y.prototype={addData:function(a){this.dataList.push(new v(a)),this.dataCache=null},isDark:function(){return Math.random()<0.5},getModuleCount:function(){return 21},make:function(){},createMovieClip:function(){}};
</script>
<script>
  const $ = (s)=>document.querySelector(s);
  const datePick = $('#datePick');
  const bookDlg = $('#bookDlg');
  const btnQR = $('#btnQR');
  const date = '<?php echo h($date); ?>';
  const isAdmin = <?php echo ($isAdminView && is_admin())? 'true':'false'; ?>;
  const dateLabel = $('#dateLabel');
  dateLabel.textContent = new Date(date+'T00:00:00').toLocaleDateString('tr-TR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});

  datePick && datePick.addEventListener('change', ()=>{
    const v = datePick.value; const url = new URL(window.location.href); url.searchParams.set('date', v); window.location.href = url.toString();
  });

  document.querySelectorAll('[data-book-hour]').forEach(el=>{
    el.addEventListener('click',()=>{
      $('#inpHour').value = el.getAttribute('data-book-hour');
      $('#inpDate').value = date;
      $('#inpName').value = '';
      $('#inpWA').value = '';
      bookDlg.showModal();
    });
  });

  $('#btnBook') && $('#btnBook').addEventListener('click', async (e)=>{
    e.preventDefault();
    const hour=$('#inpHour').value, name=$('#inpName').value.trim(), wa=$('#inpWA').value.trim();
    if(!name||!wa){ alert('Zorunlu alanlar'); return; }
    const form = new FormData(); form.append('action','book'); form.append('date',date); form.append('hour',hour); form.append('name',name); form.append('whatsapp',wa);
    const res = await fetch(location.href, { method:'POST', body:form }); const out = await res.json();
    if(out.ok){ bookDlg.close(); alert('Randevunuz kaydedildi. Teşekkürler!'); location.reload(); } else alert(out.error||'Hata');
  });

  // Admin buttons
  document.addEventListener('click', async (e)=>{
    const t = e.target; const act = t.getAttribute('data-act'); if(!act) return;
    if(act==='save' || act==='clear' || act==='delete'){
      const id = t.getAttribute('data-id');
      let payload = new FormData();
      payload.append('date', date);
      if(act==='save'){
        const name = document.querySelector(`input[data-id="${id}"][data-f="name"]`).value;
        const wa = document.querySelector(`input[data-id="${id}"][data-f="whatsapp"]`).value;
        payload.append('action','admin_update'); payload.append('id',id); payload.append('name',name); payload.append('whatsapp',wa);
      }
      if(act==='clear'){
        payload.append('action','admin_update'); payload.append('id',id); payload.append('name',''); payload.append('whatsapp','');
      }
      if(act==='delete'){
        if(!confirm('Bu saati silmek istiyor musunuz?')) return;
        payload.append('action','admin_delete'); payload.append('id',id);
      }
      const res = await fetch(location.href, { method:'POST', body:payload }); const out = await res.json(); if(out.ok) location.reload(); else alert(out.error||'Hata');
    }
  });

  $('#btnAddHour') && $('#btnAddHour').addEventListener('click', async ()=>{
    const hour = $('#newHour').value.trim(); if(!hour) return; let f=new FormData(); f.append('action','admin_add'); f.append('date',date); f.append('hour',hour); const r = await fetch(location.href,{method:'POST',body:f}); const o=await r.json(); if(o.ok) location.reload(); else alert(o.error||'Hata');
  });

  // QR
  btnQR && btnQR.addEventListener('click', ()=>{
    const u = new URL('<?php echo h($base); ?>'); u.searchParams.set('date', date); u.searchParams.delete('admin');
    const dlg = document.createElement('dialog'); dlg.innerHTML = '<div class="dlg"><h3 style="margin:0 0 8px">QR</h3><div id="qr"></div><div class="muted" style="margin-top:8px">'+u.toString()+'</div><div style="margin-top:12px;display:flex;justify-content:flex-end"><button class="btn ghost" id="qrc">Kapat</button></div></div>'; document.body.appendChild(dlg); dlg.showModal();
    const holder = dlg.querySelector('#qr'); const qr = new J(holder, 220); qr.makeCode(u.toString()); dlg.querySelector('#qrc').addEventListener('click',()=>dlg.close());
  });
</script>
</body>
</html>
