#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Calcule la fenêtre [firstOpen .. lastClose) des bougies **clôturées**
 * à partir d'un "now" (timestamp secondes), d'un pas en minutes et d'un limit.
 * - Aligne sur la dernière clôture
 * - Si l'appel tombe pile sur une borne (00s), on recule d'une bougie
 */
function computeWindow(int $nowTs, int $stepMinutes, int $limit): array {
    $stepSec   = $stepMinutes * 60;
    $lastClose = intdiv($nowTs, $stepSec) * $stepSec;
    if ($nowTs === $lastClose) {            // pile sur la borne -> nouvelle bougie vient d'ouvrir
        $lastClose -= $stepSec;             // recule d'une bougie pour n'avoir que des CLOSED
    }
    $firstOpen = $lastClose - ($limit * $stepSec);
    return [$firstOpen, $lastClose, $stepSec];
}

/** Helpers affichage */
function fmt(int $ts, string $tz = 'UTC'): string {
    return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s');
}
function assertTrue(bool $cond, string $msg): void {
    if (!$cond) { fwrite(STDERR, "❌ FAIL: $msg\n"); exit(1); }
}
function assertSame($got, $exp, string $msg): void {
    if ($got !== $exp) {
        fwrite(STDERR, "❌ FAIL: $msg\n  got: ".var_export($got, true)."\n  exp: ".var_export($exp, true)."\n");
        exit(1);
    }
}

/**
 * Calcule l'ensemble des "clôtures attendues" à l'intérieur d'une journée utc:
 * - On garde les clôtures >= jour 00:00:01 et <= jour+1 00:00:00 (incluse),
 *   ce qui revient, pour 4h, à: 04:00,08:00,12:00,16:00,20:00,24:00 (6 items)
 *   et pour 1h, à: 01:00..24:00 (24 items).
 */
function expectedDailyCloses(string $dateUtcYmd, int $stepMinutes): array {
    $start = (new DateTimeImmutable($dateUtcYmd.' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
    $end   = $start + 86400; // jour+1 00:00:00
    $step  = $stepMinutes * 60;

    // Première clôture >= start+1
    $firstClose = intdiv($start + 1, $step) * $step;
    if ($firstClose < $start + 1) { $firstClose += $step; }

    $out = [];
    for ($t = $firstClose; $t <= $end; $t += $step) {
        $out[] = $t;
    }
    return $out;
}

/**
 * Balaye chaque seconde d'une journée (UTC) et vérifie:
 *  - l'alignement de lastClose
 *  - l'ensemble des lastClose rencontrés dans la journée (hors valeurs < jour 00:00:00)
 *    correspond exactement aux clôtures attendues (6 pour 4h, 24 pour 1h).
 */
function runAllDay(string $label, string $dateUtcYmd, int $stepMinutes, int $limit): void {
    $base = (new DateTimeImmutable($dateUtcYmd.' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
    $end  = $base + 86400; // exclusif pour la boucle

    $stepSec = $stepMinutes * 60;
    $seen = []; // set de lastClose

    for ($ts = $base; $ts < $end; $ts++) {
        [$_first, $last, $step] = computeWindow($ts, $stepMinutes, $limit);
        // 1) Alignement
        if ($last % $stepSec !== 0) {
            throw new RuntimeException("$label :: lastClose non aligné @ ".fmt($ts)." => ".fmt($last));
        }
        // 2) Collecte sur (start, end]
        if ($last > $base && $last <= $end) {
            $seen[$last] = true;
        }
    }

    // force l'inclusion de 24:00:00
    $seen[$end] = true;

    $got = array_keys($seen);
    sort($got);
    $exp = expectedDailyCloses($dateUtcYmd, $stepMinutes);

    $expectedCount = (int) (86400 / ($stepMinutes * 60));
    if (count($got) !== $expectedCount) {
        fwrite(STDERR, "❌ FAIL: $label :: mauvais nombre de clôtures distinctes (got=".count($got).", exp=$expectedCount)\n");
        exit(1);
    }
    if ($got !== $exp) {
        fwrite(STDERR, "❌ FAIL: $label :: ensemble des clôtures inattendu\n  got: ".var_export($got, true)."\n  exp: ".var_export($exp, true)."\n");
        exit(1);
    }

    echo "✅ $label OK — ".count($got)." clôtures\n";
    foreach ($got as $t) { echo "   • ".fmt($t)." UTC\n"; }
    echo "\n";
}

/* =========================
   LANCEMENT DES TESTS
   ========================= */

$date = '2025-09-30'; // tu peux changer la date ici (UTC)

// 4h: 6 clôtures/jour
runAllDay('4h full-day sweep', $date, 240, 6);

// 1h: 24 clôtures/jour
runAllDay('1h full-day sweep', $date, 60, 24);

// 15m: 96 clôtures/jour
runAllDay('15m full-day sweep', $date, 15, 96);

// 5m: 288 clôtures/jour
runAllDay('5m full-day sweep', $date, 5, 288);

// 1m: 1440 clôtures/jour
runAllDay('1m full-day sweep', $date, 1, 1440);

echo "All tests passed ✅\n";
