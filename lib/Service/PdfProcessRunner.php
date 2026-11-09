<?php
declare(strict_types=1);

namespace OCA\MyXRechnung\Service;

/** Argumentlisten umgehen die Shell; begrenzte Ausgabe und Laufzeit für jeden Prozess. */
class PdfProcessRunner {
    public function run(array $command, string $cwd, int $timeout = 60): string {
        $environment = getenv();
        $environment['GSETTINGS_BACKEND'] = 'memory';
        $environment['SAL_USE_VCLPLUGIN'] = 'svp';
        $environment['XDG_CACHE_HOME'] = $cwd . '/cache';
        $environment['XDG_CONFIG_HOME'] = $cwd . '/xdg';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('PDF-Werkzeug konnte nicht gestartet werden.');
        }
        fclose($pipes[0]); // Keine interaktiven Rückfragen zulassen.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $start = microtime(true);
        try {
            do {
                foreach ([1, 2] as $i) {
                    $output .= stream_get_contents($pipes[$i]);
                }
                if (strlen($output) > 1048576) {
                    throw new \RuntimeException('PDF-Werkzeug erzeugt zu viel Ausgabe.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) - $start > $timeout) {
                    throw new \RuntimeException('Zeitlimit für PDF-Werkzeug überschritten.');
                }
                usleep(20000);
            } while (true);
            foreach ([1, 2] as $i) $output .= stream_get_contents($pipes[$i]);
            if ($status['exitcode'] !== 0) {
                throw new \RuntimeException('PDF-Werkzeug fehlgeschlagen: ' . mb_substr($output, 0, 2000));
            }
            return $output;
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
