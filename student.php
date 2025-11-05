<?php
// student.php - Student Portal with Geolocation
$db_host = getenv('DB_HOST') ?: die('DB_HOST missing');
$db_port = (int)(getenv('DB_PORT') ?: 3306);
$db_name = getenv('DB_NAME') ?: die('DB_NAME missing');
$db_user = getenv('DB_USER') ?: die('DB_USER missing');
$db_pass = getenv('DB_PASS') ?: die('DB_PASS missing');

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create tables
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
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// Mark attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_attendance') {
    header('Content-Type: application/json');

    $roll_no = (int)($_POST['roll_no'] ?? 0);
    $subject = strtoupper(trim($_POST['subject'] ?? ''));
    $room = strtoupper(trim($_POST['room'] ?? ''));
    $digit = (int)($_POST['digit'] ?? -1);
    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;

    if ($digit < 0 || $digit > 9 || $roll_no < 1 || $roll_no > 100 || empty($subject) || empty($room)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d H:i:s', strtotime('-10 seconds'));

    try {
        $check = $pdo->prepare("
            SELECT digit, ts FROM temp_attendance
            WHERE subject_code = ? AND room_no = ? AND roll_no = ?
            AND ts BETWEEN ? AND ?
        ");
        $check->execute([$subject, $room, $roll_no, $cutoff, $now]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['digit'] == $digit) {
            $insert = $pdo->prepare("
                INSERT INTO attendance (roll_no, subject_code, attendance_date, attendance_time, ts, lat, lng)
                VALUES (?, ?, DATE(?), TIME(?), ?, ?, ?)
            ");
            $insert->execute([$roll_no, $subject, $now, $now, $now, $lat, $lng]);
            $last_id = $pdo->lastInsertId();

            $delete = $pdo->prepare("DELETE FROM temp_attendance WHERE subject_code = ? AND room_no = ? AND roll_no = ?");
            $delete->execute([$subject, $room, $roll_no]);

            echo json_encode([
                'success' => true,
                'message' => "Attendance marked! (ID: $last_id)",
                'location' => $lat && $lng ? "Location recorded" : "No location"
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Digit mismatch or expired. Try again.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 16px;
        }
        .container {
            background: #1e293b;
            padding: 32px;
            border-radius: 24px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        h1 {
            font-size: 1.9rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 12px;
            color: #60a5fa;
        }
        .subtitle {
            text-align: center;
            color: #94a3b8;
            font-size: 0.95rem;
            margin-bottom: 24px;
        }
        .digit-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(5, 1fr);
            gap: 14px;
            margin: 24px 0;
        }
        .digit-btn {
            background: rgba(255,255,255,0.12);
            border: 2px solid rgba(255,255,255,0.25);
            border-radius: 18px;
            font-size: 2.4rem;
            font-weight: 800;
            color: #e2e8f0;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            user-select: none;
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .digit-btn:hover {
            background: rgba(96, 165, 250, 0.3);
            border-color: #60a5fa;
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(96, 165, 250, 0.3);
        }
        .hint {
            font-size: 0.85rem;
            color: #94a3b8;
            text-align: center;
            margin-top: 8px;
        }
        .location-status {
            text-align: center;
            font-size: 0.8rem;
            color: #34d399;
            margin-top: 12px;
        }
        .modal, #regModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-content, .reg-content {
            background: #1e293b;
            padding: 28px;
            border-radius: 24px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.5);
        }
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            color: #94a3b8;
            cursor: pointer;
        }
        .close:hover { color: #f87171; }
        .form-group { margin-bottom: 18px; }
        label {
            display: block;
            color: #cbd5e1;
            font-size: 0.95rem;
            margin-bottom: 8px;
            font-weight: 500;
        }
        input[type="text"], input[type="number"] {
            width: 100%;
            padding: 14px 16px;
            font-size: 1.1rem;
            border: 2.5px solid rgba(255,255,255,0.2);
            border-radius: 16px;
            background: rgba(255,255,255,0.1);
            color: white;
            transition: 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.2);
        }
        button[type="submit"], #saveRollBtn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 16px;
            font-size: 1.1rem;
            font-weight: 700;
            border-radius: 16px;
            cursor: pointer;
            width: 100%;
            margin-top: 12px;
            transition: 0.3s;
        }
        button[type="submit"]:hover, #saveRollBtn:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
        .message {
            margin-top: 16px;
            padding: 12px;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .view-link {
            display: block;
            text-align: center;
            margin-top: 24px;
            color: #60a5fa;
            font-weight: 500;
            text-decoration: none;
        }
        .view-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <h1>STUDENT PORTAL</h1>
    <p class="subtitle">Tap the digit shown in class</p>
    
    <div class="digit-grid">
        <?php for ($i = 0; $i <= 9; $i++): ?>
            <div class="digit-btn" data-digit="<?php echo $i; ?>"><?php echo $i; ?></div>
        <?php endfor; ?>
    </div>

    <div class="hint">Must submit within <strong>10 seconds</strong></div>
    <div id="locationStatus" class="location-status">Getting location...</div>
    
    <a href="view_attendance.php" id="viewLink" class="view-link">View My Attendance →</a>
</div>

<!-- Registration Modal -->
<div id="regModal" class="modal">
    <div class="reg-content">
        <h2 style="color:#60a5fa; text-align:center;">One-Time Setup</h2>
        <div class="form-group">
            <label>Roll Number (1-100)</label>
            <input type="number" id="regRoll" min="1" max="100" placeholder="e.g., 67" required>
        </div>
        <button id="saveRollBtn">Save & Start</button>
        <div id="regMessage" class="message" style="display:none;"></div>
    </div>
</div>

<!-- Attendance Modal -->
<div id="attendanceModal" class="modal">
    <div class="modal-content">
        <span class="close">×</span>
        <h2 style="color:#60a5fa; text-align:center;">Class Details</h2>
        <form id="attendanceForm">
            <input type="hidden" name="digit" id="modalDigit">
            <input type="hidden" name="roll_no" id="modalRoll">
            <input type="hidden" name="lat" id="modalLat">
            <input type="hidden" name="lng" id="modalLng">
            <input type="hidden" name="action" value="mark_attendance">

            <div class="form-group">
                <label>Subject Code</label>
                <input type="text" name="subject" id="modalSubject" placeholder="CS101" required>
            </div>
            <div class="form-group">
                <label>Room No</label>
                <input type="text" name="room" id="modalRoom" placeholder="A-205" required>
            </div>
            <button type="submit">Mark Attendance</button>
            <div id="modalMessage" class="message" style="display:none;"></div>
        </form>
    </div>
</div>

<script>
// Geolocation
let currentLat = null, currentLng = null;
const locationStatus = document.getElementById('locationStatus');

function updateLocation() {
    if (!navigator.geolocation) {
        locationStatus.textContent = "Location not supported";
        locationStatus.style.color = "#f87171";
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            currentLat = pos.coords.latitude.toFixed(6);
            currentLng = pos.coords.longitude.toFixed(6);
            locationStatus.textContent = "Location ready";
            locationStatus.style.color = "#34d399";
            document.getElementById('modalLat').value = currentLat;
            document.getElementById('modalLng').value = currentLng;
        },
        () => {
            locationStatus.textContent = "Location denied (still works)";
            locationStatus.style.color = "#fb923c";
        },
        { timeout: 10000, maximumAge: 60000, enableHighAccuracy: true }
    );
}

// Run on load
updateLocation();
setInterval(updateLocation, 30000); // Refresh every 30s

// Modals
const regModal = document.getElementById('regModal');
const attendanceModal = document.getElementById('attendanceModal');
const closeBtn = document.querySelector('.close');
const form = document.getElementById('attendanceForm');
let savedRoll = localStorage.getItem('student_roll');

if (!savedRoll) {
    regModal.style.display = 'flex';
} else {
    document.getElementById('modalRoll').value = savedRoll;
}

// Save Roll
document.getElementById('saveRollBtn').onclick = () => {
    const roll = document.getElementById('regRoll').value.trim();
    const msg = document.getElementById('regMessage');
    msg.style.display = 'none';

    if (!roll || roll < 1 || roll > 100) {
        msg.style.color = '#f87171';
        msg.textContent = 'Invalid roll number (1-100)';
        msg.style.display = 'block';
        return;
    }

    localStorage.setItem('student_roll', roll);
    document.getElementById('modalRoll').value = roll;
    regModal.style.display = 'none';
    setTimeout(() => alert(`Welcome, Roll ${roll}!`), 300);
};

// Digit click
document.querySelectorAll('.digit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modalDigit').value = btn.dataset.digit;
        document.getElementById('modalLat').value = currentLat;
        document.getElementById('modalLng').value = currentLng;
        attendanceModal.style.display = 'flex';
        setTimeout(() => document.getElementById('modalSubject').focus(), 300);
    });
});

// Close modal
closeBtn.onclick = () => {
    attendanceModal.style.display = 'none';
    document.getElementById('modalMessage').style.display = 'none';
};
window.onclick = (e) => {
    if (e.target === attendanceModal || e.target === regModal) {
        e.target.style.display = 'none';
    }
};

// Submit attendance
form.onsubmit = async (e) => {
    e.preventDefault();
    const msgDiv = document.getElementById('modalMessage');
    msgDiv.style.display = 'none';

    const formData = new FormData(form);
    formData.append('lat', currentLat);
    formData.append('lng', currentLng);

    try {
        const resp = await fetch('', { method: 'POST', body: formData });
        const data = await resp.json();

        if (data.success) {
            msgDiv.style.color = '#34d399';
            msgDiv.innerHTML = `${data.message}<br><small>${data.location}</small>`;
            setTimeout(() => {
                attendanceModal.style.display = 'none';
                msgDiv.style.display = 'none';
            }, 3000);
        } else {
            msgDiv.style.color = '#f87171';
            msgDiv.textContent = data.message;
            msgDiv.style.display = 'block';
        }
    } catch (err) {
        msgDiv.style.color = '#f87171';
        msgDiv.textContent = 'Network error. Try again.';
        msgDiv.style.display = 'block';
    }
};

// View attendance link
document.getElementById('viewLink').onclick = (e) => {
    if (savedRoll) {
        e.preventDefault();
        window.location.href = `view_attendance.php?roll=${savedRoll}`;
    }
};
</script>
</body>
</html>
