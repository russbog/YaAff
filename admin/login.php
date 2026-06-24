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
<html>
<head>
    <title>YaAff Login</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <link rel="stylesheet" type="text/css" href="css/login.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
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

        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('login-form');
            const submitButton = form.querySelector('button[type="submit"]');
            const passwordInput = document.getElementById('password');
            const fakeInput = document.getElementById('fake-input');
            const cursor = document.getElementById('cursor');

            // Focus input on page load
            const initialFocus = document.getElementById('username') || passwordInput;
            initialFocus.focus();

            // Handle cursor blinking
            let cursorVisible = true;
            setInterval(() => {
                cursorVisible = !cursorVisible;
                cursor.textContent = cursorVisible ? '█' : '';
            }, 530);

            // Handle password input
            passwordInput.addEventListener('input', function (e) {
                const value = this.value;
                fakeInput.textContent = 'X'.repeat(value.length);
            });

            // Keep focus on the real input (but allow the username field to take focus)
            document.addEventListener('click', (e) => {
                if (e.target && e.target.id === 'username') return;
                passwordInput.focus();
            });
            fakeInput.addEventListener('click', (e) => {
                e.preventDefault();
                passwordInput.focus();
            });

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
                        window.location.href = 'index.php';
                    } else if (data.locked) {
                        startLockout(data.retry_after);
                    } else {
                        alert('Wrong password!');
                    }
                } catch (error) {
                    alert('Error occurred during login');
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
        <div id="title" class="yaaff-login-brand">
            <div class="yaaff-login-mark" aria-hidden="true">Y</div>
            <div>
                <div class="yaaff-login-name">YaAff</div>
                <div class="yaaff-login-subtitle">Affiliate traffic intelligence</div>
            </div>
        </div>
        <div class="login-container">
            <form id="login-form">
                <h2>Welcome back</h2>
                <?php if ($multiuser): ?>
                <div class="input-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autocomplete="username" />
                </div>
                <?php endif; ?>
                <div class="input-group">
                    <label for="password"><?= $multiuser ? 'Password' : 'Enter Admin Password' ?></label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" required autocomplete="off"/>
                        <div class="fake-input-container">
                            <span id="fake-input"></span><span id="cursor">█</span>
                        </div>
                    </div>
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
