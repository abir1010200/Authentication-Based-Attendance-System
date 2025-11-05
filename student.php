<?php
// =============== student.php (FULL & FINAL) ===============
// Works on any PHP server (XAMPP, 000webhost, Hostinger, etc.)
// Google Maps API Key = AIzaSyA0tqewtVtHePHybBaUzxaBZsJMcaQy2Sg
// ------------------------------------------------------------

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_port = 3306;
$db_name = getenv('DB_NAME') ?: 'attendance_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create tables (run once)
    $pdo->exec("CREATE TABLE IF NOT EXISTS temp_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject_code VARCHAR(20) NOT NULL,
        room_no VARCHAR(20) NOT NULL,
        roll_no INT NOT NULL,
        digit INT NOT NULL,
        ts DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        roll_no INT NOT NULL,
        subject_code VARCHAR(20) NOT NULL,
        attendance_date DATE NOT NULL,
        attendance_time TIME NOT NULL,
        ts DATETIME NOT NULL,
        lat DECIMAL(10,8) NULL,
        lng DECIMAL(11,8) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    die("DB Error: " . htmlspecialchars($e->getMessage()));
}

// -------------------- MARK ATTENDANCE --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_attendance') {
    header('Content-Type: application/json');
    $roll_no = (int)($_POST['roll_no'] ?? 0);
    $subject = strtoupper(trim($_POST['subject'] ?? ''));
    $room    = strtoupper(trim($_POST['room'] ?? ''));
    $digit   = (int)($_POST['digit'] ?? -1);
    $lat     = $_POST['lat'] ?? null;
    $lng     = $_POST['lng'] ?? null;

    if ($digit < 0 || $digit > 9 || $roll_no < 1 || $roll_no > 100 || !$subject || !$room) {
        echo json_encode(['success'=>false, 'message'=>'Invalid data']);
        exit;
    }

    $now     = date('Y-m-d H:i:s');
    $cutoff  = date('Y-m-d H:i:s', strtotime('-10 seconds'));

    $check = $pdo->prepare("SELECT digit FROM temp_attendance 
                            WHERE subject_code=? AND room_no=? AND roll_no=? 
                            AND ts BETWEEN ? AND ?");
    $check->execute([$subject, $room, $roll_no, $cutoff, $now]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['digit'] == $digit) {
        $ins = $pdo->prepare("INSERT INTO attendance 
            (roll_no, subject_code, attendance_date, attendance_time, ts, lat, lng)
            VALUES (?, ?, DATE(?), TIME(?), ?, ?, ?)");
        $ins->execute([$roll_no, $subject, $now, $now, $now, $lat, $lng]);
        $id = $pdo->lastInsertId();

        $pdo->prepare("DELETE FROM temp_attendance 
                       WHERE subject_code=? AND room_no=? AND roll_no=?")
            ->execute([$subject, $room, $roll_no]);

        echo json_encode([
            'success'=>true,
            'message'=>"Attendance marked! (ID: $id)",
            'location'=> $lat ? "Location saved" : "No GPS"
        ]);
    } else {
        echo json_encode(['success'=>false, 'message'=>'Wrong digit or expired']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Portal</title>

    <!-- GOOGLE MAPS (YOUR KEY ALREADY INSERTED) -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyA0tqewtVtHePHybBaUzxaBZsJMcaQy2Sg&callback=initMap" async defer></script>

    <style>
        :root{
            --bg:#0f172a;
            --card:#1e293b;
            --blue:#60a5fa;
            --green:#34d399;
            --red:#f87171;
            --gray:#94a3b8;
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{
            font-family:system-ui,sans-serif;
            background:var(--bg);
            color:#f1f5f9;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:16px;
        }
        .box{
            background:var(--card);
            padding:32px 24px;
            border-radius:24px;
            width:100%;
            max-width:420px;
            box-shadow:0 10px 30px rgba(0,0,0,.6);
            text-align:center;
        }
        h1{font-size:2rem;color:var(--blue);margin-bottom:8px;}
        .sub{font-size:.95rem;color:var(--gray);margin-bottom:24px;}
        .grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px;
            margin:24px 0;
        }
        .dig{
            background:rgba(255,255,255,.12);
            border:2px solid rgba(255,255,255,.25);
            border-radius:20px;
            font-size:3rem;
            font-weight:800;
            padding:28px;
            cursor:pointer;
            user-select:none;
            transition:.25s;
        }
        .dig:active{
            transform:scale(.94);
            background:rgba(96,165,250,.4);
            border-color:var(--blue);
        }
        .hint{font-size:.9rem;color:var(--gray);margin:12px 0;}
        .loc{
            background:rgba(52,211,153,.2);
            color:#a7f3d0;
            padding:10px;
            border-radius:12px;
            font-size:.85rem;
            margin:16px 0;
        }
        .btn{
            background:#10b981;
            color:#fff;
            border:none;
            padding:12px 20px;
            border-radius:16px;
            font-weight:600;
            cursor:pointer;
            margin-top:8px;
        }
        .link{
            display:block;
            margin-top:28px;
            color:var(--blue);
            text-decoration:none;
            font-weight:500;
        }
        .modal{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.8);
            align-items:center;
            justify-content:center;
            padding:20px;
            z-index:999;
        }
        .mcard{
            background:var(--card);
            padding:28px;
            border-radius:24px;
            width:100%;
            max-width:400px;
            position:relative;
        }
        .close{
            position:absolute;
            top:12px;right:16px;
            font-size:32px;
            color:var(--gray);
            cursor:pointer;
        }
        .close:hover{color:var(--red);}
        .fg{margin-bottom:18px;}
        label{display:block;color:#cbd5e1;margin-bottom:8px;font-weight:500;}
        input{
            width:100%;
            padding:14px 16px;
            font-size:1.1rem;
            border:2.5px solid rgba(255,255,255,.2);
            border-radius:16px;
            background:rgba(255,255,255,.1);
            color:#fff;
        }
        input:focus{
            outline:none;
            border-color:var(--blue);
            box-shadow:0 0 0 4px rgba(96,165,250,.2);
        }
        button[type=submit],#saveRollBtn{
            background:#3b82f6;
            color:#fff;
            border:none;
            padding:16px;
            font-size:1.1rem;
            font-weight:700;
            border-radius:16px;
            cursor:pointer;
            width:100%;
            margin-top:12px;
        }
        .msg{
            margin-top:16px;
            padding:12px;
            border-radius:12px;
            text-align:center;
            font-weight:600;
            display:none;
        }
        #map{
            height:300px;
            border-radius:16px;
            margin-top:16px;
            border:3px solid var(--blue);
        }
    </style>
</head>
<body>

<div class="box">
    <h1>STUDENT PORTAL</h1>
    <p class="sub">Tap the digit shown in class</p>

    <div class="grid">
        <?php for($i=0;$i<=9;$i++): ?>
            <div class="dig" data-digit="<?= $i ?>"><?= $i ?></div>
        <?php endfor; ?>
    </div>

    <div class="hint">Must submit within <b>10 seconds</b></div>
    <div id="loc" class="loc">Getting location…</div>
    <button id="mapBtn" class="btn">View Live Map</button>

    <a href="view_attendance.php" id="viewLink" class="link">View My Attendance →</a>
</div>

<!-- ONE-TIME ROLL MODAL -->
<div id="regModal" class="modal">
    <div class="mcard">
        <span class="close">×</span>
        <h2 style="color:var(--blue)">One-Time Setup</h2>
        <div class="fg">
            <label>Roll Number (1-100)</label>
            <input type="number" id="regRoll" min="1" max="100" placeholder="67">
        </div>
        <button id="saveRollBtn">Save & Start</button>
        <div id="regMsg" class="msg"></div>
    </div>
</div>

<!-- ATTENDANCE FORM MODAL -->
<div id="attModal" class="modal">
    <div class="mcard">
        <span class="close">×</span>
        <h2 style="color:var(--blue)">Class Details</h2>
        <form id="attForm">
            <input type="hidden" name="action" value="mark_attendance">
            <input type="hidden" name="digit" id="dig">
            <input type="hidden" name="roll_no" id="roll">
            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">

            <div class="fg">
                <label>Subject Code</label>
                <input type="text" name="subject" placeholder="CS101" required>
            </div>
            <div class="fg">
                <label>Room No</label>
                <input type="text" name="room" placeholder="A-205" required>
            </div>
            <button type="submit">Mark Attendance</button>
            <div id="attMsg" class="msg"></div>
        </form>
    </div>
</div>

<!-- MAP MODAL -->
<div id="mapModal" class="modal">
    <div class="mcard">
        <span class="close">×</span>
        <h2 style="color:var(--blue)">Your Live Location</h2>
        <div id="map"></div>
        <p style="margin-top:12px;color:var(--gray);font-size:.9rem">
            Accuracy: <span id="acc">—</span>m
        </p>
    </div>
</div>

<script>
let lat=null, lng=null, map, marker;
const locDiv = document.getElementById('loc');
const attForm = document.getElementById('attForm');

// ---------- GOOGLE MAP ----------
function initMap() {
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 19,
        mapTypeId: 'satellite',
        disableDefaultUI: false
    });
    marker = new google.maps.Marker({
        map,
        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: '#3b82f6', fillOpacity: 1, strokeWeight: 3, strokeColor: '#1e40af' }
    });
}

// ---------- LIVE LOCATION ----------
function updateLoc() {
    if (!navigator.geolocation) {
        locDiv.innerHTML = 'Location not supported';
        locDiv.style.background = 'rgba(248,113,113,.2)';
        return;
    }
    navigator.geolocation.watchPosition(pos => {
        lat = pos.coords.latitude.toFixed(6);
        lng = pos.coords.longitude.toFixed(6);
        const acc = Math.round(pos.coords.accuracy);
        locDiv.innerHTML = `Location ready<br><small>±${acc}m</small>`;
        locDiv.style.background = 'rgba(52,211,153,.2)';
        document.getElementById('lat').value = lat;
        document.getElementById('lng').value = lng;

        // update map if open
        if (map) {
            const p = new google.maps.LatLng(lat, lng);
            map.panTo(p);
            marker.setPosition(p);
            document.getElementById('acc').textContent = acc;
        }
    }, () => {
        locDiv.textContent = 'Location denied (still works)';
        locDiv.style.background = 'rgba(251,146,60,.2)';
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 10000 });
}
updateLoc();
setInterval(updateLoc, 15000);

// ---------- MODALS ----------
const regModal = document.getElementById('regModal');
const attModal = document.getElementById('attModal');
const mapModal = document.getElementById('mapModal');

document.querySelectorAll('.close').forEach(c=>c.onclick=()=>c.closest('.modal').style.display='none');
window.onclick = e => { if(e.target.classList.contains('modal')) e.target.style.display='none'; };

// ---------- ROLL SAVE ----------
let roll = localStorage.getItem('student_roll');
if (!roll) regModal.style.display='flex';
else document.getElementById('roll').value = roll;

document.getElementById('saveRollBtn').onclick = () => {
    const r = document.getElementById('regRoll').value.trim();
    const msg = document.getElementById('regMsg');
    if (!r || r<1 || r>100) {
        msg.style.display='block';
        msg.style.color='#f87171';
        msg.textContent='Roll 1-100 only';
        return;
    }
    localStorage.setItem('student_roll', r);
    document.getElementById('roll').value = r;
    regModal.style.display='none';
    alert(`Welcome, Roll ${r}!`);
};

// ---------- DIGIT CLICK ----------
document.querySelectorAll('.dig').forEach(d => {
    d.onclick = () => {
        document.getElementById('dig').value = d.dataset.digit;
        document.getElementById('lat').value = lat;
        document.getElementById('lng').value = lng;
        attModal.style.display='flex';
        setTimeout(() => attForm.querySelector('[name=subject]').focus(), 300);
    };
});

// ---------- SUBMIT ATTENDANCE ----------
attForm.onsubmit = async e => {
    e.preventDefault();
    const msg = document.getElementById('attMsg');
    msg.style.display='none';

    const fd = new FormData(attForm);
    fd.append('lat', lat);
    fd.append('lng', lng);

    try {
        const r = await fetch('', {method:'POST', body:fd});
        const j = await r.json();
        if (j.success) {
            msg.style.color = '#34d399';
            msg.innerHTML = `${j.message}<br><small>${j.location}</small>`;
            msg.style.display='block';
            setTimeout(() => { attModal.style.display='none'; msg.style.display='none'; }, 3000);
        } else {
            msg.style.color = '#f87171';
            msg.textContent = j.message;
            msg.style.display='block';
        }
    } catch {
        msg.style.color = '#f87171';
        msg.textContent = 'Network error';
        msg.style.display='block';
    }
};

// ---------- MAP BUTTON ----------
document.getElementById('mapBtn').onclick = () => {
    if (!lat) { alert('Wait for location…'); return; }
    mapModal.style.display='flex';
    setTimeout(() => google.maps.event.trigger(map, 'resize'), 100);
};

// ---------- VIEW ATTENDANCE LINK ----------
document.getElementById('viewLink').onclick = e => {
    const r = localStorage.getItem('student_roll');
    if (r) {
        e.preventDefault();
        location.href = `view_attendance.php?roll=${r}`;
    }
};
</script>
</body>
</html>
