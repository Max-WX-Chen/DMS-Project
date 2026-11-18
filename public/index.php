<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FORM Club</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/live.css">
  <script type="module" src="assets/club.js"></script>
</head>
<body>
  <a href="#main" class="skip">Skip to content</a>
  <header class="site-header">
    <nav class="container nav" aria-label="Main navigation">
      <a class="brand" href="?view=home" aria-label="FORM Club home"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>FORM<small>CLUB</small></a>
      <div class="nav-links" id="navigation"><a href="?view=schedule">Classes</a><a href="?view=plans">Membership</a></div>
      <div class="nav-tools" id="account-tools"></div>
    </nav>
  </header>
  <main class="container" id="main">
    <div id="admin-navigation"></div>
    <div id="feedback" class="form-message" role="status" aria-live="polite"></div>
    <section id="page" aria-busy="true"><p class="spaced">Loading FORM Club…</p></section>
    <noscript>Enable JavaScript to use the club booking forms.</noscript>
  </main>
  <footer class="footer"><div class="container footer-inner"><strong>FORM Club</strong><p>Times in Asia/Shanghai · Prices in CNY</p><output id="timing" aria-live="off"></output></div></footer>
</body>
</html>
