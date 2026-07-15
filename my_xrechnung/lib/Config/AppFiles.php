<?php

namespace OCA\MyXRechnung\Config;

final class AppFiles {
    public const ConfigDir = 'config';

    // Read-only Templates im App-Verzeichnis config/.
    public const XmlTemplate = 'en16931.xml';
    public const OdtTemplate = 'X-Rechnung.odt';

    // Finale Arbeitsdateien in AppData. Diese Dateien werden erst beim
    // Erstellen der XML-Rechnung bzw. später beim PDF-Erzeugen aktualisiert.
    public const XmlWorkFile = 'en16931.xml';
    public const PdfWorkFile = 'X-Rechnung.pdf';

    // Persistenter Entwurfszustand in AppData. Enthält ramID, windowId,
    // createdAt und lastSeenAt.
    public const DraftFile = 'draft.json';

    // Technische flock()-Datei. Sie dient nur für kurze kritische Bereiche.
    public const DraftLockFile = 'draft.lock';
}
