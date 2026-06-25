<?php
require_once __DIR__ . "/password.php";
require_once __DIR__ . "/securitycheck.php";
require_once __DIR__ . "/../paths.php";

$multiuser = auth_multiuser();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $ip = getip();
    $rl = check_rate_limit($ip);
    if (!$rl['allowed']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'locked' => true, 'retry_after' => $rl['retry_after']]);
        exit();
    }
    $result = ['success' => check_password(false)];
    if (!$result['success']) {
        $rl2 = check_rate_limit($ip);
        if (!$rl2['allowed']) {
            $result['locked'] = true;
            $result['retry_after'] = $rl2['retry_after'];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}
?>

<!DOCTYPE html>
<html class="dark">
<head>
    <title>YaAff Login</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script>
        // Match the SPA theme (admin-ui ThemeProvider stores 'yaaff-theme').
        // Applied before paint to avoid a flash of the wrong theme.
        (function () {
            try {
                var t = localStorage.getItem('yaaff-theme');
                if (t !== 'light' && t !== 'dark') {
                    t = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                }
                document.documentElement.classList.remove('dark', 'light');
                document.documentElement.classList.add(t);
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" type="text/css" href="css/login.css">
    <script>
        let lockoutActive = false;
        let lockoutTimer = null;

        function startLockout(seconds) {
            lockoutActive = true;
            const form = document.getElementById('login-form');
            const submitButton = form.querySelector('button[type="submit"]');
            const btnSpan = submitButton.querySelector('span');
            const originalText = btnSpan.textContent;

            submitButton.disabled = true;
            submitButton.classList.remove('loading');

            let remaining = seconds;
            function tick() {
                const m = Math.floor(remaining / 60);
                const s = remaining % 60;
                btnSpan.textContent = `Locked out — ${m}:${String(s).padStart(2, '0')}`;
                if (remaining <= 0) {
                    clearInterval(lockoutTimer);
                    lockoutActive = false;
                    submitButton.disabled = false;
                    btnSpan.textContent = originalText;
                    return;
                }
                remaining--;
            }
            tick();
            lockoutTimer = setInterval(tick, 1000);
        }

        function showError(msg) {
            const el = document.getElementById('login-error');
            if (!el) return;
            el.textContent = msg;
            el.classList.add('show');
        }
        function clearError() {
            const el = document.getElementById('login-error');
            if (el) el.classList.remove('show');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('login-form');
            const submitButton = form.querySelector('button[type="submit"]');
            const passwordInput = document.getElementById('password');

            // Focus first field on page load
            const initialFocus = document.getElementById('username') || passwordInput;
            initialFocus.focus();

            // Clear the error banner as soon as the user edits any field
            form.addEventListener('input', clearError);

            // Handle form submission
            form.addEventListener('submit', async function (e) {
                e.preventDefault();

                submitButton.disabled = true;
                submitButton.classList.add('loading');

                const password = passwordInput.value;
                const formData = new FormData();
                formData.append('password', password);
                const usernameInput = document.getElementById('username');
                if (usernameInput) formData.append('username', usernameInput.value);

                try {
                    const response = await fetch('login.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();
                    if (data.success) {
                        window.location.href = 'app.php';
                    } else if (data.locked) {
                        startLockout(data.retry_after);
                    } else {
                        showError('Wrong password. Please try again.');
                        passwordInput.value = '';
                        passwordInput.focus();
                    }
                } catch (error) {
                    showError('Something went wrong during login. Please retry.');
                }
                if (!lockoutActive) {
                    submitButton.disabled = false;
                    submitButton.classList.remove('loading');
                }
            });
        });
    </script>
</head>
<?php $cloPath = get_cloaker_path(); ?>
<body>
    <div id="main">
        <div class="yaaff-login-brand">
            <div class="yaaff-login-mark" aria-hidden="true">Y</div>
            <div>
                <div class="yaaff-login-name">YaAff</div>
                <div class="yaaff-login-subtitle">Command Center</div>
            </div>
        </div>
        <div class="login-container">
            <form id="login-form">
                <h2>Welcome back</h2>
                <p class="login-subtext">Sign in to your dashboard.</p>
                <div id="login-error" class="login-error" role="alert"></div>
                <?php if ($multiuser): ?>
                <div class="input-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autocomplete="username" placeholder="username" />
                </div>
                <?php endif; ?>
                <div class="input-group">
                    <label for="password"><?= $multiuser ? 'Password' : 'Admin password' ?></label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••" />
                </div>
                <button type="submit" class="login-button">
                    <img src="<?= $cloPath ?>img/loading.apng" class="loading-img" alt="Loading..." />
                    <span>Open dashboard</span>
                </button>
            </form>
            <div class="version-info">
                <?php include __DIR__ . "/version.php"; ?>
            </div>
        </div>
    </div>
</body>
</html>
