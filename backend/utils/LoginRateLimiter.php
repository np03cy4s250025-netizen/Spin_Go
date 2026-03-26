<?php
/**
 * backend/utils/LoginRateLimiter.php
 *
 * Database-backed rate limiter for login attempts.
 * Replaces session-based rateLimit('login', ...) for the login route.
 *
 * Usage:
 *   $limiter = new LoginRateLimiter($conn);
 *   if (!$limiter->isAllowed($email, $ip)) { // blocked }
 *   $limiter->recordAttempt($email, $ip);
 *   $limiter->clearAttempts($email, $ip);   // on success
 */

class LoginRateLimiter {
    private PDO $conn;

    /** Maximum attempts before lockout */
    private int $maxAttempts;

    /** Lockout window in seconds */
    private int $windowSeconds;

    public function __construct(PDO $conn, int $maxAttempts = 10, int $windowSeconds = 300) {
        $this->conn          = $conn;
        $this->maxAttempts   = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Returns TRUE if the email+IP combination is still within the allowed limit.
     */
    public function isAllowed(string $email, string $ip): bool {
        $since = date('Y-m-d H:i:s', time() - $this->windowSeconds);

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE (email = :email OR ip_address = :ip)
             AND attempted_at >= :since"
        );
        $stmt->execute([':email' => $email, ':ip' => $ip, ':since' => $since]);
        $count = (int)$stmt->fetchColumn();

        return $count < $this->maxAttempts;
    }

    /**
     * Records a failed login attempt.
     */
    public function recordAttempt(string $email, string $ip): void {
        $stmt = $this->conn->prepare(
            "INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)"
        );
        $stmt->execute([':email' => $email, ':ip' => $ip]);

        // Prune old records to keep the table lean (older than 24 h)
        $this->conn->exec(
            "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }

    /**
     * Clears attempts after a successful login.
     */
    public function clearAttempts(string $email, string $ip): void {
        $stmt = $this->conn->prepare(
            "DELETE FROM login_attempts WHERE email = :email AND ip_address = :ip"
        );
        $stmt->execute([':email' => $email, ':ip' => $ip]);
    }
}
