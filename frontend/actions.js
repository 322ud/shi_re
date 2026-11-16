import Vue from 'vue'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'

window.ShiRE.mountReviewActions = function (element, valid, choose, report, options = {}) {
    const appName = 'ShiRE'
    const vm = new Vue({
        data: () => ({busy: false}),
        render(h) {
            const action = (label, choice) => h(NcActionButton, {
                props: {disabled: this.busy || (options.createDisabled && !choice.startsWith('cancel-'))}, on: {click: () => choose(choice)},
            }, [label])
            const menu = (label, children) => h(NcActions, {
                props: {menuName: label, forceMenu: true, disabled: this.busy},
            }, children)
            return h('div', {class: 'myx-review-actions'}, [
                valid ? menu(options.unvalidated ? 'Rechnung erstellen' : 'XML annehmen', [
                    (!options.unvalidated || options.pdfEnabled) ? action(options.unvalidated ? 'PDF-Rechnung erstellen' : 'in PDF einbinden', 'pdf') : null,
                    action((options.unvalidated ? 'XML speichern und ' : 'speichern und ') + appName + ' schließen', 'accept-close'),
                    action(options.unvalidated ? 'XML speichern und neue Rechnung starten' : 'speichern und neue Rechnung starten', 'accept-new'),
                ]) : null,
                menu('abbrechen', [
                    action(appName + ' schließen', 'cancel-close'),
                    action('neue Rechnung starten', 'cancel-new'),
                ]),
                report ? h(NcButton, {props: {disabled: this.busy}, on: {click: report}}, ['Bericht als PDF speichern']) : null,
            ])
        },
    }).$mount(element)
    return {setBusy: value => { vm.busy = value }, destroy: () => { vm.$destroy(); vm.$el.remove() }}
}

window.ShiRE.mountFinishedActions = function (element, start, close, savePdf, report) {
    return new Vue({
        render(h) {
            return h('div', {class: 'myx-review-actions'}, [
                savePdf ? h(NcButton, {on: {click: savePdf}}, ['PDF-Rechnung speichern']) : null,
                report ? h(NcButton, {on: {click: report}}, ['Bericht als PDF speichern']) : null,
                h(NcButton, {on: {click: start}}, ['neue Rechnung starten']),
                h(NcButton, {on: {click: close}}, ['ShiRE schließen']),
            ])
        },
    }).$mount(element)
}
