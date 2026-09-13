<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// UI Only - Student Dashboard (Guide Only)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE Student Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    background: #f4f6fb;
}

/* ===== MAIN CONTENT ===== */
/* pushed right by sidebar width, pushed down by topbar height */
.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
    overflow-y: auto;
}

/* ===== DASHBOARD PRESENTATION LAYOUT ===== */
.presentation-container {
    background: #f0f0f0;
    padding: 50px;
    margin: 0 auto;
    max-width: 850px;
    border-radius: 4px;
}

.presentation-header {
    margin-bottom: 30px;
}

.presentation-header h1 {
    font-size: 36px;
    color: #444;
    font-weight: 400;
    line-height: 1.3;
}

.presentation-header span {
    color: #888;
    font-weight: 400;
}

.presentation-embed {
    background: #5d3a7a;
    width: 100%;
    height: 400px;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #ffffff;
}

.embed-content h3 {
    font-size: 26px;
    font-weight: 500;
    text-align: center;
    letter-spacing: 1.5px;
    margin-bottom: 20px;
    line-height: 1.4;
}

.embed-content p {
    font-size: 9px;
    color: #b0c4de;
    text-align: center;
    letter-spacing: 0.5px;
}

.embed-arrow {
    position: absolute;
    right: 20px;
    bottom: 50px;
    color: #f39c12;
    font-size: 40px;
    cursor: pointer;
}

.embed-footer {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    background: #f9f9f9;
    padding: 8px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #e0e0e0;
}

.embed-footer span {
    color: #666;
    font-size: 12px;
}

.embed-footer .footer-icons i {
    color: #666;
    font-size: 16px;
    margin-left: 15px;
    cursor: pointer;
}

@media (max-width: 1024px) {
    .main { margin-left: 0 !important; padding: 16px !important; }
}

@media (max-width: 700px) {
    .presentation-container { padding: 20px; border-radius: 8px; }
    .presentation-header h1 { font-size: 24px; }
    .presentation-embed { height: 260px; }
    .embed-content h3 { font-size: 19px; letter-spacing: 0.5px; margin-bottom: 12px; }
    .embed-content p { font-size: 11px; padding: 0 12px; }
    .embed-arrow { right: 12px; bottom: 40px; font-size: 30px; }
    .embed-footer { padding: 8px 12px; }
}

@media (max-width: 420px) {
    .presentation-header h1 { font-size: 20px; }
    .presentation-embed { height: 220px; }
}
</style>
</head>

<body>

<!-- TOPBAR (fixed, full width, on top) -->
<?php include 'student_topbar.php'; ?>

<!-- SIDEBAR (fixed, below topbar) -->
<?php include 'student_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">

    <div class="presentation-container">
        <div class="presentation-header">
            <h1>Welcome to VOICE - <br><span>A web-based information system for managing student complaints and suggestions</span></h1>
        </div>

        <div class="presentation-embed">
            <div class="embed-content">
                <h3>VOICE<br>STUDENT GUIDE</h3>
                <p>CLICK THE &lt; &gt; ICONS TO NAVIGATE TO THE PREVIOUS / NEXT SLIDE, OR USE YOUR ARROW KEYS.</p>
            </div>

            <i class='bx bx-chevron-right embed-arrow'></i>

            <div class="embed-footer">
                <span>Created with Slides.com</span>
                <div class="footer-icons">
                    <i class='bx bx-share-alt'></i>
                    <i class='bx bx-fullscreen'></i>
                </div>
            </div>
        </div>
    </div>

</div>

</body>
</html>