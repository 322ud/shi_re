<?php

declare(strict_types=1);

namespace OCA\MyXRechnung\Service;

/** Liest nur reguläre TAR-Dateien/Verzeichnisse; entpackt niemals Links. */
final class KositReleaseArchive {
    public static function unpack(string $archive, string $destination, string $version): void {
        $stream = gzopen($archive, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Release-Archiv nicht lesbar.');
        }
        $count = 0;
        $total = 0;
        $seen = [];
        try {
            while (true) {
                $header = self::read($stream, 512);
                if ($header === str_repeat("\0", 512)) {
                    break;
                }
                if (++$count > 10000) {
                    throw new \RuntimeException('Zu viele Archiveinträge.');
                }
                $checksum = octdec(trim(substr($header, 148, 8), " \0"));
                $checked = substr_replace($header, str_repeat(' ', 8), 148, 8);
                if (array_sum(unpack('C*', $checked)) !== $checksum) {
                    throw new \RuntimeException('Ungültige TAR-Prüfsumme.');
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
                $name = rtrim($name, '/');
                $sizeText = trim(substr($header, 124, 12), " \0");
                if (!preg_match('/^[0-7]+$/D', $sizeText)) {
                    throw new \RuntimeException('Ungültige TAR-Größe.');
                }
                $size = (int)octdec($sizeText);
                $total += $size;
                $type = $header[156];
                if (!in_array($type, ["\0", '0', '5'], true)
                    || rtrim(substr($header, 157, 100), "\0") !== '') {
                    throw new \RuntimeException('Release enthält Links, Sonderdateien oder nicht unterstützte TAR-Metadaten.');
                }
                if ($total > 200 * 1024 * 1024 || $size > 20 * 1024 * 1024
                    || !preg_match('~^inv_validator(?:/[A-Za-z0-9_.+-]+)*$~D', $name)
                    || in_array('..', explode('/', $name), true) || in_array('.', explode('/', $name), true)
                    || isset($seen[$name])) {
                    throw new \RuntimeException('Ungültiger oder zu großer Archiveintrag.');
                }
                $seen[$name] = true;
                $path = $destination . '/' . $name;
                if ($type === '5') {
                    if ($size !== 0 || (!is_dir($path) && !mkdir($path, 0755, true))) {
                        throw new \RuntimeException('Ungültiges Archivverzeichnis.');
                    }
                } else {
                    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
                        throw new \RuntimeException('Archivverzeichnis nicht erstellbar.');
                    }
                    $content = self::read($stream, $size);
                    if (file_put_contents($path, $content) !== $size || !chmod($path, 0644)) {
                        throw new \RuntimeException('Release-Datei konnte nicht geschrieben werden.');
                    }
                    $padding = (512 - ($size % 512)) % 512;
                    self::read($stream, $padding);
                }
            }
        } finally {
            gzclose($stream);
        }
        $info = $destination . '/inv_validator/appinfo/info.xml';
        $xml = is_file($info) ? file_get_contents($info) : false;
        if ($xml === false || stripos($xml, '<!DOCTYPE') !== false) {
            throw new \RuntimeException('App-Metadaten fehlen oder sind ungültig.');
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)
            || $dom->documentElement?->nodeName !== 'info') {
            throw new \RuntimeException('App-Metadaten sind ungültig.');
        }
        $xpath = new \DOMXPath($dom);
        if ($xpath->evaluate('string(/info/id)') !== 'inv_validator'
            || $xpath->evaluate('string(/info/version)') !== $version) {
            throw new \RuntimeException('App-ID oder Release-Version stimmt nicht überein.');
        }
        foreach (['lib/AppInfo/Application.php', 'lib/Service/KoSITValidatorService.php'] as $required) {
            if (!is_file($destination . '/inv_validator/' . $required)) {
                throw new \RuntimeException('Release-Paket ist unvollständig.');
            }
        }
    }

    private static function read($stream, int $size): string {
        $result = '';
        while (strlen($result) < $size) {
            $chunk = gzread($stream, min(65536, $size - strlen($result)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Release-Archiv ist abgeschnitten.');
            }
            $result .= $chunk;
        }
        return $result;
    }
}
