<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

/**
 * Ein gespeicherter Wert lässt sich nicht entschlüsseln: meist wurde der encryptionKey der Installation gewechselt.
 */
final class TresorFehler extends \RuntimeException {}
