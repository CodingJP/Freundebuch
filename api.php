<?php
/* ============================================================
   Freundebuch - Server-Backend
   Jede Feier ist ein eigenes Event mit eigenem Gaeste-Code:
     data/events.json                     Liste aller Events
     data/events/<event>/pages/<id>.json  eine Datei pro Gaeste-Seite
     data/events/<event>/media/<datei>    Fotos, Audio, Video
   Der Ordner data/ ist komplett gesperrt; Medien liefert api.php
   nur nach Login aus.
   ============================================================ */

session_start();
require __DIR__ . '/config.php';

define('FB_DATA',   __DIR__ . '/data');
define('FB_EVENTS', FB_DATA . '/events');
define('FB_MAX_UPLOAD', 80 * 1024 * 1024);
define('FB_MAX_PROOF', 8); // Fotos/Videos/Sprachnachrichten pro Mini-Mission
$FB_OCCASIONS = array('birthday', 'wedding', 'farewell', 'party');

@mkdir(FB_EVENTS, 0775, true);

// Schutzdatei fuer Apache anlegen, falls sie beim Hochladen vergessen wurde
if (!is_file(FB_DATA . '/.htaccess')) {
  @file_put_contents(FB_DATA . '/.htaccess',
    "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
    "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$action = isset($_GET['action']) ? $_GET['action'] : '';

function out($x){ echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
function body(){ $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : array(); }
function authed(){ return !empty($_SESSION['fb_auth']); }
function isAdmin(){ return !empty($_SESSION['fb_admin']); }

function clean_str($s, $max){
  if (!is_string($s)) $s = '';
  $s = trim($s);
  if (function_exists('mb_strlen')) { if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max); }
  else { if (strlen($s) > $max) $s = substr($s, 0, $max); }
  return $s;
}
function read_json($f){ if (!is_file($f)) return null; $j = json_decode(file_get_contents($f), true); return is_array($j) ? $j : null; }
function write_json($f, $data){ return file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false; }
function rrmdir($dir){
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $e) {
    if ($e === '.' || $e === '..') continue;
    $p = $dir . '/' . $e;
    if (is_dir($p) && !is_link($p)) rrmdir($p); else @unlink($p);
  }
  @rmdir($dir);
}

/* ---------- Events ---------- */
function events_load(){ $e = read_json(FB_DATA . '/events.json'); return $e ? $e : array(); }
function events_save($events){ write_json(FB_DATA . '/events.json', $events); }
function valid_event_id($id){ return is_string($id) && preg_match('/^[a-f0-9]{8,32}$/', $id); }
function pages_dir($ev){ return FB_EVENTS . '/' . $ev . '/pages'; }
function media_dir($ev){ return FB_EVENTS . '/' . $ev . '/media'; }
function event_public($e){
  // questions/tasks: eigene Listen des Admins, null = Standard des Anlasses
  return array('id'=>$e['id'], 'title'=>$e['title'], 'occasion'=>$e['occasion'], 'status'=>$e['status'],
    'questions'=>isset($e['questions']) ? $e['questions'] : null, 'tasks'=>isset($e['tasks']) ? $e['tasks'] : null);
}
// Liste von Texten bereinigen (leer, doppelt, zu lang); null bleibt null
function clean_list($l, $maxLen, $maxCount){
  if (!is_array($l)) return null;
  $out = array();
  foreach ($l as $t) {
    $t = clean_str($t, $maxLen);
    if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
    if (count($out) >= $maxCount) break;
  }
  return $out ? $out : null;
}
function load_pages($ev){
  $pages = array();
  foreach (glob(pages_dir($ev) . '/*.json') as $f) { $p = read_json($f); if ($p) $pages[] = $p; }
  usort($pages, function($a, $b){ $x = isset($a['created'])?$a['created']:0; $y = isset($b['created'])?$b['created']:0; return $x == $y ? 0 : ($x < $y ? -1 : 1); });
  return $pages;
}

// Uebernimmt beim ersten Start die Seiten der alten Version (ein einziges Fest) als erstes Event
function migrate_legacy(){
  if (is_file(FB_DATA . '/events.json')) return;
  $lock = fopen(FB_DATA . '/.migrate.lock', 'c');
  if ($lock) flock($lock, LOCK_EX);
  if (!is_file(FB_DATA . '/events.json')) {
    $events = array();
    $oldPages = glob(FB_DATA . '/pages/*.json');
    $oldCode = defined('FB_SECRET_CODE') ? FB_SECRET_CODE : '';
    if ($oldPages || $oldCode !== '') {
      $meta = read_json(FB_DATA . '/meta.json');
      $id = bin2hex(random_bytes(6));
      @mkdir(pages_dir($id), 0775, true);
      @mkdir(media_dir($id), 0775, true);
      foreach ($oldPages as $f) {
        $p = read_json($f);
        if ($p) foreach (array('photo','audio','video') as $k) {
          if (!empty($p[$k]) && is_string($p[$k]) && basename($p[$k]) === $p[$k] && is_file(__DIR__ . '/uploads/' . $p[$k])) {
            @rename(__DIR__ . '/uploads/' . $p[$k], media_dir($id) . '/' . $p[$k]);
          }
        }
        @rename($f, pages_dir($id) . '/' . basename($f));
      }
      $events[$id] = array(
        'id'=>$id,
        'title'=>($meta && !empty($meta['title'])) ? $meta['title'] : (defined('FB_TITLE') ? FB_TITLE : 'Unser Freundebuch'),
        'occasion'=>'birthday',
        'code'=>$oldCode !== '' ? $oldCode : bin2hex(random_bytes(4)),
        'status'=>'open',
        'created'=>time()
      );
    }
    events_save($events);
  }
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}
migrate_legacy();

// Event, auf das sich die Anfrage bezieht. Gaeste sind fest an ihr Event gebunden,
// der Admin waehlt es pro Anfrage ueber ?event=..., damit mehrere Tabs nicht durcheinander kommen.
function req_event(){
  $events = events_load();
  if (isAdmin()) $id = isset($_GET['event']) ? $_GET['event'] : '';
  else $id = isset($_SESSION['fb_event']) ? $_SESSION['fb_event'] : '';
  if (!valid_event_id($id) || !isset($events[$id])) out(array('error'=>'no_event'));
  @mkdir(pages_dir($id), 0775, true);
  @mkdir(media_dir($id), 0775, true);
  return $events[$id];
}
function can_write($ev){ return isAdmin() || $ev['status'] === 'open'; }

/* ---------- Medien ---------- */
$FB_MIME = array('jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','webm'=>'video/webm','mp4'=>'video/mp4',
  'm4a'=>'audio/mp4','mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav');
function valid_media($ev, $n){
  if (is_string($n) && preg_match('/^[a-f0-9]{16,40}\.(jpg|png|webp|webm|mp4|m4a|mp3|ogg|wav)$/', $n) && is_file(media_dir($ev) . '/' . $n)) return $n;
  return null;
}
// Alle Mediendateien einer Seite: Foto, Sprachnachricht, Video und die Beweise der Mini-Mission
function page_media($p){
  $names = array();
  foreach (array('photo','audio','video') as $k) if (!empty($p[$k]) && is_string($p[$k])) $names[] = $p[$k];
  if (isset($p['task']['media']) && is_array($p['task']['media'])) foreach ($p['task']['media'] as $m) if (is_string($m)) $names[] = $m;
  return $names;
}
// Alle Mediendateien, die von einer anderen Seite als $exceptId benutzt werden
function media_used_elsewhere($ev, $exceptId){
  $used = array();
  foreach (load_pages($ev) as $p) {
    if (isset($p['id']) && $p['id'] === $exceptId) continue;
    foreach (page_media($p) as $n) $used[$n] = true;
  }
  return $used;
}
function ext_from($name, $type){
  $map = array(
    'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
    'audio/webm'=>'webm','video/webm'=>'webm','audio/ogg'=>'ogg','video/ogg'=>'ogg',
    'audio/mpeg'=>'mp3','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a','audio/aac'=>'m4a',
    'audio/wav'=>'wav','audio/x-wav'=>'wav','video/mp4'=>'mp4','video/quicktime'=>'mp4'
  );
  $type = strtolower(trim(explode(';', (string)$type)[0])); // z. B. "audio/webm;codecs=opus"
  if (isset($map[$type])) return $map[$type];
  $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $alias = array('jpeg'=>'jpg', 'mov'=>'mp4');
  if (isset($alias[$e])) return $alias[$e];
  if (in_array($e, array('jpg','png','webp','webm','mp4','m4a','mp3','ogg','wav'))) return $e;
  return null;
}
// Hochgeladene, aber nie gespeicherte Dateien nach einem Tag wegraeumen
function cleanup_orphans($ev){
  $used = media_used_elsewhere($ev, null);
  foreach (glob(media_dir($ev) . '/*') as $f) {
    if (!isset($used[basename($f)]) && filemtime($f) < time() - 86400) @unlink($f);
  }
}
// Datei ausliefern, mit Range-Unterstuetzung (Safari/iOS spielt Videos sonst nicht ab)
function send_file($path, $mime){
  $size = filesize($path); $start = 0; $end = $size - 1;
  header('Content-Type: ' . $mime);
  header('Accept-Ranges: bytes');
  header('Cache-Control: private, max-age=86400');
  if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
    if ($m[1] === '' && $m[2] === '' || $start > $end) {
      http_response_code(416); header('Content-Range: bytes */' . $size); exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
  }
  header('Content-Length: ' . ($end - $start + 1));
  $fp = fopen($path, 'rb'); fseek($fp, $start); $left = $end - $start + 1;
  while ($left > 0 && !feof($fp)) { $chunk = fread($fp, min(65536, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
  fclose($fp); exit;
}

/* ---------- Buchansicht (fuer Bildschirm, Druck und Export) ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function render_book($ev, $pages, $mediaPrefix, $fontPrefix){
  $wish = array('birthday'=>'Das wünsche ich dir zum Geburtstag', 'wedding'=>'Das wünsche ich euch',
    'farewell'=>'Das wünsche ich dir für deinen Weg', 'party'=>'Das möchte ich dir noch sagen');
  $wishTitle = isset($wish[$ev['occasion']]) ? $wish[$ev['occasion']] : $wish['party'];
  ob_start(); ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($ev['title']) ?></title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($fontPrefix) ?>fonts.css">
<style>
  :root{--paper:#fffdf3;--line:#cfe3f0;--margin:#f4a9bb;--ink:#3a3a52;--accent:#e8556d;--accent2:#2bb3a3;--pen:#2a5db0;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:"Patrick Hand","Comic Sans MS",cursive;color:var(--ink);background:#eef2f7;padding:18px 14px 60px;}
  .bar{max-width:820px;margin:0 auto 16px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
  .bar button{font-family:inherit;font-size:16px;cursor:pointer;border:none;border-radius:14px;padding:10px 16px;color:#fff;background:var(--accent);font-weight:bold;}
  .cover,.sheet{max-width:820px;margin:0 auto 22px;background:var(--paper);border-radius:14px;box-shadow:0 10px 28px rgba(58,58,82,.2);}
  .cover{text-align:center;padding:40px 20px;}
  .cover h1{font-family:"Gochi Hand",cursive;font-size:44px;margin:0 0 8px;}
  .cover p{margin:0;opacity:.75;font-size:18px;}
  .sheet{background:linear-gradient(var(--line) 1px,transparent 1px);background-size:100% 36px;background-color:var(--paper);
    padding:28px 28px 22px 66px;position:relative;overflow:hidden;}
  .sheet::before{content:"";position:absolute;left:46px;top:0;bottom:0;width:2px;background:var(--margin);opacity:.6;}
  .top{display:flex;gap:22px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px;}
  .top img{width:150px;height:172px;object-fit:cover;border:3px solid #fff;border-radius:8px;box-shadow:0 6px 16px rgba(58,58,82,.2);transform:rotate(-3deg);}
  .name{font-family:"Gochi Hand",cursive;font-size:32px;}
  .date{font-family:"Caveat",cursive;font-size:19px;color:var(--accent);}
  .qa{margin:6px 0 12px;break-inside:avoid;}
  .q{font-weight:bold;font-size:16px;}
  .a{font-family:"Caveat",cursive;font-size:22px;color:var(--pen);white-space:pre-wrap;}
  .box{margin-top:14px;border-radius:14px;padding:10px 14px;break-inside:avoid;}
  .mission{background:#eefcf8;border:2px dashed var(--accent2);}
  .wish{background:#fff7fa;border:2px solid var(--margin);}
  .box h3{margin:0 0 4px;font-size:17px;}
  .wish .a{color:var(--accent);}
  .proof{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:10px;}
  .proof img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:10px;border:3px solid #fff;box-shadow:0 4px 12px rgba(58,58,82,.18);}
  .proof video,.proof audio{margin-top:0;}
  audio,video{width:100%;margin-top:8px;border-radius:10px;}
  video{max-height:320px;background:#000;}
  .printnote{display:none;font-size:14px;opacity:.75;margin-top:6px;}
  @media print{
    body{background:#fff;padding:0;}
    .bar,audio,video{display:none!important;}
    .printnote{display:block;}
    .cover,.sheet{box-shadow:none;margin:0;border-radius:0;break-after:page;max-width:none;}
    .cover{padding-top:35vh;}
    *{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  }
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">🖨️ Drucken / als PDF speichern</button></div>
<div class="cover">
  <h1><?= h($ev['title']) ?></h1>
  <p><?= count($pages) ?> <?= count($pages) === 1 ? 'Seite' : 'Seiten' ?> · erstellt am <?= h(date('d.m.Y')) ?></p>
</div>
<?php foreach ($pages as $p):
  $items = array_filter(isset($p['items']) ? $p['items'] : array(), function($it){ return isset($it['a']) && trim($it['a']) !== ''; }); ?>
<div class="sheet">
  <div class="top">
    <?php if (!empty($p['photo'])): ?><img src="<?= h($mediaPrefix . $p['photo']) ?>" alt=""><?php endif; ?>
    <div>
      <div class="name"><?= h(!empty($p['name']) ? $p['name'] : 'Ohne Namen') ?></div>
      <?php if (!empty($p['date'])): ?><div class="date">✎ <?= h($p['date']) ?></div><?php endif; ?>
    </div>
  </div>
  <?php foreach ($items as $it): ?>
  <div class="qa"><div class="q"><?= h($it['q']) ?></div><div class="a"><?= h($it['a']) ?></div></div>
  <?php endforeach; ?>
  <?php $proof = isset($p['task']['media']) && is_array($p['task']['media']) ? $p['task']['media'] : array();
  if (!empty($p['task']['text']) && (!empty($p['task']['done']) || $proof)): ?>
  <div class="box mission"><h3>🎯 Mini-Mission<?= !empty($p['task']['done']) ? ' erledigt ✓' : '' ?></h3><div class="a"><?= h($p['task']['text']) ?></div>
    <?php if ($proof): ?><div class="proof">
    <?php foreach ($proof as $m): $e = pathinfo($m, PATHINFO_EXTENSION); ?>
      <?php if (in_array($e, array('jpg','png','webp'))): ?><img src="<?= h($mediaPrefix . $m) ?>" alt="">
      <?php elseif (in_array($e, array('mp4','webm'))): ?><video controls playsinline preload="metadata" src="<?= h($mediaPrefix . $m) ?>"></video><span class="printnote">🎥 Video – im digitalen Freundebuch ansehen</span>
      <?php else: ?><audio controls preload="none" src="<?= h($mediaPrefix . $m) ?>"></audio><span class="printnote">🎤 Sprachnachricht – im digitalen Freundebuch anhören</span>
      <?php endif; ?>
    <?php endforeach; ?>
    </div><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($p['wish'])): ?>
  <div class="box wish"><h3>🎉 <?= h($wishTitle) ?>:</h3><div class="a"><?= h($p['wish']) ?></div></div>
  <?php endif; ?>
  <?php if (!empty($p['audio'])): ?>
  <audio controls preload="none" src="<?= h($mediaPrefix . $p['audio']) ?>"></audio>
  <div class="printnote">🎤 Sprachnachricht – im digitalen Freundebuch anhören</div>
  <?php endif; ?>
  <?php if (!empty($p['video'])): ?>
  <video controls playsinline preload="metadata" src="<?= h($mediaPrefix . $p['video']) ?>"></video>
  <div class="printnote">🎥 Video – im digitalen Freundebuch ansehen</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</body>
</html>
<?php
  return ob_get_clean();
}
function public_pages($ev){
  $pages = load_pages($ev['id']);
  foreach ($pages as &$p) unset($p['token']); // Geheimnis nie ausliefern
  return $pages;
}
function slug($s){
  $s = strtr($s, array('ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss'));
  $s = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
  return $s !== '' ? $s : 'freundebuch';
}

/* ---------- oeffentliche Aktionen ---------- */
function status_info(){
  $r = array('auth'=>authed(), 'admin'=>isAdmin(), 'event'=>null);
  if (authed() && !isAdmin()) {
    $events = events_load(); $id = isset($_SESSION['fb_event']) ? $_SESSION['fb_event'] : '';
    if (isset($events[$id])) $r['event'] = event_public($events[$id]);
    else { $r['auth'] = false; $_SESSION = array(); } // Event wurde geloescht
  }
  return $r;
}
if ($action === 'status') out(status_info());

if ($action === 'login') {
  $b = body();
  $code = trim((string)(isset($b['code']) ? $b['code'] : ''));
  $admin = false; $event = null;
  if ($code !== '' && FB_ADMIN_CODE !== '' && hash_equals(FB_ADMIN_CODE, $code)) $admin = true;
  elseif ($code !== '') {
    foreach (events_load() as $e) { if ($e['status'] !== 'archived' && hash_equals((string)$e['code'], $code)) { $event = $e; break; } }
  }
  if ($admin || $event) {
    session_regenerate_id(true);
    $_SESSION['fb_auth'] = true;
    $_SESSION['fb_admin'] = $admin;
    $_SESSION['fb_event'] = $event ? $event['id'] : null;
    out(array_merge(array('ok'=>true), status_info()));
  }
  usleep(800000); // gegen schnelles Durchprobieren
  out(array('ok'=>false));
}
if ($action === 'logout') { $_SESSION = array(); session_destroy(); out(array('ok'=>true)); }

/* ---------- ab hier ist Login Pflicht ---------- */
if (!authed()) out(array('error'=>'auth'));

/* ---------- Event-Verwaltung (nur Admin) ---------- */
if (in_array($action, array('events', 'event_save', 'event_content', 'event_delete', 'export'), true) && !isAdmin()) out(array('error'=>'forbidden'));

if ($action === 'events') {
  $list = array();
  foreach (events_load() as $e) {
    $e['pages'] = count(glob(pages_dir($e['id']) . '/*.json'));
    $list[] = $e;
  }
  usort($list, function($a, $b){ return $b['created'] - $a['created']; });
  out(array('events'=>$list));
}

if ($action === 'event_save') {
  $b = body();
  $events = events_load();
  $id = isset($b['id']) ? $b['id'] : '';
  $isNew = !(valid_event_id($id) && isset($events[$id]));
  $title = clean_str(isset($b['title']) ? $b['title'] : '', 80);
  $code = clean_str(isset($b['code']) ? $b['code'] : '', 60);
  $occasion = (isset($b['occasion']) && in_array($b['occasion'], $FB_OCCASIONS, true)) ? $b['occasion'] : 'birthday';
  $status = (isset($b['status']) && in_array($b['status'], array('open','closed','archived'), true)) ? $b['status'] : 'open';
  if ($title === '') out(array('error'=>'title'));
  if (strlen($code) < 4) out(array('error'=>'code_short'));
  if (FB_ADMIN_CODE !== '' && hash_equals(FB_ADMIN_CODE, $code)) out(array('error'=>'code_taken'));
  foreach ($events as $e) if ($e['id'] !== $id && hash_equals((string)$e['code'], $code)) out(array('error'=>'code_taken'));
  if ($isNew) { $id = bin2hex(random_bytes(6)); $events[$id] = array('id'=>$id, 'created'=>time()); }
  $events[$id] = array_merge($events[$id], array('title'=>$title, 'code'=>$code, 'occasion'=>$occasion, 'status'=>$status));
  @mkdir(pages_dir($id), 0775, true);
  @mkdir(media_dir($id), 0775, true);
  events_save($events);
  out(array('ok'=>true, 'event'=>$events[$id]));
}

// Eigene Fragen und Missionen einer Feier (null = Standard des Anlasses)
if ($action === 'event_content') {
  $b = body();
  $events = events_load();
  $id = isset($b['id']) ? $b['id'] : '';
  if (!valid_event_id($id) || !isset($events[$id])) out(array('error'=>'bad'));
  $events[$id]['questions'] = clean_list(isset($b['questions']) ? $b['questions'] : null, 120, 200);
  $events[$id]['tasks'] = clean_list(isset($b['tasks']) ? $b['tasks'] : null, 220, 200);
  events_save($events);
  out(array('ok'=>true, 'event'=>event_public($events[$id])));
}

if ($action === 'event_delete') {
  $b = body();
  $events = events_load();
  $id = isset($b['id']) ? $b['id'] : '';
  if (!valid_event_id($id) || !isset($events[$id])) out(array('error'=>'bad'));
  unset($events[$id]);
  events_save($events);
  rrmdir(FB_EVENTS . '/' . $id);
  out(array('ok'=>true));
}

/* ---------- Aktionen innerhalb eines Events ---------- */
$ev = req_event();
$EV = $ev['id'];

if ($action === 'list') {
  out(array('pages'=>public_pages($ev), 'admin'=>isAdmin(), 'event'=>event_public($ev)));
}

if ($action === 'save') {
  if (!can_write($ev)) out(array('error'=>'closed'));
  $b = body();
  $page = isset($b['page']) ? $b['page'] : array();
  $token = (string)(isset($b['token']) ? $b['token'] : '');

  $id = (isset($page['id']) && is_string($page['id']) && preg_match('/^[a-f0-9]{8,32}$/', $page['id'])) ? $page['id'] : null;
  $file = $id ? (pages_dir($EV) . '/' . $id . '.json') : null;
  $existing = $file ? read_json($file) : null;

  if ($existing) {
    if (!isAdmin() && !hash_equals((string)$existing['token'], $token)) out(array('error'=>'forbidden'));
  } else {
    $id = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(16));
    $file = pages_dir($EV) . '/' . $id . '.json';
  }

  $items = array();
  if (isset($page['items']) && is_array($page['items'])) {
    foreach ($page['items'] as $it) {
      if (!is_array($it)) continue;
      $items[] = array('q'=>clean_str(isset($it['q'])?$it['q']:'', 120), 'a'=>clean_str(isset($it['a'])?$it['a']:'', 600));
      if (count($items) >= 40) break;
    }
  }
  $usedElsewhere = media_used_elsewhere($EV, $id);
  $media = array();
  foreach (array('photo','audio','video') as $k) {
    $m = valid_media($EV, isset($page[$k])?$page[$k]:null);
    if ($m && isset($usedElsewhere[$m])) $m = null; // Datei einer fremden Seite nicht uebernehmen
    $media[$k] = $m;
  }

  $task = isset($page['task']) && is_array($page['task']) ? $page['task'] : array();
  $proof = array();
  if (isset($task['media']) && is_array($task['media'])) {
    foreach ($task['media'] as $m) {
      $m = valid_media($EV, $m);
      if ($m && !isset($usedElsewhere[$m]) && !in_array($m, $proof, true)) $proof[] = $m;
      if (count($proof) >= FB_MAX_PROOF) break;
    }
  }
  $task = array('text'=>clean_str(isset($task['text'])?$task['text']:'', 220), 'done'=>!empty($task['done']), 'media'=>$proof);

  $clean = array(
    'id'=>$id,
    'token'=>$existing ? $existing['token'] : $token,
    'name'=>clean_str(isset($page['name'])?$page['name']:'', 60),
    'date'=>clean_str(isset($page['date'])?$page['date']:'', 40),
    'wish'=>clean_str(isset($page['wish'])?$page['wish']:'', 1500),
    'photo'=>$media['photo'],
    'audio'=>$media['audio'],
    'video'=>$media['video'],
    'task'=>$task,
    'items'=>$items,
    'created'=>$existing && isset($existing['created']) ? $existing['created'] : time()
  );

  write_json($file, $clean);

  // ersetzte oder entfernte Medien loeschen, damit keine Dateileichen bleiben
  if ($existing) {
    $keep = array_flip(page_media($clean));
    foreach (page_media($existing) as $old) {
      if (!isset($keep[$old]) && !isset($usedElsewhere[$old]) && valid_media($EV, $old)) @unlink(media_dir($EV) . '/' . $old);
    }
  }
  out(array('ok'=>true, 'id'=>$id, 'token'=>$existing ? null : $token));
}

if ($action === 'delete') {
  if (!can_write($ev)) out(array('error'=>'closed'));
  $b = body();
  $id = (isset($b['id']) && is_string($b['id']) && preg_match('/^[a-f0-9]{8,32}$/', $b['id'])) ? $b['id'] : null;
  if (!$id) out(array('error'=>'bad'));
  $file = pages_dir($EV) . '/' . $id . '.json';
  $p = read_json($file);
  if (!$p) out(array('ok'=>true));
  if (!isAdmin() && !hash_equals((string)(isset($p['token'])?$p['token']:''), (string)(isset($b['token'])?$b['token']:''))) out(array('error'=>'forbidden'));
  @unlink($file);
  $usedElsewhere = media_used_elsewhere($EV, $id);
  foreach (page_media($p) as $n) {
    if (valid_media($EV, $n) && !isset($usedElsewhere[$n])) @unlink(media_dir($EV) . '/' . $n);
  }
  out(array('ok'=>true));
}

if ($action === 'upload') {
  if (!can_write($ev)) out(array('error'=>'closed'));
  if (empty($_FILES['file'])) out(array('error'=>'nofile'));
  $f = $_FILES['file'];
  if ($f['error'] !== UPLOAD_ERR_OK) out(array('error'=>'upload_err', 'code'=>$f['error']));
  if ($f['size'] > FB_MAX_UPLOAD) out(array('error'=>'too_big'));
  $ext = ext_from($f['name'], $f['type']);
  if (!$ext) out(array('error'=>'type'));
  cleanup_orphans($EV);
  $name = bin2hex(random_bytes(10)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], media_dir($EV) . '/' . $name)) out(array('error'=>'move'));
  out(array('ok'=>true, 'name'=>$name));
}

if ($action === 'media') {
  session_write_close(); // parallele Anfragen (mehrere Videos) nicht blockieren
  $n = valid_media($EV, isset($_GET['f']) ? $_GET['f'] : null);
  if (!$n) { http_response_code(404); out(array('error'=>'not_found')); }
  header_remove('Cache-Control');
  send_file(media_dir($EV) . '/' . $n, $FB_MIME[pathinfo($n, PATHINFO_EXTENSION)]);
}

if ($action === 'book') {
  session_write_close();
  header('Content-Type: text/html; charset=utf-8');
  echo render_book($ev, public_pages($ev), 'api.php?action=media&event=' . $EV . '&f=', 'fonts/');
  exit;
}

if ($action === 'export') {
  session_write_close();
  if (!class_exists('ZipArchive')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:sans-serif">Der Server kann keine ZIP-Dateien erstellen (PHP-Erweiterung <code>zip</code> fehlt). ' .
         'Bitte beim Hoster aktivieren lassen – oder <a href="api.php?action=book&amp;event=' . h($EV) . '">das Buch öffnen</a> und als PDF drucken.</p>';
    exit;
  }
  @set_time_limit(0);
  $pages = public_pages($ev);
  $tmp = tempnam(sys_get_temp_dir(), 'fb');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) out(array('error'=>'zip_open'));
  $zip->addFromString('freundebuch.html', render_book($ev, $pages, 'media/', 'fonts/'));
  $zip->addFromString('seiten.json', json_encode(array('event'=>event_public($ev), 'pages'=>$pages), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  foreach (glob(__DIR__ . '/fonts/*') as $f) $zip->addFile($f, 'fonts/' . basename($f));
  $zip->addFile(__DIR__ . '/favicon.svg', 'favicon.svg');
  foreach ($pages as $p) foreach (page_media($p) as $n) {
    if (valid_media($EV, $n)) $zip->addFile(media_dir($EV) . '/' . $n, 'media/' . $n);
  }
  $zip->close();
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . slug($ev['title']) . '-' . date('Y-m-d') . '.zip"');
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  exit;
}

if ($action === 'settitle') {
  if (!isAdmin()) out(array('error'=>'forbidden'));
  $b = body();
  $t = clean_str(isset($b['title']) ? $b['title'] : '', 80);
  if ($t === '') out(array('error'=>'title'));
  $events = events_load();
  $events[$EV]['title'] = $t;
  events_save($events);
  out(array('ok'=>true, 'title'=>$t));
}

out(array('error'=>'unknown'));
