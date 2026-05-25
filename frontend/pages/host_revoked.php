<?php
// frontend/pages/host_revoked.php
// Handles host accounts whose application was revoked or rejected by the admin.

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

// User must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Sync latest DB state to ensure status is up to date
if (isset($conn)) {
    isLoggedIn();
}

// User must have 'rejected' host status to view this page
if (!isset($_SESSION['host_status']) || $_SESSION['host_status'] !== 'rejected') {
    header('Location: dashboard.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'become_user' || $action === 'drop_and_back' || $action === 'logout_drop') {
        try {
            $conn->beginTransaction();
            
            // 1. Fetch file paths before deleting DB rows to protect privacy
            $stmt = $conn->prepare("SELECT gov_id_path, license_path, license_file FROM hosts WHERE user_id = :uid");
            $stmt->execute([':uid' => $user_id]);
            $host_doc = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 2. Delete document files from filesystem
            $upload_base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'host_docs';
            if ($host_doc) {
                if (!empty($host_doc['gov_id_path'])) {
                    $file_path = $upload_base . DIRECTORY_SEPARATOR . $host_doc['gov_id_path'];
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }
                if (!empty($host_doc['license_path'])) {
                    $file_path = $upload_base . DIRECTORY_SEPARATOR . $host_doc['license_path'];
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }
                if (!empty($host_doc['license_file'])) {
                    $file_path = $upload_base . DIRECTORY_SEPARATOR . $host_doc['license_file'];
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }
            }
            
            // 3. Delete host application record
            $stmtDel = $conn->prepare("DELETE FROM hosts WHERE user_id = :uid");
            $stmtDel->execute([':uid' => $user_id]);
            
            if ($action === 'logout_drop') {
                // Delete user record entirely
                $stmtDelUser = $conn->prepare("DELETE FROM users WHERE id = :uid");
                $stmtDelUser->execute([':uid' => $user_id]);
            } else {
                // 4. Ensure the user's role is set back to 'user'
                $stmt2 = $conn->prepare("UPDATE users SET role = 'user' WHERE id = :uid");
                $stmt2->execute([':uid' => $user_id]);
            }
            
            $conn->commit();
            
            // Update session status
            $_SESSION['host_status'] = null;
            $_SESSION['role'] = 'user';
            
            if ($action === 'logout_drop') {
                // Wipe session and destroy
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(), '',
                        time() - 42000,
                        $params['path'],
                        $params['domain'],
                        $params['secure'],
                        $params['httponly']
                    );
                }
                session_destroy();
                header('Location: login.php?logout=1');
            } elseif ($action === 'drop_and_back') {
                header('Location: index.php');
            } else {
                header('Location: dashboard.php');
            }
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log('host_revoked.php [' . $action . '] - ' . $e->getMessage());
            $error = 'A database error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Host application status update.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Account Update Required</title>
    <style>
        .auth-form-panel {
            position: relative;
            background: radial-gradient(circle at 80% 20%, rgba(123, 98, 98, 0.12) 0%, transparent 50%), 
                        radial-gradient(circle at 20% 80%, rgba(63, 62, 70, 0.12) 0%, transparent 50%), 
                        var(--bg-main);
        }
        .auth-form-inner {
            max-width: 450px !important;
            width: 100%;
        }
        
        /* Premium Header wrapper */
        .auth-header-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .auth-header-wrap .header-badge {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--accent);
            background: rgba(123, 98, 98, 0.08);
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid rgba(123, 98, 98, 0.12);
        }
        
        /* Logout button */
        .btn-logout-nav {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.1);
            color: #dc2626;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.25s var(--ease);
            cursor: pointer;
            outline: none;
            font-family: inherit;
        }
        .btn-logout-nav:hover {
            background: #dc2626;
            color: var(--white);
            border-color: #dc2626;
            transform: translateY(-1.5px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
        }
        .btn-logout-nav:active {
            transform: translateY(0);
        }
        
        /* Revoked alert card */
        .status-alert-box {
            background: var(--white);
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-left: 4px solid #dc2626;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 28px;
            display: flex;
            gap: 16px;
            align-items: flex-start;
            box-shadow: 0 10px 25px -5px rgba(220, 38, 38, 0.05), 0 8px 16px -6px rgba(220, 38, 38, 0.03);
            position: relative;
            overflow: hidden;
        }
        .status-alert-box::after {
            content: '';
            position: absolute;
            top: -20px; right: -20px;
            width: 80px; height: 80px;
            background: rgba(220, 38, 38, 0.02);
            border-radius: 50%;
        }
        .status-alert-box .alert-icon-wrap {
            background: rgba(220, 38, 38, 0.08);
            border-radius: 12px;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .status-alert-box .alert-icon-wrap i {
            font-size: 20px;
            color: #dc2626;
            margin: 0;
        }
        .status-alert-box .alert-content {
            flex: 1;
        }
        .status-alert-box h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 18px;
            color: #dc2626;
            margin-bottom: 6px;
            font-weight: 700;
        }
        .status-alert-box p {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.5;
            margin: 0;
        }
        
        /* Option Sections */
        .option-section {
            background: var(--white);
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 18px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.02), 0 8px 16px -6px rgba(0,0,0,0.02);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }
        .option-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: transparent;
            transition: background 0.3s ease;
        }
        .option-section.renter-option:hover {
            transform: translateY(-4px);
            border-color: rgba(5, 150, 105, 0.2);
            box-shadow: 0 12px 30px rgba(5, 150, 105, 0.06);
        }
        .option-section.renter-option:hover::before {
            background: #059669;
        }
        .option-section.host-option:hover {
            transform: translateY(-4px);
            border-color: rgba(123, 98, 98, 0.3);
            box-shadow: 0 12px 30px rgba(123, 98, 98, 0.08);
        }
        .option-section.host-option:hover::before {
            background: var(--accent);
        }
        
        .option-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 17px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .option-title .icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            transition: transform 0.3s ease;
            flex-shrink: 0;
        }
        .renter-option .icon-wrap {
            background: rgba(5, 150, 105, 0.08);
            color: #059669;
        }
        .host-option .icon-wrap {
            background: rgba(123, 98, 98, 0.08);
            color: var(--accent);
        }
        .option-section:hover .icon-wrap {
            transform: scale(1.1);
        }
        .option-desc {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        /* Action buttons in options */
        .btn-option-renter {
            background: rgba(5, 150, 105, 0.05);
            color: #059669;
            border: 1px solid rgba(5, 150, 105, 0.15);
            border-radius: 12px;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 14.5px;
            padding: 13px 20px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            box-sizing: border-box;
        }
        .btn-option-renter:hover {
            background: #059669;
            color: var(--white);
            border-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(5, 150, 105, 0.15);
        }
        .btn-option-renter:active {
            transform: translateY(0);
        }
        
        .btn-option-host {
            background: var(--primary);
            color: var(--white);
            border: 1px solid var(--primary);
            border-radius: 12px;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 14.5px;
            padding: 13px 20px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            box-sizing: border-box;
        }
        .btn-option-host:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(63, 62, 70, 0.2);
        }
        .btn-option-host:active {
            transform: translateY(0);
        }
        .btn-back {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 14.5px;
            padding: 13px 20px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            box-sizing: border-box;
        }
        .btn-back:hover {
            background: rgba(0, 0, 0, 0.03);
            color: var(--text-main);
            border-color: rgba(0, 0, 0, 0.2);
            transform: translateY(-2px);
        }
        .btn-back:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>

    <div class="auth-page-wrap">

        <!-- Left: Brand panel -->
        <div class="auth-brand-panel">
            <div class="auth-brand-logo">
                <div class="logo-icon"><i class="fas fa-car"></i></div>
                SpinGo
            </div>
            <div class="auth-brand-tagline">
                <h2>Account Status Update</h2>
                <p>We review all host applications with rigorous safety and verification protocols to maintain community trust.</p>
            </div>
            <div class="auth-brand-points">
                <div class="auth-brand-point">
                    <i class="fas fa-user-shield"></i>
                    Secure Verification System
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-handshake"></i>
                    Mutual Trust &amp; Quality standards
                </div>
            </div>
        </div>

        <!-- Right: Action panel -->
        <div class="auth-form-panel">
            <div class="auth-form-inner">

                <!-- Header block with Badge and Logout button -->
                <div class="auth-header-wrap">
                    <span class="header-badge">Host Panel</span>
                    <form method="POST" action="" style="margin: 0; padding: 0;" onsubmit="return confirm('Logging out will delete your account and drop it from the database. This action cannot be undone. Do you want to proceed?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="logout_drop">
                        <button type="submit" class="btn-logout-nav">
                            <i class="fas fa-sign-out-alt"></i> Log Out
                        </button>
                    </form>
                </div>

                <h1 class="auth-form-title">Application Status</h1>
                <p class="auth-form-subtitle">Your host application status was updated by an administrator.</p>

                <?php if ($error): ?>
                    <div class="auth-alert auth-alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Warning card -->
                <div class="status-alert-box">
                    <div class="alert-icon-wrap">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="alert-content">
                        <h3>Application Revoked</h3>
                        <p>Unfortunately, your host application has been revoked or was not approved. To proceed, please select one of the following paths to update your account status.</p>
                    </div>
                </div>

                <!-- Option A: Apply as a Renter -->
                <div class="option-section renter-option">
                    <div class="option-title">
                        <div class="icon-wrap">
                            <i class="fas fa-user-check"></i>
                        </div>
                        Apply as a Renter
                    </div>
                    <p class="option-desc">
                        Your previous host application was revoked. To re-register as a host, please continue as a user first. From your user account, you can submit a new host application again.
                    </p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="become_user">
                        <button type="submit" class="btn-option-renter" onclick="return confirm('Convert your account to a standard renter profile?')">
                            Continue as Renter <i class="fas fa-chevron-right" style="font-size: 11px;"></i>
                        </button>
                    </form>
                </div>



            </div>
        </div>

    </div>

</body>
</html>
