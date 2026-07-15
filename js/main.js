(function () {
	'use strict';

	const APP_ID = 'my_xrechnung';

	function getAppIconUrl() {
		if (typeof OC !== 'undefined' && OC.imagePath) {
			return OC.imagePath(APP_ID, 'app.svg');
		}
		return '/apps/' + APP_ID + '/img/app.svg';
	}

	function addMessage(text, type = 'info') {
		const output = document.getElementById('myx-output');
		if (!output) return;

		const line = document.createElement('div');
		line.className = 'myx-message myx-message-' + type;

		const time = new Date().toLocaleTimeString();
		line.textContent = '[' + time + '] ' + text;

		output.appendChild(line);
		output.scrollTop = output.scrollHeight;
	}

	function buildGui() {
		const appContent = document.getElementById('app-content') || document.body;

		appContent.innerHTML = '';

		const root = document.createElement('div');
		root.id = 'myx-root';

		Object.assign(root.style, {
			position: 'relative',
			width: '100%',
			height: '100%',
			minHeight: 'calc(100vh - 50px)',
			overflow: 'hidden',
			backgroundColor: 'var(--color-main-background)'
		});

		const iconArea = document.createElement('div');
		iconArea.id = 'myx-icon-area';

		Object.assign(iconArea.style, {
			position: 'absolute',
			top: '0',
			left: '0',
			right: '0',
			bottom: '20%',
			display: 'flex',
			alignItems: 'center',
			justifyContent: 'center',
			backgroundImage: 'url("' + getAppIconUrl() + '")',
			backgroundRepeat: 'no-repeat',
			backgroundPosition: 'center',
			backgroundSize: 'contain',
			opacity: '0.18',
			pointerEvents: 'none'
		});

		const output = document.createElement('div');
		output.id = 'myx-output';

		Object.assign(output.style, {
			position: 'absolute',
			left: '0',
			right: '0',
			bottom: '0',
			height: '20%',
			boxSizing: 'border-box',
			padding: '12px 16px',
			overflowY: 'auto',
			fontFamily: 'monospace',
			fontSize: '13px',
			lineHeight: '1.45',
			backgroundColor: 'var(--color-background-dark)',
			borderTop: '1px solid var(--color-border)',
			color: 'var(--color-main-text)'
		});

		root.appendChild(iconArea);
		root.appendChild(output);
		appContent.appendChild(root);

		addMessage('MyXRechnung gestartet.');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', buildGui);
	} else {
		buildGui();
	}

	window.MyXRechnung = {
		log: function (text) {
			addMessage(text, 'info');
		},
		warn: function (text) {
			addMessage(text, 'warning');
		},
		error: function (text) {
			addMessage(text, 'error');
		},
		clear: function () {
			const output = document.getElementById('myx-output');
			if (output) output.innerHTML = '';
		}
	};
})();
