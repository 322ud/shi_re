<?php

namespace OCA\MyXRechnung\Config;

final class AppFiles {
    public const ConfigDir = 'config';

    // read-only templates in app config/
    public const XmlTemplate = 'en16931.xml';
    public const OdtTemplate = 'X-Rechnung.odt';

    // writable files in AppData
    public const XmlWorkFile = 'en16931.xml';
    public const PdfWorkFile = 'X-Rechnung.pdf';
}
