<?php

namespace OCA\ShiRE\Config;

final class AppFiles {
    public const ConfigDir = 'config';

    // Read-only Templates im App-Verzeichnis config/.
    public const XmlTemplate = 'XRechnung.xml';
    public const OdtTemplate = 'XRechnung.odt';

    // Temporäre Prüf-XML in AppData; nach Annahme oder Verwerfen löschen.
    // Angenommene Rechnungen liegen separat als <Rechnungsnummer>.xml/.pdf vor.
    public const XmlWorkFile = 'tmp.xml';
    public const PdfWorkFile = 'X-Rechnung.pdf';

    // Persistenter Entwurfszustand in AppData. Enthält ramID, windowId,
    // createdAt und lastSeenAt.
    public const DraftFile = 'draft.json';

    // Persistente Rechnungsvorlage in Appdata. Neue Rechnungnummer und
    // reduziert auf einen Rechnungsposten
    public const DraftXmlFile = 'draft.xml';
}
