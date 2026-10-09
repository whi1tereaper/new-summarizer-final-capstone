<?php
// Redirect root requests to the public web root.
// Build the base path dynamically so this works both at the domain root
// and inside an XAMPP htdocs subfolder (e.g. /ai-summarizer-123 - quality upg/).
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
header('Location: ' . $base . '/app/public/', true, 302);
exit;
