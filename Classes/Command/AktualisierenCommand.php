<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Command;

use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Erklaerung\Fassung;
use Lubomedia\Barrierepruefung\Verbindung\TresorFehler;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Gleicht die veröffentlichte Erklärung jeder verbundenen Website mit dem Dienst ab.
 *
 * Fragt am eigenen Cache vorbei (mit ETag, meist nur ein 304) und lässt eine geänderte Fassung genau die
 * Seiten aus dem Seitencache werfen, die sie zeigen. Ein Ausfall des Dienstes ist der Normalbetrieb und
 * kein Fehlercode: die letzte gute Fassung bleibt stehen. Planbar im Scheduler (Aufgabe „Konsolenbefehl
 * ausführen“) - in 13.4 wie in 14.3 gilt jeder Befehl als planbar, solange er sich nicht ausnimmt.
 */
#[AsCommand(
    name: 'barrierepruefung:aktualisieren',
    description: 'Gleicht die veröffentlichte Barrierefreiheitserklärung jeder verbundenen Website mit dem Dienst ab (empfohlen stündlich).',
    help: 'Fragt für jede Verbindung die veröffentlichte Erklärung beim Dienst ab und leert bei einer neuen Fassung genau die Seiten im Seitencache, die sie zeigen. Empfohlen stündlich, zum Beispiel als Scheduler-Aufgabe „Konsolenbefehl ausführen“. Fällt der Dienst aus, bleibt die letzte gute Fassung stehen; der Rückgabewert ist dann trotzdem 0.',
)]
final class AktualisierenCommand extends Command
{
    public function __construct(
        private readonly Verbindungen $verbindungen,
        private readonly Abruf $abruf,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $alle = $this->verbindungen->alle();
        if ($alle === []) {
            $output->writeln('Keine Verbindung eingerichtet.');

            return Command::SUCCESS;
        }

        $lesbare = 0;
        foreach ($alle as $verbindung) {
            $lesbar = $this->lesbar($verbindung);
            $lesbare += $lesbar ? 1 : 0;

            $fassung = $this->abruf->auffrischen($verbindung);
            $output->writeln($verbindung->siteIdentifier . ': Fassung ' . self::nummer($fassung) . ' (' . self::zustand($fassung, $lesbar) . ')');
        }

        // Nur wenn kein einziger Token mehr zu entschlüsseln war, ist etwas grundsätzlich kaputt (encryptionKey gewechselt).
        return $lesbare === 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function lesbar(Verbindung $verbindung): bool
    {
        try {
            $this->verbindungen->zugang($verbindung);
        } catch (TresorFehler) {
            return false;
        }

        return true;
    }

    private static function nummer(Fassung $fassung): string
    {
        $version = $fassung->erklaerung['version'] ?? null;

        return is_int($version) ? (string)$version : '-';
    }

    private static function zustand(Fassung $fassung, bool $lesbar): string
    {
        if ($fassung->zustand === Fassung::KEINE) {
            return 'keine';
        }
        if ($fassung->zustand === Fassung::RUECKFALL) {
            $grund = self::einzeilig($fassung->letzterFehler ?? 'Der Dienst hat nicht geantwortet.');

            return 'Rückfall: ' . $grund . ($lesbar ? '' : ' Verbindung neu herstellen.');
        }

        return $fassung->neu ? 'neu' : 'unverändert';
    }

    private static function einzeilig(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
