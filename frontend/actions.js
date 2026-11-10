import Vue from 'vue'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'

window.MyXRechnung.mountReviewActions = function (element, valid, choose, report) {
    const appName = 'meine X-Rechnung'
    const vm = new Vue({
        data: () => ({busy: false}),
        render(h) {
            const action = (label, choice) => h(NcActionButton, {
                props: {disabled: this.busy}, on: {click: () => choose(choice)},
            }, [label])
            const menu = (label, children) => h(NcActions, {
                props: {menuName: label, forceMenu: true, disabled: this.busy},
            }, children)
            return h('div', {class: 'myx-review-actions'}, [
                valid ? menu('XML annehmen', [
                    action('in PDF einbinden', 'pdf'),
                    action('speichern und ' + appName + ' schließen', 'accept-close'),
                    action('speichern und neue Rechnung starten', 'accept-new'),
                ]) : null,
                menu('abbrechen', [
                    action(appName + ' schließen', 'cancel-close'),
                    action('neue Rechnung starten', 'cancel-new'),
                ]),
                h(NcButton, {props: {disabled: this.busy}, on: {click: report}}, ['Bericht als PDF speichern']),
            ])
        },
    }).$mount(element)
    return {setBusy: value => { vm.busy = value }, destroy: () => { vm.$destroy(); vm.$el.remove() }}
}

window.MyXRechnung.mountFinishedActions = function (element, start, close) {
    return new Vue({
        render(h) {
            return h('div', {class: 'myx-review-actions'}, [
                h(NcButton, {on: {click: start}}, ['neue Rechnung starten']),
                h(NcButton, {on: {click: close}}, ['meine X-Rechnung schließen']),
            ])
        },
    }).$mount(element)
}
