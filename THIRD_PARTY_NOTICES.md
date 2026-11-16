# Lizenzen und Fremdkomponenten

## ShiRE

ShiRE ist gemäß `appinfo/info.xml` unter **GNU AGPL, Version 3 oder später**
(`AGPL-3.0-or-later`) lizenziert. Der vollständige Lizenztext steht in [LICENSE](LICENSE).
Diese Angabe gilt für den eigenen Projektcode; enthaltene Fremdkomponenten behalten
ihre unten aufgeführten Lizenzen und Copyright-Hinweise.

## Gebündeltes Frontend

Die folgende Liste wurde aus den esbuild-Eingaben für `frontend/actions.js` und
`package-lock.json` ermittelt. Die Komponenten fließen in
`js/validation-actions.js` bzw. `css/validation-actions.css` ein. Die kopierten
Lizenztexte stammen aus den installierten Paketen; fehlende Texte wurden aus
Upstream-Quellen ergänzt. Die Lizenzkommentare im generierten JavaScript bleiben erhalten.

| Komponente | Version | Lizenz | Text | Quellprojekt |
| --- | --- | --- | --- | --- |
| `@floating-ui/core` | 0.3.1 | MIT | [Lizenztext](LICENSES/floating-ui-core.txt) | https://github.com/floating-ui/floating-ui |
| `@floating-ui/dom` | 0.1.10 | MIT | [Lizenztext](LICENSES/floating-ui-dom.txt) | https://github.com/floating-ui/floating-ui |
| `@nextcloud/auth` | 2.6.0 | GPL-3.0-or-later | [Lizenztext](LICENSES/nextcloud-auth.txt) | https://github.com/nextcloud-libraries/nextcloud-auth |
| `@nextcloud/browser-storage` | 0.5.0 | GPL-3.0-or-later | [Lizenztext](LICENSES/nextcloud-browser-storage.txt) | https://github.com/nextcloud-libraries/nextcloud-browser-storage |
| `@nextcloud/event-bus` | 3.3.3 | GPL-3.0-or-later | [Lizenztext](LICENSES/GPL-3.0.txt) | https://github.com/nextcloud/nextcloud-event-bus |
| `@nextcloud/l10n` | 3.4.1 | GPL-3.0-or-later | [Lizenztext](LICENSES/nextcloud-l10n.txt) | https://github.com/nextcloud-libraries/nextcloud-l10n |
| `@nextcloud/logger` | 3.0.3 | GPL-3.0-or-later | [Lizenztext](LICENSES/nextcloud-logger.txt) | https://github.com/nextcloud-libraries/nextcloud-logger |
| `@nextcloud/router` | 3.2.0 | GPL-3.0-or-later | [Lizenztext](LICENSES/nextcloud-router.txt) | https://github.com/nextcloud-libraries/nextcloud-router |
| `@nextcloud/vue` | 8.42.0 | AGPL-3.0-or-later | [Lizenztext](LICENSE) | https://github.com/nextcloud-libraries/nextcloud-vue |
| `@vueuse/core` | 11.3.0 | MIT | [Lizenztext](LICENSES/vueuse-core.txt) | https://github.com/vueuse/vueuse |
| `@vueuse/shared` | 11.3.0 | MIT | [Lizenztext](LICENSES/vueuse-shared.txt) | https://github.com/vueuse/vueuse |
| `dompurify` | 3.4.17 | (MPL-2.0 OR Apache-2.0) | [Lizenztext](LICENSES/dompurify.txt) | git://github.com/cure53/DOMPurify |
| `escape-html` | 1.0.3 | MIT | [Lizenztext](LICENSES/escape-html.txt) | component/escape-html |
| `floating-vue` | 1.0.0-beta.19 | MIT | [Lizenztext](LICENSES/floating-vue.txt) | https://github.com/Akryum/floating-vue |
| `focus-trap` | 7.8.0 | MIT | [Lizenztext](LICENSES/focus-trap.txt) | https://github.com/focus-trap/focus-trap |
| `semver` | 7.8.6 | ISC | [Lizenztext](LICENSES/semver.txt) | https://github.com/npm/node-semver |
| `tabbable` | 6.5.1 | MIT | [Lizenztext](LICENSES/tabbable.txt) | https://github.com/focus-trap/tabbable |
| `vue` | 2.7.16 | MIT | [Lizenztext](LICENSES/vue.txt) | https://github.com/vuejs/vue |
| `vue-demi` | 0.14.10 | MIT | [Lizenztext](LICENSES/vue-demi.txt) | https://github.com/antfu/vue-demi |

Für DOMPurify sind sowohl der Apache-2.0-Text im Paket als auch
[MPL-2.0](LICENSES/MPL-2.0.txt) enthalten; upstream bietet die Wahl zwischen beiden
Lizenzen. Copyright: Cure53 and other contributors.

Die Nextcloud-Komponenten tragen Copyright-Hinweise von Nextcloud GmbH und
Nextcloud contributors (teilweise zusätzlich ownCloud). Ihre ursprünglichen
SPDX-Hinweise befinden sich in den veröffentlichten Paketquellen; die vollständigen
GPL-/AGPL-Texte sind oben verlinkt.

## Bereits in Abhängigkeiten eingebettete Bestandteile

Die Source Maps von `@nextcloud/vue` weisen zusätzlich `@mdi/js/mdi.js` und
`vue-material-design-icons/DotsHorizontal.vue` aus. Diese werden von esbuild nicht
als eigenständige npm-Pakete aufgelöst; ihre exakten Versionen sind aus dem
ShiRE-Lockfile nicht bestimmbar.

- Material Design Icons / Pictogrammers: Icons unter [Apache-2.0](LICENSES/Apache-2.0.txt),
  mit dem mitgelieferten [Upstream-Lizenzhinweis](LICENSES/material-design-icons.txt),
  gemäß der [Upstream-Lizenz](https://github.com/Templarian/MaterialDesign/blob/master/LICENSE).
- `vue-material-design-icons`: [MIT-Lizenz mit Copyright-Hinweis](LICENSES/vue-material-design-icons.txt),
  übernommen aus https://github.com/robcresswell/vue-material-design-icons/blob/master/LICENSE.
- DOMPurify enthält einen regenerator-Helfer. Der Bundle-Hinweis nennt
  Copyright (c) 2014-present, Facebook, Inc.; der vollständige
  [MIT-Text für Babel-Helfer](LICENSES/babel-helpers.txt) stammt aus
  https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE.

Die fehlenden Floating-UI-Lizenzdateien stammen aus dem zum eingebundenen
`@floating-ui/core` 0.3.1 gehörenden Tag (mit dem DOM-Tag 0.1.10 abgeglichen):
https://github.com/floating-ui/floating-ui/blob/%40floating-ui/core%400.3.1/LICENSE.

## Extern installierte Programme

LibreOffice, Java, Mustang-CLI einschließlich PDFBox, veraPDF und INValidator
werden nicht in diesem Release-Paket mitgeliefert. Die separat installierten
Programme enthalten ihre eigenen Lizenzinformationen. Der eigene Java-Helfer
`tools/pdf-metadata/ShiRePdfMetadata.java` und seine kompilierte Klasse werden
mitgeliefert; PDFBox-Klassen werden nicht in diese Klasse kopiert.

## Pflege und Auslieferung

Bei Änderungen an `package-lock.json`, den Frontend-Imports oder vorgebündelten
Abhängigkeiten diese Liste anhand eines neuen esbuild-Metafiles und der
Upstream-Lizenzhinweise prüfen. Reine Build- und Testwerkzeuge wie esbuild und
Playwright werden nicht mit dem App-Paket ausgeliefert.

Das Release-Skript berücksichtigt `LICENSE`, `LICENSES/` und diese Datei in
seiner Positivliste. Wie alle neuen Paketdateien müssen sie vor dem Release
von Git erfasst sein (`git add LICENSE LICENSES THIRD_PARTY_NOTICES.md`).
