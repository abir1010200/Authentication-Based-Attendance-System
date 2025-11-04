<?php
// student.php - Student Portal (Env Vars ONLY)

$db_host = getenv('DB_HOST') ?: die('DB_HOST missing');
$db_port = (int)(getenv('DB_PORT') ?: 3306);
$db_name = getenv('DB_NAME') ?: die('DB_NAME missing');
$db_user = getenv('DB_USER') ?: die('DB_USER missing');
$db_pass = getenv('DB_PASS') ?: die('DB_PASS missing');

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS temp_attendance (
    id INT NOT NULL AUTO_INCREMENT,
    subject_code VARCHAR(20) NOT NULL,
    room_no VARCHAR(20) NOT NULL,
    roll_no INT NOT NULL,
    digit INT NOT NULL,
    ts DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
    id INT NOT NULL AUTO_INCREMENT,
    roll_no INT NOT NULL,
    subject_code VARCHAR(20) NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_time TIME NOT NULL,
    ts DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    die("Database setup error: " . htmlspecialchars($e->getMessage()));
}

// Mark attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_attendance') {
    header('Content-Type: application/json');
    
    $roll_no = (int)($_POST['roll_no'] ?? 0);
    $subject = strtoupper(trim($_POST['subject'] ?? ''));
    $room    = strtoupper(trim($_POST['room'] ?? ''));
    $digit   = (int)($_POST['digit'] ?? -1);

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
                INSERT INTO attendance (roll_no, subject_code, attendance_date, attendance_time, ts)
                VALUES (?, ?, DATE(?), TIME(?), ?)
            ");
            $insert->execute([$roll_no, $subject, $now, $now, $now]);

            $last_id = $pdo->lastInsertId();

            $delete = $pdo->prepare("
                DELETE FROM temp_attendance 
                WHERE subject_code = ? AND room_no = ? AND roll_no = ?
            ");
            $delete->execute([$subject, $room, $roll_no]);

            echo json_encode([
                'success' => true,
                'message' => "Attendance marked successfully! (ID: $last_id)"
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No matching digit in 10-second window.']);
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
            padding: 20px;
        }
        .container {
            background: #1e293b;
            padding: 32px;
            border-radius: 24px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 {
            font-size: 1.8rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 24px;
            color: #60a5fa;
        }
        .digit-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin: 20px 0;
        }
        .digit-btn {
            background: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 16px;
            font-size: 2rem;
            font-weight: 700;
            color: #e2e8f0;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .digit-btn:hover {
            background: rgba(255,255,255,0.18);
            border-color: #60a5fa;
        }
        .hint {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 8px;
            text-align: center;
        }
        .modal, #regModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
        }
        .modal-content, .reg-content {
            background: #1e293b;
            padding: 32px;
            border-radius: 24px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .close {
            color: #94a3b8;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: #f87171;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 0.95rem;
            color: #cbd5e1;
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
        }
        input:focus {
            outline: none;
            border-color: #60a5fa;
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
            transition: all 0.3s ease;
        }
        button[type="submit"]:hover, #saveRollBtn:hover {
            background: #2563eb;
        }
        .message {
            margin-top: 16px;
            padding: 12px;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
        }
        @media (max-width: 480px) {
            .container { padding: 24px; }
            h1 { font-size: 1.6rem; }
        }
    </style>
</head>
<body>

<div class="container">
    <h1>STUDENT PORTAL</h1>
    <p style="text-align:center; color:#94a3b8; margin-bottom:20px;">Tap the digit shown in class to mark attendance</p>
    <div class="digit-grid">
        <?php for ($i = 0; $i <= 9; $i++): ?>
            <div class="digit-btn" data-digit="<?php echo $i; ?>"><?php echo $i; ?></div>
        <?php endfor; ?>
    </div>
    <div class="hint">Submit within 10 seconds of tapping the digit</div>
    <a href="view_attendance.php" id="viewLink" style="display:block; text-align:center; margin-top:20px; color:#60a5fa;">View My Attendance</a>
</div>

<!-- Registration Modal -->
<div id="regModal" class="modal">
    <div class="reg-content">
        <h2 style="text-align:center; color:#60a5fa; margin-bottom:20px;">One-Time Registration</h2>
        <div class="form-group">
            <label>Your Roll Number (1-100)</label>
            <input type="number" id="regRoll" min="1" max="100" placeholder="e.g., 45" required>
        </div>
        <button id="saveRollBtn">Save & Continue</button>
        <div id="regMessage" class="message" style="display:none;"></div>
    </div>
</div>

<!-- Attendance Modal -->
<div id="attendanceModal" class="modal">
    <div class="modal-content">
        <span class="close">x</span>
        <h2 style="text-align:center; color:#60a5fa; margin-bottom:20px;">Enter Class Details</h2>
        <form id="attendanceForm">
            <input type="hidden" name="digit" id="modalDigit">
            <input type="hidden" name="roll_no" id="modalRoll">
            <input type="hidden" name="action" value="mark_attendance">
            <div class="form-group">
                <label>Subject Code</label>
                <input type="text" name="subject" id="modalSubject" placeholder="e.g., CS101" required>
            </div>
            <div class="form-group">
                <label>Room No</label>
                <input type="text" name="room" id="modalRoom" placeholder="e.g., A-101" required>
            </div>
            <button type="submit">Mark Attendance</button>
            <div id="modalMessage" class="message" style="display:none;"></div>
        </form>
    </div>
</div>

<script>
const regModal = document.getElementById('regModal');
const attendanceModal = document.getElementById('attendanceModal');
const closeBtn = document.querySelector('.close');
const form = document.getElementById('attendanceForm');
let selectedDigit = null;
let savedRoll = localStorage.getItem('student_roll');

if (!savedRoll) {
    regModal.style.display = 'flex';
} else {
    document.getElementById('modalRoll').value = savedRoll;
}

document.getElementById('saveRollBtn').onclick = () => {
    const roll = document.getElementById('regRoll').value.trim();
    const msg = document.getElementById('regMessage');
    msg.style.display = 'none';

    if (!roll || roll < 1 || roll > 100) {
        msg.style.color = '#f87171';
        msg.textContent = 'Enter valid roll (1-10)';
        msg.style.display = 'block';
        return;
    }

    localStorage.setItem('student_roll', roll);
    document.getElementById('modalRoll').value = roll;
    regModal.style.display = 'none';
    msg.style.color = '#34d399';
    msg.textContent = 'Roll saved!';
    msg.style.display = 'block';
    setTimeout(() => msg.style.display = 'none', 2000);
};

document.querySelectorAll('.digit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        selectedDigit = btn.getAttribute('data-digit');
        document.getElementById('modalDigit').value = selectedDigit;
        attendanceModal.style.display = 'flex';
        document.getElementById('modalSubject').focus();
    });
});

closeBtn.onclick = () => {
    attendanceModal.style.display = 'none';
    document.getElementById('modalMessage').style.display = 'none';
};

window.onclick = (e) => {
    if (e.target === attendanceModal) {
        attendanceModal.style.display = 'none';
        document.getElementById('modalMessage').style.display = 'none';
    }
};

form.onsubmit = async (e) => {
    e.preventDefault();
    const msgDiv = document.getElementById('modalMessage');
    msgDiv.style.display = 'none';

    const formData = new FormData(form);

    try {
        const resp = await fetch('', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

        if (data.success) {
            msgDiv.style.color = '#34d399';
            msgDiv.textContent = data.message || 'Attendance marked!';
            setTimeout(() => {
                attendanceModal.style.display = 'none';
                msgDiv.style.display = 'none';
            }, 3000);
        } else {
            msgDiv.style.color = '#f87171';
            msgDiv.textContent = data.message || 'Failed.';
            msgDiv.style.display = 'block';
        }
    } catch (err) {
        msgDiv.style.color = '#f87171';
        msgDiv.textContent = 'Network error.';
        msgDiv.style.display = 'block';
    }
};

document.getElementById('viewLink').addEventListener('click', (e) => {
    if (savedRoll) {
        e.preventDefault();
        window.location.href = 'view_attendance.php?roll=' + savedRoll;
    }
});
</script>

</body>
</html>