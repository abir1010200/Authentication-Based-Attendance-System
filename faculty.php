<?php
// faculty.php - Faculty Roll (Env Vars ONLY)

$db_host = getenv('DB_HOST') ?: die('DB_HOST missing');
$db_port = (int)(getenv('DB_PORT') ?: 3306);
$db_name = getenv('DB_NAME') ?: die('DB_NAME missing');
$db_user = getenv('DB_USER') ?: die('DB_USER missing');
$db_pass = getenv('DB_PASS') ?: die('DB_PASS missing');

if (empty($db_host) || empty($db_name) || empty($db_user) || empty($db_pass)) {
    die('Set DB_HOST, DB_NAME, DB_USER, DB_PASS in environment variables.');
}

// Save digit per roll
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_digit') {
    header('Content-Type: application/json');

    $subject = strtoupper(trim($_POST['subject'] ?? ''));
    $room = strtoupper(trim($_POST['room'] ?? ''));
    $roll_no = (int)($_POST['roll_no'] ?? 0);
    $digit = (int)($_POST['digit'] ?? -1);

    if (empty($subject) || empty($room) || $roll_no < 1 || $roll_no > 100 || $digit < 0 || $digit > 9) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    try {
        $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
    id INT NOT NULL AUTO_INCREMENT,
    roll_no INT NOT NULL,
    subject_code VARCHAR(20) NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_time TIME NOT NULL,
    ts DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;");

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("REPLACE INTO temp_attendance (subject_code, room_no, roll_no, digit, ts) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$subject, $room, $roll_no, $digit, $now]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Roll complete - TRUNCATE after delay
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'roll_complete') {
    header('Content-Type: application/json');

    try {
        $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("TRUNCATE TABLE temp_attendance");

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Faculty Roll</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      height: 100vh; width: 100vw;
      background: #0a0a0a; color: white;
      font-family: 'Segoe UI', Arial, sans-serif;
      display: flex; flex-direction: column; align-items: center;
      overflow: hidden;
    }

    .info-box {
      margin: 30px 0 10px; text-align: center;
      color: #0ff; text-shadow: 0 0 12px #0ff; font-weight: bold;
    }
    .subject { font-size: 50px; }
    .room { font-size: 40px; margin-top: 8px; }

    .container {
      width: 70%; max-width: 900px; padding: 30px;
      background: #1a1a1a; border-radius: 15px;
      box-shadow: 0 0 30px rgba(0,255,255,0.4);
      border: 1px solid #0ff;
    }
    #title { font-size: 150px; margin: 0; color: #fff; }
    .circle {
      width: 400px; height: 400px; margin: 20px auto;
      border: 8px solid #0ff; border-radius: 50%;
      display: flex; justify-content: center; align-items: center;
      font-size: 300px; font-weight: bold;
      color: #0ff; text-shadow: 0 0 20px #0ff;
    }

    #inputModal {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.97); display: flex;
      justify-content: center; align-items: center; z-index: 999;
    }
    .modal-content {
      background: #1a1a1a; padding: 40px; border-radius: 20px;
      width: 400px; text-align: center; border: 2px solid #0ff;
      box-shadow: 0 0 30px #0ff;
    }
    .modal-content h2 { color: #0ff; margin-bottom: 20px; }
    input {
      width: 100%; padding: 14px; margin: 12px 0;
      font-size: 18px; border: 2px solid #0ff; border-radius: 10px;
      background: #111; color: white;
    }
    button {
      background: #0ff; color: #111; border: none;
      padding: 14px 40px; font-size: 22px; font-weight: bold;
      border-radius: 10px; cursor: pointer; margin-top: 20px;
      box-shadow: 0 0 15px #0ff; transition: 0.3s;
    }
    button:hover { background: #0cc; transform: scale(1.05); }

    .hidden { display: none !important; }
    #status { font-size: 24px; margin: 20px; }
    .success { color: #0f0; } .error { color: #f00; }
  </style>
</head>
<body>

  <div class="info-box hidden" id="infoBox">
    <div class="subject" id="subjectLine"></div>
    <div class="room" id="roomLine"></div>
  </div>

  <div id="status" class="hidden"></div>

  <div id="inputModal">
    <div class="modal-content">
      <h2>Enter Details</h2>
      <input type="text" id="subjectCode" placeholder="Subject Code" maxlength="10" required />
      <input type="text" id="roomNo" placeholder="Room No." maxlength="10" required />
      <button onclick="startRolling()">START ROLL</button>
    </div>
  </div>

  <div class="container hidden" id="rollContainer">
    <div id="title">ROLL : 1</div>
    <div class="circle" id="digit">–</div>
  </div>

  <div style="text-align:center; margin-top:30px;">
    <a href="view_attendance.php" style="color:#0ff; font-size:20px; text-decoration:none;">View Attendance Records</a>
  </div>

  <script>
    let roll = 1;
    const total = 10;
    let subjectCode = "", roomNo = "";

    function showStatus(msg, isError = false) {
      const el = document.getElementById("status");
      el.innerText = msg;
      el.className = isError ? "error" : "success";
      el.classList.remove("hidden");
      setTimeout(() => el.classList.add("hidden"), 10000);  // Show longer
    }

    function startRolling() {
      subjectCode = document.getElementById("subjectCode").value.trim().toUpperCase();
      roomNo = document.getElementById("roomNo").value.trim().toUpperCase();
      if (!subjectCode || !roomNo) return alert("Both fields required!");

      document.getElementById("inputModal").classList.add("hidden");
      document.getElementById("subjectLine").innerText = "SUBJECT CODE : " + subjectCode;
      document.getElementById("roomLine").innerText = "ROOM NO. : " + roomNo;
      document.getElementById("infoBox").classList.remove("hidden");
      document.getElementById("rollContainer").classList.remove("hidden");

      startRoll();
    }

    async function saveDigit(roll_no, digit) {
      const formData = new FormData();
      formData.append('action', 'save_digit');
      formData.append('subject', subjectCode);
      formData.append('room', roomNo);
      formData.append('roll_no', roll_no);
      formData.append('digit', digit);

      try {
        await fetch('', {
          method: 'POST',
          body: formData
        });
      } catch (err) {}
    }

    async function completeRoll() {
      const formData = new FormData();
      formData.append('action', 'roll_complete');

      try {
        await fetch('', {
          method: 'POST',
          body: formData
        });
        showStatus("Temp data cleared! Attendance window closed.", false);
      } catch (err) {}
    }

    function startRoll() {
      if (roll > total) {
        document.getElementById("title").innerText = "ALL DONE!";
        document.getElementById("digit").innerText = "";
        showStatus("Roll complete! Temp data will clear in 30 seconds.");
        setTimeout(completeRoll, 30000);  // TRUNCATE after 30s
        return;
      }
      const digit = Math.floor(Math.random() * 10);
      document.getElementById("title").innerText = "ROLL : " + roll;
      document.getElementById("digit").innerText = digit;

      saveDigit(roll, digit);

      roll++;
      setTimeout(startRoll, 2000);
    }
  </script>
</body>
</html>