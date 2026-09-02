<?php
// Copy this file to smtp_config.php (same folder) and fill in real values.
// smtp_config.php is git-ignored — it must never be committed, since it
// holds a live credential for the sending Gmail account.
return [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => 'your-account@gmail.com',
    // Generate at https://myaccount.google.com/apppasswords — do NOT use
    // your normal Gmail password here.
    'password' => 'xxxx xxxx xxxx xxxx',
    'from_email' => 'your-account@gmail.com',
    'from_name' => 'VOICE Complaint System',
    'secure' => 'tls',
];
