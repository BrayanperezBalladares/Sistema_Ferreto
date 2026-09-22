<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Foundation\NativeSession;
use Closure;

final readonly class AuthSession
{
    public const string KEY_USER_ID = 'auth_user_id';
    public const string KEY_USERNAME = 'auth_username';
    public const string KEY_LAST_ACTIVITY = 'auth_last_activity';
    public const string KEY_TARGET_URL = 'auth_target_url';

    public const int TIMEOUT_CAJERO = 1200; // 20 minutes
    public const int DEFAULT_IDLE_TIMEOUT = 1800; // 30 minutes

    private int $nonCajeroTimeout;

    /**
     * @param (Closure(): int)|null $clock Optional clock returning Unix timestamp for testing
     */
    public function __construct(
        private NativeSession $session,
        private UserQuery $query,
        ?int $nonCajeroTimeout = null,
        private readonly ?Closure $clock = null,
    ) {
        if ($nonCajeroTimeout !== null) {
            $this->nonCajeroTimeout = $nonCajeroTimeout > 0 ? $nonCajeroTimeout : self::DEFAULT_IDLE_TIMEOUT;
        } else {
            $env = getenv('SESSION_IDLE_TIMEOUT');
            if (is_string($env) && is_numeric($env) && (int) $env > 0) {
                $this->nonCajeroTimeout = (int) $env;
            } else {
                $this->nonCajeroTimeout = self::DEFAULT_IDLE_TIMEOUT;
            }
        }
    }

    /**
     * Establishes an authenticated session for an eligible active user.
     *
     * Revalidates that the account currently exists and remains 'activo' in persistent storage
     * before regenerating the session ID and writing authentication state.
     *
     * @param int|array<string, mixed> $identity User ID or identity array
     * @return array{id_usuario: int, username: string, rol: string, estado: string}|null Fresh user context or null if ineligible
     */
    public function establish(int|array $identity): ?array
    {
        if (is_array($identity)) {
            $rawId = $identity['id_usuario'] ?? null;
            $userId = is_int($rawId) || is_numeric($rawId) ? (int) $rawId : 0;
        } else {
            $userId = $identity;
        }
        if ($userId <= 0) {
            return null;
        }

        $user = $this->query->findById($userId);
        if ($user === null || $user['estado'] !== 'activo') {
            return null;
        }

        // Session fixation protection: regenerate ID before associating auth state
        $this->session->regenerate();

        $now = $this->currentTime();
        $this->session->set(self::KEY_USER_ID, $user['id_usuario']);
        $this->session->set(self::KEY_USERNAME, $user['username']);
        $this->session->set(self::KEY_LAST_ACTIVITY, $now);

        return [
            'id_usuario' => $user['id_usuario'],
            'username'   => $user['username'],
            'rol'        => $user['rol'],
            'estado'     => $user['estado'],
        ];
    }

    /**
     * Alias for establish().
     *
     * @param int|array<string, mixed> $identity
     * @return array{id_usuario: int, username: string, rol: string, estado: string}|null
     */
    public function login(int|array $identity): ?array
    {
        return $this->establish($identity);
    }

    /**
     * Resolves and revalidates the current authenticated user on protected requests.
     *
     * Enforces:
     * 1. Session presence of auth_user_id.
     * 2. Persistent account existence and 'activo' state (stale sessions are completely invalidated).
     * 3. Role-aware inactivity expiration:
     *    - 'cajero': 1200 seconds (20 minutes) mandatory.
     *    - others: configured timeout (default 1800 seconds / 30 minutes).
     * 4. Activity refresh on valid interaction.
     *
     * @return array{id_usuario: int, username: string, rol: string, estado: string}|null
     */
    public function user(): ?array
    {
        $userId = $this->session->get(self::KEY_USER_ID);
        if (!is_int($userId) && (!is_string($userId) || !ctype_digit($userId))) {
            return null;
        }
        $userId = (int) $userId;
        if ($userId <= 0) {
            $this->invalidate();
            return null;
        }

        $user = $this->query->findById($userId);
        if ($user === null || $user['estado'] !== 'activo') {
            $this->invalidate();
            return null;
        }

        $lastActivity = $this->session->get(self::KEY_LAST_ACTIVITY);
        if (!is_int($lastActivity) && (!is_string($lastActivity) || !ctype_digit($lastActivity))) {
            $this->invalidate();
            return null;
        }
        $lastActivity = (int) $lastActivity;

        // Role-aware timeout governed by the FRESH persistent role
        $timeout = $user['rol'] === 'cajero'
            ? self::TIMEOUT_CAJERO
            : $this->nonCajeroTimeout;

        $now = $this->currentTime();
        if (($now - $lastActivity) > $timeout) {
            $this->invalidate();
            return null;
        }

        // Valid interaction: refresh last activity
        $this->session->set(self::KEY_LAST_ACTIVITY, $now);

        return [
            'id_usuario' => $user['id_usuario'],
            'username'   => $user['username'],
            'rol'        => $user['rol'],
            'estado'     => $user['estado'],
        ];
    }

    /**
     * Alias for user().
     *
     * @return array{id_usuario: int, username: string, rol: string, estado: string}|null
     */
    public function getCurrentUser(): ?array
    {
        return $this->user();
    }

    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    public function invalidate(): void
    {
        $this->session->destroy();
    }

    public function logout(): void
    {
        $this->invalidate();
    }

    public function getTargetUrl(): ?string
    {
        $url = $this->session->get(self::KEY_TARGET_URL);
        return is_string($url) ? $url : null;
    }

    public function setTargetUrl(string $url): void
    {
        $this->session->set(self::KEY_TARGET_URL, $url);
    }

    public function clearTargetUrl(): void
    {
        $this->session->remove(self::KEY_TARGET_URL);
    }

    public function pullTargetUrl(): ?string
    {
        $url = $this->getTargetUrl();
        $this->clearTargetUrl();
        return $url;
    }

    private function currentTime(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
