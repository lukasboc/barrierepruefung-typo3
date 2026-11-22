<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Erklaerung;

/**
 * Die Erklärung, wie die Website sie gerade zeigen kann.
 *
 * `aktuell`: frisch vom Dienst (oder von ihm per 304 bestätigt). `rueckfall`: der Dienst war nicht zu
 * erreichen, es gilt die letzte gute Fassung mit ihrem eigenen Datum. `keine`: es gibt noch keine gute Fassung.
 */
final readonly class Fassung
{
    public const AKTUELL = 'aktuell';
    public const RUECKFALL = 'rueckfall';
    public const KEINE = 'keine';

    /**
     * @param array<string, mixed>|null $erklaerung `data.declaration` der API
     */
    public function __construct(
        public ?array $erklaerung,
        public string $zustand,
        public ?int $abgerufenAm = null,
        public ?int $letzterFehlerAm = null,
        public ?string $letzterFehler = null,
    ) {}

    /**
     * @return array{erklaerung: array<string, mixed>|null, zustand: string, abgerufen_am: int|null, letzter_fehler_am: int|null, letzter_fehler: string|null}
     */
    public function alsArray(): array
    {
        return [
            'erklaerung' => $this->erklaerung,
            'zustand' => $this->zustand,
            'abgerufen_am' => $this->abgerufenAm,
            'letzter_fehler_am' => $this->letzterFehlerAm,
            'letzter_fehler' => $this->letzterFehler,
        ];
    }

    /**
     * @param array<mixed> $daten
     */
    public static function ausArray(array $daten): self
    {
        $erklaerung = is_array($daten['erklaerung'] ?? null) ? $daten['erklaerung'] : null;
        $zustand = $daten['zustand'] ?? null;
        if (!in_array($zustand, [self::AKTUELL, self::RUECKFALL, self::KEINE], true)) {
            $zustand = $erklaerung === null ? self::KEINE : self::RUECKFALL;
        }

        /** @var array<string, mixed>|null $erklaerung */
        return new self(
            $erklaerung,
            $zustand,
            self::zahl($daten['abgerufen_am'] ?? null),
            self::zahl($daten['letzter_fehler_am'] ?? null),
            is_string($daten['letzter_fehler'] ?? null) ? $daten['letzter_fehler'] : null,
        );
    }

    private static function zahl(mixed $wert): ?int
    {
        return is_int($wert) ? $wert : null;
    }
}
