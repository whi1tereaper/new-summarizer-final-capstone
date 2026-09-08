<?php
require_once __DIR__ . '/../src/whitereaper.php';

// This page only exists between form submission and the final result page.
if (empty($_SESSION['pending_summary'])) {
    header('Location: summarizer.php');
    exit;
}

$username = $_SESSION['username'] ?? 'GUEST';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Summary | Article Summarizer</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index.css">
    <style>
        :root {
            --bg-color: #f8fafc;
            --accent-primary: #8f3fe0;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        body.processing-page {
            background-color: var(--bg-color);
            color: var(--text-main);
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }

        .processing-container {
            text-align: center;
            max-width: 500px;
            width: 90%;
        }

        .status-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            margin-bottom: 8px;
            font-weight: 700;
            color: var(--text-main);
        }

        .sub-status {
            color: var(--text-muted);
            font-size: 1rem;
            margin-bottom: 48px;
        }
    </style>
</head>
<body class="processing-page">
    <div class="processing-container">
    
        <p class="sub-status">Processing your request...</p>
    </div>

    <script>
        // Kick off the heavy summary work after the lightweight processing screen is visible.
        async function runSummarization() {
            try {
                const response = await fetch('summarize.php?action=execute');
                const rawBody = await response.text();
                let data;

                try {
                    data = JSON.parse(rawBody);
                } catch (parseError) {
                    // A non-JSON response usually means PHP emitted a fatal error before the controller finished.
                    throw new Error('Summarization returned an invalid server response. Check the PHP error log and local Python setup.');
                }

                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    throw new Error(data.error || 'An unexpected error occurred.');
                }
            } catch (error) {
                console.error(error);
                // Send the message back to the form page so the user lands where they can retry.
                window.location.href = 'summarizer.php?error=' + encodeURIComponent(error.message);
            }
        }

        // Small delay lets the loading page paint before the request starts.
        setTimeout(runSummarization, 800);
    </script>
</body>
</html>
