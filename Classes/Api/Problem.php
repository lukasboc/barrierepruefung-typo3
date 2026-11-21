<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Api;

/**
 * Ein Fehler des Dienstes (RFC 9457) oder ein Netzfehler, einheitlich gelesen.
 */
final readonly class Problem
{
    public const REASON_NICHT_ERREICHBAR = 'nicht_erreichbar';

    /**
     * @param array<string, mixed> $errors   Fehler je Feld, Schlüssel wie `contact` oder `services.0`
     * @param array<string, mixed> $blockers Hinderungsgründe, nach Schlüssel
     */
    public function __construct(
        public ?string $reason,
        public string $detail,
        public array $errors = [],
        public array $blockers = [],
        public ?string $url = null,
    ) {}

    /**
     * @param array<mixed> $json
     */
    public static function ausAntwort(int $status, array $json): ?Problem
    {
        if ($status < 400) {
            return null;
        }

        // Nicht-Problem-Rümpfe (`{"message": "..."}`) tragen ihren Text in `message`.
        $detail = self::text($json['detail'] ?? null) ?? self::text($json['message'] ?? null) ?? 'Der Dienst hat die Anfrage mit Status ' . $status . ' abgelehnt.';

        return new self(
            self::text($json['reason'] ?? null),
            $detail,
            self::liste($json['errors'] ?? null),
            self::liste($json['blockers'] ?? null),
            self::text($json['url'] ?? null),
        );
    }

    public static function nichtErreichbar(string $meldung): Problem
    {
        return new self(self::REASON_NICHT_ERREICHBAR, $meldung);
    }

    private static function text(mixed $wert): ?string
    {
        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function liste(mixed $wert): array
    {
        /** @var array<string, mixed> $liste */
        $liste = is_array($wert) ? $wert : [];

        return $liste;
    }
}
