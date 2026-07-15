<?php

namespace OCA\MyXRechnung\Service;

class KositValidatorService {

    private const ValidatorAppPath = '/opt/kosit';

    public function validateXml(string $xml): array {
        if (!is_dir(self::ValidatorAppPath)) {
            throw new \RuntimeException(
                'KoSIT-Verzeichnis wurde nicht gefunden: ' . self::ValidatorAppPath
            );
        }

        $workDir = sys_get_temp_dir() . '/my_xrechnung_validator_' . bin2hex(random_bytes(8));

        if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) {
            throw new \RuntimeException('Temporäres Validator-Verzeichnis konnte nicht erstellt werden.');
        }

        $xmlFile = $workDir . '/invoice.xml';

        file_put_contents($xmlFile, $xml);

        $command = $this->buildCommand($xmlFile, $workDir);

        $output = [];
        $exitCode = 0;

        exec($command . ' 2>&1', $output, $exitCode);

        // erzeugte HTML-Datei suchen
        if ($reports && is_readable($reports[0])) {
            $html = file_get_contents($reports[0]);
        } else {
            $html = '<pre>' .
            htmlspecialchars(
                implode("\n", $output),
                             ENT_QUOTES | ENT_SUBSTITUTE,
                             'UTF-8'
            ) .
            '</pre>';
        }

        $this->deleteDirectory($workDir);

        return [
            'valid' => $exitCode === 0,
            'html' => $html ?: '<p>Keine Validator-Ausgabe vorhanden.</p>',
        ];
    }

    private function buildCommand(string $xmlFile, string $outputDir): string {
        $jars = glob(self::ValidatorAppPath . '/validator-*-standalone.jar');

        if (!$jars) {
            throw new \RuntimeException(
                'Kein validator-*-standalone.jar unter ' .
                self::ValidatorAppPath .
                ' gefunden.'
            );
        }

        sort($jars);
        $jar = end($jars);

        $scenario = self::ValidatorAppPath . '/scenarios.xml';

        if (!is_file($scenario)) {
            throw new \RuntimeException(
                'scenarios.xml nicht gefunden: ' . $scenario
            );
        }

        return sprintf(
            'java -jar %s --scenarios %s --output-directory %s --html %s',
            escapeshellarg($jar),
            escapeshellarg($scenario),
            escapeshellarg($outputDir),
            escapeshellarg($xmlFile)
        );
    }

    private function findHtmlReports(string $workDir): array {
        $reports = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $workDir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'html') {
                $reports[] = $file->getPathname();
            }
        }

        sort($reports);

        return $reports;
    }

    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
