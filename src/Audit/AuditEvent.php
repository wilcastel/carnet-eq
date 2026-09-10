<?php

declare(strict_types=1);

namespace CarnetEquidad\Audit;

/**
 * A single structured audit record, ready to be mapped to a {@code wp_carnet_audit}
 * row by {@see AuditLogger}.
 *
 * Habeas Data / Ley 1581: this object is a plain data holder with a fixed, small
 * set of fields. It never carries insured/beneficiary names, the full upstream
 * payload, the bearer token, or the API credentials — and it must stay that way.
 *
 * {@code created_at} is intentionally NOT a field here: it is stamped by the
 * logger from its injected clock so every row shares one UTC source of truth.
 */
final class AuditEvent
{
    public const RESULT_FOUND = 'found';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_ERROR = 'error';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_RATE_LIMITED = 'rate_limited';

    public const TYPE_QUERY = 'query';
    public const TYPE_TOKEN_REFRESH = 'token_refresh';
    public const TYPE_AUTH_ERROR = 'auth_error';

    private const RESULTS = [
        self::RESULT_FOUND,
        self::RESULT_NOT_FOUND,
        self::RESULT_ERROR,
        self::RESULT_INVALID,
        self::RESULT_RATE_LIMITED,
    ];

    private const EVENT_TYPES = [
        self::TYPE_QUERY,
        self::TYPE_TOKEN_REFRESH,
        self::TYPE_AUTH_ERROR,
    ];

    /** Hard cap on {@see self::$detail}; the column is VARCHAR(255). */
    private const DETAIL_MAX = 255;

    public readonly ?string $detail;

    public function __construct(
        public readonly string $requestId,
        public readonly string $ip,
        public readonly string $cedula,
        public readonly string $codPla,
        public readonly string $eventType,
        public readonly ?string $result = null,
        public readonly ?int $apiHttp = null,
        ?string $detail = null,
    ) {
        if (!\in_array($this->eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown audit event type "%s".', $this->eventType));
        }

        if ($this->result !== null && !\in_array($this->result, self::RESULTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown audit result "%s".', $this->result));
        }

        $this->detail = self::clampDetail($detail);
    }

    /**
     * A completed (or rejected-before-upstream) consulta.
     *
     * @param self::RESULT_* $result
     */
    public static function query(
        string $requestId,
        string $ip,
        string $cedula,
        string $codPla,
        string $result,
        ?int $apiHttp = null,
        ?string $detail = null,
    ): self {
        return new self($requestId, $ip, $cedula, $codPla, self::TYPE_QUERY, $result, $apiHttp, $detail);
    }

    /**
     * The upstream token was refreshed after a 401 (§6: "errores 401 y refrescos
     * de token").
     */
    public static function tokenRefresh(
        string $requestId,
        string $ip,
        string $cedula,
        string $codPla,
        ?string $detail = null,
    ): self {
        return new self($requestId, $ip, $cedula, $codPla, self::TYPE_TOKEN_REFRESH, null, null, $detail);
    }

    /**
     * Authentication with the upstream still failed after a token refresh + retry.
     */
    public static function authError(
        string $requestId,
        string $ip,
        string $cedula,
        string $codPla,
        ?string $detail = null,
    ): self {
        return new self($requestId, $ip, $cedula, $codPla, self::TYPE_AUTH_ERROR, null, null, $detail);
    }

    private static function clampDetail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $detail = trim($detail);

        if ($detail === '') {
            return null;
        }

        return \strlen($detail) > self::DETAIL_MAX ? substr($detail, 0, self::DETAIL_MAX) : $detail;
    }
}
