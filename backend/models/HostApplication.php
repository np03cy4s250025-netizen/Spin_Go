<?php
// backend/models/HostApplication.php

class HostApplication {
    private $conn;

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    /** Get a host record by the user's ID. Returns null if none exists. */
    public function getByUserId(int $user_id): ?array {
        $stmt = $this->conn->prepare(
            "SELECT h.*, u.full_name, u.email
             FROM hosts h
             JOIN users u ON h.user_id = u.id
             WHERE h.user_id = :uid
             LIMIT 1"
        );
        $stmt->execute([':uid' => $user_id]);
        return $stmt->fetch() ?: null;
    }

    /** Submit a new host application (status = pending). */
    public function create(int $user_id, string $phone, ?string $gov_id_path, ?string $license_path, string $description): bool {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO hosts (user_id, phone, gov_id_path, license_path, description, status)
                 VALUES (:uid, :phone, :gov, :lic, :desc, 'pending')"
            );
            $stmt->execute([
                ':uid'   => $user_id,
                ':phone' => $phone,
                ':gov'   => $gov_id_path,
                ':lic'   => $license_path,
                ':desc'  => $description,
            ]);
            return true;
        } catch (PDOException $e) {
            error_log('HostApplication::create — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a host application status (admin action). Also syncs users.role.
     * NOTE: hosts table PK is user_id — there is no separate 'id' column.
     */
    public function updateStatus(int $user_id, string $status, int $reviewed_by): bool {
        $allowed = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $allowed, true)) return false;

        try {
            $this->conn->beginTransaction();

            // 1. Update the hosts table (PK = user_id)
            $stmt = $this->conn->prepare(
                "UPDATE hosts SET status = :status, reviewed_by = :rev, updated_at = NOW() WHERE user_id = :uid"
            );
            $stmt->execute([':status' => $status, ':rev' => $reviewed_by, ':uid' => $user_id]);

            // 2. Sync the users.role — 'host' when approved, 'user' otherwise
            $newRole = ($status === 'approved') ? 'host' : 'user';
            $stmt2 = $this->conn->prepare(
                "UPDATE users SET role = :role WHERE id = :uid"
            );
            $stmt2->execute([':role' => $newRole, ':uid' => $user_id]);

            $this->conn->commit();
            return true;
        } catch (\PDOException $e) {
            $this->conn->rollBack();
            error_log('HostApplication::updateStatus — ' . $e->getMessage());
            return false;
        }
    }

    /** All applications for admin view. */
    public function getAll(): array {
        $stmt = $this->conn->prepare(
            "SELECT h.*, u.full_name, u.email
             FROM hosts h
             JOIN users u ON h.user_id = u.id
             ORDER BY h.created_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Only pending applications. */
    public function getPending(): array {
        $stmt = $this->conn->prepare(
            "SELECT h.*, u.full_name, u.email
             FROM hosts h
             JOIN users u ON h.user_id = u.id
             WHERE h.status = 'pending'
             ORDER BY h.created_at ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Quick helper — is this user an approved host?
     * Used as a fast boolean check for access control.
     */
    public function isApproved(int $user_id): bool {
        $stmt = $this->conn->prepare(
            "SELECT user_id FROM hosts WHERE user_id = :uid AND status = 'approved' LIMIT 1"
        );
        $stmt->execute([':uid' => $user_id]);
        return (bool)$stmt->fetchColumn();
    }
}
