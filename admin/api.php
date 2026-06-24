<?php
require_once __DIR__ . '/securitycheck.php';
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php' ?>
<body>
<?php include __DIR__ . '/header.php' ?>
<div class="all-content-wrapper">
    <div class="container-fluid" style="padding-top:20px">
        <div class="d-flex align-items-center gap-2 mb-2">
            <h5 class="mb-0">REST API</h5>
            <a class="btn btn-sm btn-outline-secondary" href="../api/openapi.php" target="_blank">openapi.json</a>
        </div>
        <p class="text-muted small mb-3">
            Authenticate every request with <code>Authorization: Bearer &lt;token&gt;</code> — either a user's
            <code>api_token</code> (Users page) or the master <code>apiToken</code> from <code>settings.php</code>.
            Base path: <code>/api/rest.php/&lt;type&gt;[/&lt;id&gt;]</code>.
        </p>
        <div id="swagger-ui"></div>
    </div>
</div>
<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
<script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
<script>
window.addEventListener('load', function () {
    if (!window.SwaggerUIBundle) return;
    SwaggerUIBundle({ url: '../api/openapi.php', dom_id: '#swagger-ui' });
});
</script>
</body>
</html>
