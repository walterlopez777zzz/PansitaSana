<?php
/**
 * ============================================================================
 * BANANO STORE — DAILY SPIN ELIGIBILITY (server side)
 * ============================================================================
 * Single source of truth for WHO may play the Giro Diario.
 *
 *   - Policy `verified`: only accounts with `users.is_verified = 1`.
 *   - Policy `all`:      any authenticated account.
 *
 * The policy is global (not per business day) and lives in `site_settings`
 * under `daily_spin_eligibility`. Anything unknown, missing or malformed falls
 * back to `verified`, which is the historical behaviour of the game, so a
 * broken row can never open the game to more users than intended.
 *
 * Public endpoints read the policy once per request (`load`) and evaluate it
 * against the user row (`evaluate`). `spin_go.php` is the authority: the
 * frontend only mirrors the result exposed by `spin_init.php`.
 * ============================================================================
 */

if (!class_exists('DailySpinEligibility')) {

final class DailySpinEligibility
{
    public const POLICY_VERIFIED = 'verified';
    public const POLICY_ALL      = 'all';

    public const DEFAULT_POLICY = self::POLICY_VERIFIED;

    public const SETTING_KEY = 'daily_spin_eligibility';

    /** Stable machine-readable reason returned when the user cannot play. */
    public const REASON_UNVERIFIED = 'account_not_verified';

    public const MESSAGE_UNVERIFIED = 'Debes verificar tu cuenta para girar.';

    /** policy => label shown by the admin panel. */
    private const LABELS = [
        self::POLICY_VERIFIED => 'Solo cuentas verificadas',
        self::POLICY_ALL      => 'Cuentas en general (cualquier sesión iniciada)',
    ];

    /** @return list<string> */
    public static function policies(): array
    {
        return [self::POLICY_VERIFIED, self::POLICY_ALL];
    }

    public static function isValidPolicy(?string $policy): bool
    {
        return $policy !== null && in_array($policy, self::policies(), true);
    }

    /** Coerces any input to a valid policy (defaults to the restrictive one). */
    public static function normalize(?string $policy): string
    {
        $policy = strtolower(trim((string)$policy));
        return self::isValidPolicy($policy) ? $policy : self::DEFAULT_POLICY;
    }

    public static function label(string $policy): string
    {
        return self::LABELS[self::normalize($policy)];
    }

    /**
     * Current policy. Never throws: a missing table/row/prepare failure yields
     * the default so the game keeps working with its historical rule.
     */
    public static function load(mysqli $conn): string
    {
        $stmt = $conn->prepare("SELECT value FROM site_settings WHERE key_name = ? LIMIT 1");
        if (!$stmt) return self::DEFAULT_POLICY;

        $key = self::SETTING_KEY;
        $stmt->bind_param('s', $key);
        $value = null;
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $value = $row ? (string)$row['value'] : null;
        }
        $stmt->close();

        return self::normalize($value);
    }

    /**
     * Persists a policy. Strict: rejects anything outside `policies()` instead
     * of silently coercing, so the admin gets an explicit error.
     *
     * @throws InvalidArgumentException on an unknown policy
     * @throws RuntimeException when the write fails
     */
    public static function save(mysqli $conn, string $policy): string
    {
        $policy = strtolower(trim($policy));
        if (!self::isValidPolicy($policy)) {
            throw new InvalidArgumentException('Política de elegibilidad no válida.');
        }

        $stmt = $conn->prepare(
            "INSERT INTO site_settings (key_name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar el guardado de la elegibilidad.');
        }
        $key = self::SETTING_KEY;
        $stmt->bind_param('ss', $key, $policy);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new RuntimeException('No se pudo guardar la elegibilidad del Giro Diario.');
        }
        return $policy;
    }

    /**
     * Evaluates a user row against a policy.
     *
     * @param array{is_verified?:mixed} $user Row from `users` (only `is_verified` is read).
     * @return array{policy:string, eligible:bool, reason:?string, message:?string}
     */
    public static function evaluate(string $policy, array $user): array
    {
        $policy = self::normalize($policy);
        $verified = (int)($user['is_verified'] ?? 0) === 1;

        $eligible = $policy === self::POLICY_ALL || $verified;

        return [
            'policy'   => $policy,
            'eligible' => $eligible,
            'reason'   => $eligible ? null : self::REASON_UNVERIFIED,
            'message'  => $eligible ? null : self::MESSAGE_UNVERIFIED,
        ];
    }

    /**
     * Shape exposed to public clients (`spin_init`), without the human message
     * so the frontend keeps owning its own copy.
     *
     * @param array{policy:string, eligible:bool, reason:?string} $evaluation
     * @return array{policy:string, eligible:bool, reason:?string}
     */
    public static function publicShape(array $evaluation): array
    {
        return [
            'policy'   => $evaluation['policy'],
            'eligible' => $evaluation['eligible'],
            'reason'   => $evaluation['reason'],
        ];
    }
}

}
