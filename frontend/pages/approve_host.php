<?php
// frontend/pages/approve_host.php
// Admin-only page: review and approve/reject host applications.

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/HostApplication.php';

requireAdmin();

$user      = getCurrentUser();
$hostApp   = new HostApplication($conn);
$navActive = 'admin';
$flash     = '';
$flashType = 'success';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action  = $_POST['action']  ?? '';
    $host_id = (int)($_POST['host_id'] ?? $_POST['user_id'] ?? 0);

    if (in_array($action, ['approve', 'reject'], true) && $host_id > 0) {
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
        // host_id here is user_id (hosts table PK is user_id)
        $ok = $hostApp->updateStatus($host_id, $newStatus, (int)$user['id']);
        if ($ok) {
            $flash = $action === 'approve'
                ? 'Host application approved successfully. User role updated to host.'
                : 'Host application rejected.';
            
            if ($action === 'approve') {
                $conn->prepare("
                    INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
                    VALUES (:uid, 'host', 'application_approved', 'Host Application Approved 🎉', 
                            'Congratulations! Your SpinGo host application has been approved. You can now list your vehicles.', 
                            'host_dashboard.php')
                ")->execute([':uid' => $host_id]);
            } else {
                $conn->prepare("
                    INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
                    VALUES (:uid, 'user', 'application_rejected', 'Host Application Rejected/Revoked ❌', 
                            'Unfortunately, your host application was rejected or revoked by the administrator.', 
                            'host_revoked.php')
                ")->execute([':uid' => $host_id]);
            }
        } else {
            $flash     = 'Action failed. Please try again.';
            $flashType = 'error';
        }
    }
}

// ── Fetch all applications ───────────────────────────────────────────────────
$applications = $hostApp->getAll();
$pendingCount = count(array_filter($applications, fn($a) => $a['status'] === 'pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo Admin — review host applications.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <title>SpinGo | Host Applications</title>
    
    
    
    
    
    
    
    
</head>
<body>
    <?php 
    $navActive = 'admin';
    include '../includes/navbar.php'; 
    ?>

    <div class="dash-wrapper">

        <!-- Page Header -->
        <div class="health-header">
            <div class="health-header-content">
                <h1>Host Applications</h1>
                <p>Review and approve host applicants. <strong><?= $pendingCount ?></strong> pending.</p>
            </div>
            <a href="admin.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Admin
            </a>
        </div>

        <!-- Flash message -->
        <?php if ($flash): ?>
            <div class="dash-flash <?= $flashType === 'error' ? 'dash-alert-error' : '' ?>">
                <i class="fas fa-<?= $flashType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <!-- Summary stats -->
        <div class="dash-stats-grid">
            <?php
            $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
            foreach ($applications as $a) $counts[$a['status']] = ($counts[$a['status']] ?? 0) + 1;
            ?>
            <div class="dash-stat-card">
                <div class="dash-stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div>
                    <span class="dash-stat-label">Pending</span>
                    <span class="dash-stat-value"><?= $counts['pending'] ?></span>
                </div>
            </div>
            <div class="dash-stat-card dash-stat-accent-blue">
                <div class="dash-stat-icon"><i class="fas fa-check-circle"></i></div>
                <div>
                    <span class="dash-stat-label">Approved Hosts</span>
                    <span class="dash-stat-value"><?= $counts['approved'] ?></span>
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dash-stat-icon"><i class="fas fa-times-circle"></i></div>
                <div>
                    <span class="dash-stat-label">Rejected</span>
                    <span class="dash-stat-value"><?= $counts['rejected'] ?></span>
                </div>
            </div>
        </div>

        <!-- Applications Table -->
        <div class="dash-card">
            <div class="dash-card-header">
                <h2>All Applications</h2>
            </div>

            <!-- Filter tabs (JS-powered) -->
            <div class="filter-tabs">
                <button class="filter-tab active btn" onclick="filterTable('all', this)">All</button>
                <button class="filter-tab btn" onclick="filterTable('pending',  this)">Pending</button>
                <button class="filter-tab btn" onclick="filterTable('approved', this)">Approved</button>
                <button class="filter-tab btn" onclick="filterTable('rejected', this)">Rejected</button>
            </div>

            <div class="dash-table-wrap">
                <table class="dash-table" id="host-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Applicant</th>
                            <th>Phone</th>
                            <th>Documents</th>
                            <th>Reason</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($applications)): ?>
                        <tr>
                            <td colspan="8">
                                No host applications yet.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php $idx = 1; foreach ($applications as $a): ?>
                        <tr data-status="<?= htmlspecialchars($a['status']) ?>">
                            <td class="dash-td-id"><?= $idx++ ?></td>
                            <td>
                                <div class="dash-vehicle-name"><?= htmlspecialchars($a['full_name']) ?></div>
                                <div class="dash-vehicle-type"><?= htmlspecialchars($a['email']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($a['phone']) ?></td>
                            <td>
                                 <?php if ($a['gov_id_path']): ?>
                                     <a href="/Spin_Go/frontend/pages/view_document.php?dir=host_docs&file=<?= urlencode($a['gov_id_path']) ?>"
                                        target="_blank" class="host-doc-link" title="Government ID">
                                         <i class="fas fa-id-card"></i> Gov ID
                                     </a>
                                 <?php endif; ?>
                                 <?php if ($a['license_path']): ?>
                                     <a href="/Spin_Go/frontend/pages/view_document.php?dir=host_docs&file=<?= urlencode($a['license_path']) ?>"
                                        target="_blank" class="host-doc-link" title="Driver's License">
                                         <i class="fas fa-car"></i> License
                                     </a>
                                 <?php endif; ?>
                            </td>
                            <td>
                                <span class="ha-desc-cell" title="<?= htmlspecialchars($a['description'] ?? '') ?>">
                                    <?= htmlspecialchars(mb_substr($a['description'] ?? '', 0, 60)) ?>…
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime($a['created_at'])) ?>
                            </td>
                            <td>
                                <span class="dash-status-badge dash-status-<?= htmlspecialchars($a['status']) ?>">
                                    <?= $a['status'] === 'rejected' ? 'Revoked' : ucfirst(htmlspecialchars($a['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-cell">
                                    <?php if ($a['status'] === 'pending'): ?>
                                    <form class="action-form" method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action"  value="approve">
                                        <input type="hidden" name="host_id" value="<?= (int)$a['user_id'] ?>">
                                        <button type="submit" class="btn-approve-host btn"
                                                onclick="return confirm('Approve <?= htmlspecialchars(addslashes($a['full_name'])) ?> as a host?')">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form class="action-form" method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action"  value="reject">
                                        <input type="hidden" name="host_id" value="<?= (int)$a['user_id'] ?>">
                                        <button type="submit" class="btn-reject-host btn"
                                                onclick="return confirm('Reject this application?')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </form>
                                    <?php elseif ($a['status'] === 'approved'): ?>
                                    <form class="action-form" method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action"  value="reject">
                                        <input type="hidden" name="host_id" value="<?= (int)$a['user_id'] ?>">
                                        <button type="submit" class="btn-reject-host btn"
                                                onclick="return confirm('Revoke host access?')">
                                            <i class="fas fa-ban"></i> Revoke
                                        </button>
                                    </form>
                                     <?php else: ?>
                                     <form class="action-form" method="POST">
                                         <?= csrf_field() ?>
                                         <input type="hidden" name="action"  value="approve">
                                         <input type="hidden" name="host_id" value="<?= (int)$a['user_id'] ?>">
                                         <button type="submit" class="btn-approve-host btn"
                                                 onclick="return confirm('Approve <?= htmlspecialchars(addslashes($a['full_name'])) ?> as a host?')">
                                             <i class="fas fa-check"></i> Approve
                                         </button>
                                     </form>
                                     <form class="action-form" method="POST">
                                         <?= csrf_field() ?>
                                         <input type="hidden" name="action"  value="reject">
                                         <input type="hidden" name="host_id" value="<?= (int)$a['user_id'] ?>">
                                         <button type="submit" class="btn-reject-host btn"
                                                 onclick="return confirm('Reject this application?')">
                                             <i class="fas fa-times"></i> Reject
                                         </button>
                                     </form>
                                     <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /.dash-wrapper -->

    <script src="../js/app.js"></script>
    <script>
    function filterTable(status, btn) {
        // Update active tab
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');

        // Show/hide rows
        document.querySelectorAll('#host-table tbody tr[data-status]').forEach(row => {
            row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
        });
    }
    </script>
</body>
</html>
