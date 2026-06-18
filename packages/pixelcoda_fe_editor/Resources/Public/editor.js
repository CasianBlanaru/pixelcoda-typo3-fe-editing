(function() {
    function init() {
        const bar = document.getElementById('pc-fe-toolbar-root');
        if (!bar) return;
        bar.hidden = false;

        let editMode = false;
        const editToggle = document.getElementById('pc-edit-toggle');
        const saveButton = document.getElementById('pc-save');
        const aiButton = document.getElementById('pc-ai');
        const addGlobal = document.getElementById('pc-add-global');
        const status = document.getElementById('pc-fe-status');
        const selection = document.getElementById('pc-fe-selection');
        const drawer = document.getElementById('pc-fe-drawer');
        const drawerBackdrop = document.getElementById('pc-fe-drawer-backdrop');
        const drawerClose = document.getElementById('pc-fe-drawer-close');
        const drawerTitle = document.getElementById('pc-fe-drawer-title');
        const imagePanel = document.getElementById('pc-fe-image-panel');
        const aiPanel = document.getElementById('pc-fe-ai-panel');
        const recordFrame = document.getElementById('pc-fe-record-frame');
        const aiConfig = document.getElementById('pc-fe-ai-config');
        const aiFieldName = document.getElementById('pc-fe-ai-field-name');
        const aiRun = document.getElementById('pc-fe-ai-run');
        const dirtyFields = new Map();
        let saveTimer = null;
        let activeEditable = null;
        let draggedFrame = null;
        let dragSourceNextSibling = null;
        let dragTargetContainer = null;
        let dragOriginalOrder = [];
        let drawerTrigger = null;
        let aiAction = 'rewrite';
        const dropIndicator = document.createElement('div');
        dropIndicator.className = 'pc-fe-drop-indicator';
        dropIndicator.setAttribute('role', 'presentation');

        updateToolbarState();

        function setEditable(on) {
            if (on) {
                markContentFields();
                updateToolbarState();
            }
            document.querySelectorAll('[data-pc-field]').forEach(el => {
                el.contentEditable = on ? 'true' : 'false';
                el.spellcheck = on;
                el.classList.toggle('pc-fe-editable', on);
                if (on && el.dataset.pcOriginalValue === undefined) {
                    el.dataset.pcOriginalValue = getFieldValue(el);
                }
            });
            if (!on) {
                setActiveEditable(null);
            } else {
                updateSelectionState();
            }
            document.querySelectorAll('.pc-fe-image-edit-button').forEach(button => {
                button.hidden = !on;
            });
            document.querySelectorAll('[data-pc-image-field]').forEach(image => {
                image.classList.toggle('pc-fe-image', on);
            });
            document.querySelectorAll('[data-pc-record-uid]').forEach(frame => {
                frame.classList.toggle('pc-fe-draggable', on);
            });
            document.querySelectorAll('.pc-fe-drag-handle').forEach(handle => {
                handle.hidden = !on;
                handle.draggable = on;
            });
            document.querySelectorAll('.pc-fe-move-controls').forEach(controls => {
                controls.hidden = !on;
            });
        }

        function markContentFields() {
            // Headless/Next.js support: mark elements with explicit data-pixelcoda attributes
            document.querySelectorAll("[data-pixelcoda-uid]").forEach(el => {
                const uid = el.dataset.pixelcodaUid;
                markFrame(el, uid);
                const header = el.querySelector("h1, h2, h3, h4, h5, h6");
                if (header && !header.dataset.pcField) {
                    markField(header, uid, "header");
                }
                const body = el.querySelector("p, .bodytext");
                if (body && !body.dataset.pcField && body !== header) {
                    markField(body, uid, "bodytext");
                }
            });
            document.querySelectorAll('[id^="c"], [data-content-element-uid], [data-table="tt_content"][data-uid], .frame[data-uid]').forEach(frame => {
                const uid = parseInt(
                    frame.dataset.contentElementUid
                    || frame.dataset.uid
                    || (frame.id || '').replace(/^c/, ''),
                    10
                );
                if (!uid || frame.closest('.pc-fe-toolbar')) return;
                markFrame(frame, uid);

                const header = frame.querySelector(':scope h1, :scope h2, :scope h3, :scope h4, :scope h5, :scope h6');
                if (header && !header.dataset.pcField) {
                    header.dataset.pcField = '1';
                    header.dataset.table = 'tt_content';
                    header.dataset.uid = String(uid);
                    header.dataset.field = 'header';
                }

                const body = frame.querySelector(':scope .ce-bodytext, :scope p');
                if (body && !body.dataset.pcField && body !== header) {
                    body.dataset.pcField = '1';
                    body.dataset.table = 'tt_content';
                    body.dataset.uid = String(uid);
                    body.dataset.field = 'bodytext';
                }
            });

            const records = getEditableRecords();
            records.forEach(record => {
                if (!record.uid) return;

                const article = findArticleForRecord(record);
                if (!article) return;
                markFrame(article, record.uid);

                const header = article.querySelector('h1, h2, h3, h4, h5, h6');
                if (header && !header.dataset.pcField) {
                    markField(header, record.uid, 'header');
                }

                const body = article.querySelector('.ce-bodytext, p');
                if (body && !body.dataset.pcField && body !== header) {
                    markField(body, record.uid, 'bodytext');
                }

                markImages(article, record);
            });
        }

        function getEditableRecords() {
            return (TYPO3 && TYPO3.settings && Array.isArray(TYPO3.settings.feEditorRecords))
                ? TYPO3.settings.feEditorRecords
                : [];
        }

        function normalizeText(value) {
            return (value || '').replace(/\s+/g, ' ').trim();
        }

        function findArticleForRecord(record) {
            const uid = String(record.uid || '');
            const exactMatch = document.getElementById('c' + uid)
                || document.querySelector('[data-content-element-uid="' + uid + '"]')
                || document.querySelector('[data-table="tt_content"][data-uid="' + uid + '"]')
                || document.querySelector('.frame[data-uid="' + uid + '"]');
            if (exactMatch && !exactMatch.closest('.pc-fe-toolbar')) {
                return exactMatch;
            }

            const header = normalizeText(record.header);
            const bodytext = normalizeText(record.bodytextText);
            const candidates = Array.from(document.querySelectorAll('article, section, .frame'))
                .filter(candidate => !candidate.dataset.pcRecordUid);

            return candidates.find(candidate => {
                const candidateHeader = normalizeText(candidate.querySelector('h1, h2, h3, h4, h5, h6')?.textContent || '');
                const candidateBody = normalizeText(candidate.querySelector('.ce-bodytext, p')?.textContent || '');

                return (header && candidateHeader === header) || (bodytext && candidateBody === bodytext);
            }) || null;
        }

        function markField(element, uid, field) {
            element.dataset.pcField = '1';
            element.dataset.table = 'tt_content';
            element.dataset.uid = String(uid);
            element.dataset.field = field;
        }

        function markFrame(frame, uid) {
            const isHeadless = !!frame.dataset.pixelcodaUid;
            if (frame.closest('.pc-fe-toolbar')) return;
            if (frame.dataset.pcRecordUid && frame.dataset.pcRecordUid !== String(uid)) return;
            frame.dataset.pcRecordUid = String(uid);
            frame.classList.toggle('pc-fe-draggable', editMode);
            if (frame.querySelector(':scope > .pc-fe-move-controls')) return;

            const controls = document.createElement('div');
            controls.className = 'pc-fe-move-controls';
            controls.hidden = !editMode;
            controls.innerHTML = [
                '<button type="button" class="pc-fe-drag-handle" title="Element ziehen" aria-label="Element per Drag-and-drop verschieben"><span aria-hidden="true">⠿</span></button>',
                '<button type="button" class="pc-fe-move-button" data-pc-move="up" title="Nach oben verschieben" aria-label="Nach oben verschieben"><span aria-hidden="true">↑</span></button>',
                '<button type="button" class="pc-fe-move-button" data-pc-move="down" title="Nach unten verschieben" aria-label="Nach unten verschieben"><span aria-hidden="true">↓</span></button>',
                '<button type="button" class="pc-fe-actions-toggle" title="Elementaktionen" aria-label="Elementaktionen öffnen" aria-haspopup="menu" aria-expanded="false"><span aria-hidden="true">•••</span></button>',
                '<div class="pc-fe-actions-menu" role="menu" hidden>',
                '<div class="pc-fe-actions-meta"><strong></strong><span></span></div>',
                '<button type="button" role="menuitem" data-pc-record-action="edit">Datensatz bearbeiten</button>',
                '<button type="button" role="menuitem" data-pc-record-action="hide">Element ausblenden</button>',
                '<button type="button" role="menuitem" class="pc-fe-danger-action" data-pc-record-action="delete">Element löschen</button>',
                '</div>'
            ].join('');
            frame.prepend(controls);
            controls.querySelector('.pc-fe-drag-handle').draggable = editMode;
            const record = getRecordByUid(uid);
            const ctype = frame.dataset.pixelcodaCtype || record?.CType || 'tt_content';
            controls.querySelector(".pc-fe-actions-meta strong").textContent = record?.header || frame.dataset.pixelcodaCtype || record?.CType || "Inhaltselement";
            controls.querySelector('.pc-fe-actions-meta span').textContent = (isHeadless ? 'Headless · ' : '') + ctype + ' · UID ' + uid;
        }

        function getIcon(name) {
            return TYPO3 && TYPO3.settings && TYPO3.settings.feEditorIcons
                ? (TYPO3.settings.feEditorIcons[name] || '')
        }

        function getRecordByUid(uid) {
            return getEditableRecords().find(record => String(record.uid) === String(uid)) || null;
        }

        function closeActionMenus(except = null) {
            document.querySelectorAll('.pc-fe-actions-menu').forEach(menu => {
                if (menu !== except) menu.hidden = true;
            });
            document.querySelectorAll('.pc-fe-actions-toggle').forEach(button => {
                if (!except || button.nextElementSibling !== except) {
                    button.setAttribute('aria-expanded', 'false');
                }
            });
        }

        function confirmRecordAction(title, message, confirmLabel) {
            return new Promise(resolve => {
                const previousFocus = document.activeElement;
                const overlay = document.createElement('div');
                overlay.className = 'pc-fe-confirm-backdrop';
                overlay.innerHTML = [
                    '<div class="pc-fe-confirm" role="alertdialog" aria-modal="true" aria-labelledby="pc-fe-confirm-title" aria-describedby="pc-fe-confirm-message">',
                    '<h2 id="pc-fe-confirm-title"></h2>',
                    '<p id="pc-fe-confirm-message"></p>',
                    '<div class="pc-fe-confirm-actions">',
                    '<button type="button" data-pc-confirm="cancel">Abbrechen</button>',
                    '<button type="button" class="pc-fe-confirm-danger" data-pc-confirm="accept"></button>',
                    '</div>',
                    '</div>'
                ].join('');
                overlay.querySelector('#pc-fe-confirm-title').textContent = title;
                overlay.querySelector('#pc-fe-confirm-message').textContent = message;
                overlay.querySelector('[data-pc-confirm="accept"]').textContent = confirmLabel;
                document.body.appendChild(overlay);

                const close = result => {
                    document.removeEventListener('keydown', onKeydown);
                    overlay.remove();
                    previousFocus?.focus?.();
                    resolve(result);
                };
                const onKeydown = event => {
                    if (event.key === 'Escape') close(false);
                    if (event.key === 'Tab') {
                        const buttons = Array.from(overlay.querySelectorAll('button'));
                        const first = buttons[0];
                        const last = buttons[buttons.length - 1];
                        if (event.shiftKey && document.activeElement === first) {
                            event.preventDefault();
                            last.focus();
                        } else if (!event.shiftKey && document.activeElement === last) {
                            event.preventDefault();
                            first.focus();
                        }
                    }
                };
                overlay.addEventListener('click', event => {
                    if (event.target === overlay || event.target.closest('[data-pc-confirm="cancel"]')) close(false);
                    if (event.target.closest('[data-pc-confirm="accept"]')) close(true);
                });
                document.addEventListener('keydown', onKeydown);
                overlay.querySelector('[data-pc-confirm="cancel"]').focus();
            });
        }

        async function runRecordAction(frame, action, trigger) {
            const explicitEditUrl = frame?.dataset.pixelcodaEditUrl;
            const uid = frame?.dataset.pcRecordUid;
            const record = getRecordByUid(uid);
            if (!uid) return;

            if (action === 'edit') {
                const url = explicitEditUrl || record?.editUrl;
                if (url) {
                    openDrawer('record', trigger, url);
                }
                return;
            }
            if (action === 'hide') {
                const confirmed = await confirmRecordAction(
                    'Element ausblenden?',
                    'Das Element wird im Frontend ausgeblendet und kann im TYPO3-Backend wieder aktiviert werden.',
                    'Ausblenden'
                );
                if (confirmed && await saveDataPayload({ tt_content: { [uid]: { hidden: 1 } } })) {
                    setStatus('Element ausgeblendet, Seite wird aktualisiert...', 'success', true);
                    window.location.reload();
                }
                return;
            }
            if (action === 'delete') {
                const confirmed = await confirmRecordAction(
                    'Element löschen?',
                    'Das Inhaltselement wird über TYPO3 DataHandler gelöscht.',
                    'Endgültig löschen'
                );
                if (confirmed && await saveDataPayload({}, { tt_content: { [uid]: { delete: 1 } } })) {
                    setStatus('Element gelöscht, Seite wird aktualisiert...', 'success', true);
                    window.location.reload();
                }
            }
        }

        function createIconMarkup(name) {
            const icon = getIcon(name);
            return icon
                ? '<img class="pc-fe-icon" src="' + icon + '" alt="" onerror="this.hidden=true">'
                : '';
        }

        function markImages(container, record) {
            container.querySelectorAll('img').forEach(image => {
                if (image.closest('.pc-fe-toolbar') || image.dataset.pcImageField) return;
                image.dataset.pcImageField = '1';
                image.dataset.uid = String(record.uid);
                image.dataset.editUrl = record.editUrl || '';
                image.classList.toggle('pc-fe-image', editMode);

                const host = image.parentElement || container;
                host.classList.add('pc-fe-image-host');

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pc-fe-image-edit-button';
                button.dataset.editUrl = record.editUrl || '';
                button.title = 'Bild und Datensatz bearbeiten';
                button.hidden = !editMode;
                button.innerHTML = createIconMarkup('edit') + 'Bild';
                host.appendChild(button);
            });
        }

        function getFieldKey(element) {
            return [element.dataset.table, element.dataset.uid, element.dataset.field].join(':');
        }

        function getFieldValue(element) {
            return element.dataset.field === 'header'
                ? normalizeText(element.textContent)
                : element.innerHTML;
        }

        function markDirty(element) {
            if (!element || !element.dataset.pcField) return;
            dirtyFields.set(getFieldKey(element), element);
            element.classList.add('pc-fe-dirty');
            updateSaveButton();
            scheduleAutosave();
        }

        function setButtonLabel(button, label) {
            if (!button) return;
            button.dataset.label = label;
            button.setAttribute('aria-label', label);
        }

        function updateSaveButton() {
            if (!saveButton) return;
            const dirty = dirtyFields.size > 0;
            saveButton.disabled = !dirty;
            saveButton.classList.toggle('dirty', dirty);
            saveButton.classList.toggle('has-unsaved-changes', dirty);
            setButtonLabel(saveButton, dirty ? 'Änderungen speichern' : 'Keine ungespeicherten Änderungen');
            const label = saveButton.querySelector('span');
            if (label) {
                label.textContent = dirty ? 'Save *' : 'Save';
                return;
            }
            const textNode = Array.from(saveButton.childNodes).find(node => node.nodeType === Node.TEXT_NODE);
            if (textNode) {
                textNode.textContent = dirty ? 'Save *' : 'Save';
            } else {
                saveButton.appendChild(document.createTextNode(dirty ? 'Save *' : 'Save'));
            }
        }

        function scheduleAutosave() {
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(() => {
                saveDirtyFields();
            }, 1500);
        }

        function getActiveEditable() {
            if (activeEditable && activeEditable.isConnected && activeEditable.dataset.pcField) {
                return activeEditable;
            }
            return document.activeElement?.closest?.('[data-pc-field][contenteditable="true"]') || null;
        }

        async function applyAiPolish(element, action = 'rewrite') {
            const text = normalizeText(element.innerText || element.textContent || '');
            if (!text) return { ok: false, error: 'empty_text' };

            const result = await requestAiSuggestion({
                text: element.dataset.field === 'header' ? text : element.innerHTML,
                field: element.dataset.field || 'bodytext',
                action
            });
            if (!result || !result.ok || !result.text) {
                return result || { ok: false, error: 'ai_request_failed' };
            }

            if (element.dataset.field === 'header') {
                element.textContent = normalizeText(result.text);
            } else {
                element.innerHTML = result.text;
            }
            return { ok: true };
        }

        function setStatus(message, type, persistent) {
            if (!status) return;
            status.textContent = message || '';
            status.dataset.state = type || '';
            if (message && !persistent) {
                window.setTimeout(() => {
                    if (status.textContent === message) {
                        status.textContent = '';
                        status.dataset.state = '';
                    }
                }, 4000);
            }
        }

        function setActiveEditable(element) {
            document.querySelectorAll('.pc-fe-selected').forEach(field => {
                field.classList.remove('pc-fe-selected');
            });
            activeEditable = element && element.dataset.pcField ? element : null;
            activeEditable?.classList.add('pc-fe-selected');
            updateSelectionState();
        }

        function updateSelectionState() {
            if (!selection) return;
            if (!editMode) {
                selection.textContent = 'Editieren aktivieren';
                selection.dataset.state = '';
            } else if (!activeEditable) {
                selection.textContent = 'Textfeld auswählen';
                selection.dataset.state = 'waiting';
            } else {
                selection.textContent = activeEditable.dataset.field === 'header' ? 'Überschrift ausgewählt' : 'Text ausgewählt';
                selection.dataset.state = 'selected';
            }
            updateAiPanel();
        }

        function getAiErrorMessage(error) {
            return {
                openai_api_key_missing: 'AI-Provider ist noch nicht konfiguriert',
                empty_text: 'Das ausgewählte Feld ist leer',
                auth_required: 'Backend-Anmeldung erforderlich',
                no_modify_permission: 'Keine Berechtigung für AI-Bearbeitung',
                invalid_token: 'Sitzung abgelaufen, Seite neu laden',
                openai_request_failed: 'AI-Dienst derzeit nicht erreichbar'
            }[error] || 'AI-Vorschlag konnte nicht erstellt werden';
        }

        function setAiBusy(busy) {
            if (!aiButton) return;
            aiButton.disabled = busy;
            aiButton.classList.toggle('is-loading', busy);
            aiButton.setAttribute('aria-busy', busy ? 'true' : 'false');
            if (aiRun) {
                aiRun.disabled = busy || TYPO3?.settings?.feEditorAiConfigured !== true || !getActiveEditable();
                aiRun.textContent = busy ? 'AI arbeitet...' : 'AI-Vorschlag erstellen';
            }
        }

        function updateAiPanel() {
            if (!aiConfig || !aiFieldName || !aiRun) return;
            const configured = TYPO3?.settings?.feEditorAiConfigured === true;
            const field = getActiveEditable();
            aiConfig.textContent = configured
                ? (TYPO3.settings.feEditorAiProvider + ' ist konfiguriert und bereit.')
                : 'AI ist nicht konfiguriert. Provider und API-Key unter TYPO3 Einstellungen > Extension Configuration hinterlegen.';
            aiConfig.dataset.state = configured ? 'ready' : 'warning';
            aiFieldName.textContent = field
                ? (field.dataset.field === 'header' ? 'Überschrift' : 'Textinhalt')
                : 'Kein Feld ausgewählt';
            aiRun.disabled = !configured || !field;
        }

        function openDrawer(type, trigger, url) {
            if (!drawer || !drawerBackdrop || !drawerTitle) return;
            drawerTrigger = trigger || document.activeElement;
            imagePanel.hidden = type !== 'image' && type !== 'record';
            aiPanel.hidden = type !== 'ai';
            drawerTitle.textContent = type === 'image'
                ? 'Bild und Datensatz bearbeiten'
                : (type === 'record' ? 'Inhaltselement bearbeiten' : 'AI-Schreibassistent');
            if ((type === 'image' || type === 'record') && recordFrame) {
                recordFrame.src = url;
            }
            if (type === 'ai') {
                updateAiPanel();
            }
            drawer.hidden = false;
            drawerBackdrop.hidden = false;
            Array.from(document.body.children).forEach(element => {
                if (element !== drawer && element !== drawerBackdrop && !['SCRIPT', 'LINK'].includes(element.tagName)) {
                    element.inert = true;
                    element.dataset.pcDrawerInert = '1';
                }
            });
            document.body.classList.add('pc-fe-drawer-open');
            window.requestAnimationFrame(() => {
                drawer.classList.add('is-open');
                drawerBackdrop.classList.add('is-open');
                drawerClose?.focus();
            });
        }

        function closeDrawer() {
            if (!drawer || drawer.hidden) return;
            drawer.classList.remove('is-open');
            drawerBackdrop?.classList.remove('is-open');
            document.querySelectorAll('[data-pc-drawer-inert="1"]').forEach(element => {
                element.inert = false;
                delete element.dataset.pcDrawerInert;
            });
            document.body.classList.remove('pc-fe-drawer-open');
            window.setTimeout(() => {
                drawer.hidden = true;
                if (drawerBackdrop) drawerBackdrop.hidden = true;
                if (recordFrame) recordFrame.src = 'about:blank';
                drawerTrigger?.focus?.();
            }, 180);
        }

        async function runAiAction() {
            const el = getActiveEditable();
            if (!el) {
                setStatus('Zuerst ein markiertes Textfeld auswählen', 'warning');
                updateAiPanel();
                return;
            }
            setAiBusy(true);
            setStatus('AI verbessert den ausgewählten Text...', 'info', true);
            let result;
            try {
                result = await applyAiPolish(el, aiAction);
            } catch (error) {
                console.error('AI processing failed', error);
                result = { ok: false, error: 'openai_request_failed' };
            } finally {
                setAiBusy(false);
            }
            if (result.ok) {
                markDirty(el);
                closeDrawer();
                setStatus('AI-Vorschlag bereit und noch nicht gespeichert', 'success');
            } else {
                setStatus(getAiErrorMessage(result.error), 'warning', result.error === 'openai_api_key_missing');
            }
        }

        function updateToolbarState() {
            const hasEditableFields = document.querySelector('[data-pc-field]') !== null;
            const hasContentFrames = document.querySelector('[id^="c"]') !== null;
            const hasEditableRecords = getEditableRecords().length > 0;
            const hasDropzones = document.querySelector('[data-pc-dropzone]') !== null;
            const pageId = TYPO3 && TYPO3.settings ? parseInt(TYPO3.settings.feEditorPageId || '0', 10) : 0;
            const canEdit = hasEditableFields || hasContentFrames || hasEditableRecords;
            const canAdd = hasDropzones || pageId > 0;

            if (editToggle) {
                editToggle.disabled = !canEdit;
                setButtonLabel(editToggle, canEdit
                    ? (editMode ? 'Frontend Editing deaktivieren' : 'Frontend Editing aktivieren')
                    : 'Keine editierbaren Felder auf dieser Seite gefunden');
            }
            if (addGlobal) {
                addGlobal.disabled = !canAdd;
                setButtonLabel(addGlobal, canAdd
                    ? 'Neues Element hinzufügen'
                    : 'Keine Zielseite für neue Elemente gefunden');
            }
            if (aiButton) {
                const configured = TYPO3?.settings?.feEditorAiConfigured === true;
                aiButton.classList.toggle('is-unconfigured', !configured);
                setButtonLabel(aiButton, configured
                    ? 'Ausgewählten Text mit AI verbessern'
                    : 'AI konfigurieren: OPENAI_API_KEY fehlt');
            }
            if (!canEdit && !canAdd) {
                setStatus('Keine FE-Edit Marker', 'warning');
            }
            updateSaveButton();
        }

        document.addEventListener('click', async (e) => {
            if (!e.target.closest('.pc-fe-actions-toggle') && !e.target.closest('.pc-fe-actions-menu')) {
                closeActionMenus();
            }
            if (e.target.closest('#pc-edit-toggle')) {
                if (editToggle && editToggle.disabled) return;
                if (editMode && dirtyFields.size > 0) {
                    await saveDirtyFields();
                }
                editMode = !editMode;
                setEditable(editMode);
                e.target.closest('#pc-edit-toggle').classList.toggle('active', editMode);
                e.target.closest('#pc-edit-toggle').setAttribute('aria-pressed', editMode ? 'true' : 'false');
                setButtonLabel(editToggle, editMode ? 'Frontend Editing deaktivieren' : 'Frontend Editing aktivieren');
                setStatus(editMode ? 'Bearbeitung aktiv' : 'Bearbeitung beendet', 'info');
            }
            if (e.target.closest('#pc-save')) {
                await saveDirtyFields();
            }
            if (e.target.closest('.pc-fe-move-button')) {
                const button = e.target.closest('.pc-fe-move-button');
                const frame = button ? button.closest('[data-pc-record-uid]') : null;
                const direction = button ? button.dataset.pcMove : '';
                const container = frame?.parentElement || null;
                const originalOrder = getFrameOrder(container);
                if (!frame || !moveFrame(frame, direction)) {
                    setStatus('Kann nicht verschieben', 'warning');
                } else {
                    setStatus('Reihenfolge wird gespeichert...', 'info', true);
                    if (await saveCurrentOrder(container)) {
                        setStatus('Reihenfolge gespeichert', 'success');
                    } else {
                        restoreFrameOrder(container, originalOrder);
                        setStatus('Speichern fehlgeschlagen, Reihenfolge wiederhergestellt', 'warning');
                    }
                }
            }
            if (e.target.closest('.pc-fe-actions-toggle')) {
                const button = e.target.closest('.pc-fe-actions-toggle');
                const menu = button.nextElementSibling;
                const open = menu?.hidden === true;
                closeActionMenus(open ? menu : null);
                if (menu) menu.hidden = !open;
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) menu.querySelector('[role="menuitem"]')?.focus();
            }
            if (e.target.closest('[data-pc-record-action]')) {
                const button = e.target.closest('[data-pc-record-action]');
                const frame = button.closest('[data-pc-record-uid]');
                closeActionMenus();
                await runRecordAction(frame, button.dataset.pcRecordAction, button);
            }
            if (e.target.closest('.pc-fe-image-edit-button')) {
                const button = e.target.closest('.pc-fe-image-edit-button');
                const editUrl = button ? button.dataset.editUrl : '';
                if (editUrl) {
                    if (e.ctrlKey || e.metaKey) {
                        window.open(editUrl, '_blank', 'noopener,noreferrer');
                        setStatus('Datensatzeditor im neuen Tab geöffnet', 'info');
                    } else {
                        openDrawer('image', button, editUrl);
                        setStatus('Datensatzeditor geöffnet', 'info');
                    }
                } else {
                    setStatus('Keine Bild-URL', 'warning');
                }
            }
            if (e.target.closest('#pc-ai')) {
                if (!editMode) {
                    editMode = true;
                    setEditable(true);
                    if (editToggle) {
                        editToggle.classList.toggle('active', true);
                        editToggle.setAttribute('aria-pressed', 'true');
                        setButtonLabel(editToggle, 'Frontend Editing deaktivieren');
                    }
                }
                const el = getActiveEditable();
                if (!el) {
                    setStatus('Zuerst ein markiertes Textfeld auswählen', 'warning');
                    document.querySelector('[data-pc-field][contenteditable="true"]')?.focus();
                    return;
                }
                openDrawer('ai', aiButton);
            }
            if (e.target.closest('#pc-fe-drawer-close') || e.target.closest('#pc-fe-drawer-backdrop')) {
                closeDrawer();
            }
            if (e.target.closest('.pc-fe-ai-action')) {
                const button = e.target.closest('.pc-fe-ai-action');
                aiAction = button.dataset.pcAiAction || 'rewrite';
                document.querySelectorAll('.pc-fe-ai-action').forEach(actionButton => {
                    const active = actionButton === button;
                    actionButton.classList.toggle('active', active);
                    actionButton.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            }
            if (e.target.closest('#pc-fe-ai-run')) {
                await runAiAction();
            }
            if (e.target.closest('.pc-add')) {
                const z = e.target.closest('[data-pc-dropzone]');
                if (!z) return;
                if (await createContentElement(z.dataset.targetPid, z.dataset.colPos)) {
                    location.reload();
                }
            }
            if (e.target.closest('#pc-add-global')) {
                const firstDropzone = document.querySelector('[data-pc-dropzone]');
                if (firstDropzone) {
                    if (await createContentElement(firstDropzone.dataset.targetPid, firstDropzone.dataset.colPos)) {
                        setStatus('Element erstellt', 'success');
                        location.reload();
                    } else {
                        setStatus('Erstellen fehlgeschlagen', 'warning');
                    }
                    return;
                }
                const pageId = TYPO3 && TYPO3.settings ? parseInt(TYPO3.settings.feEditorPageId || '0', 10) : 0;
                if (pageId > 0 && await createContentElement(pageId, '0')) {
                    setStatus('Element erstellt', 'success');
                    location.reload();
                } else {
                    setStatus('Erstellen fehlgeschlagen', 'warning');
                }
            }
        });

        document.addEventListener('focusin', (e) => {
            const el = e.target.closest && e.target.closest('[data-pc-field]');
            if (el) {
                setActiveEditable(el);
            }
        });

        document.addEventListener('pointerdown', (e) => {
            const el = e.target.closest && e.target.closest('[data-pc-field]');
            if (el && editMode) {
                setActiveEditable(el);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeActionMenus();
            if (!drawer || drawer.hidden) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                closeDrawer();
                return;
            }
            if (e.key !== 'Tab') return;
            const focusable = Array.from(drawer.querySelectorAll('button:not([disabled]), iframe, [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
                .filter(element => element.offsetParent !== null);
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });

        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin || !event.data?.actionName) return;
            if (event.data.actionName === 'typo3:editform:saved') {
                setStatus('Gespeichert, Vorschau wird aktualisiert...', 'success', true);
                closeDrawer();
                window.setTimeout(() => window.location.reload(), 250);
            } else if (event.data.actionName === 'typo3:editform:closed') {
                closeDrawer();
            } else if (event.data.actionName === 'typo3:editform:navigate') {
                closeDrawer();
                window.location.reload();
            }
        });

        document.addEventListener('input', (e) => {
            const el = e.target.closest && e.target.closest('[data-pc-field]');
            if (!el || !editMode) return;
            setActiveEditable(el);
            markDirty(el);
            setStatus('Ungespeichert', 'info');
        });

        document.addEventListener('dragstart', (e) => {
            if (!editMode) return;
            const frame = e.target.closest && e.target.closest('[data-pc-record-uid]');
            if (!frame || !e.target.closest('.pc-fe-drag-handle')) {
                e.preventDefault();
                return;
            }
            draggedFrame = frame;
            dragSourceNextSibling = frame.nextSibling;
            dragTargetContainer = frame.parentElement;
            dragOriginalOrder = getFrameOrder(frame.parentElement);
            frame.classList.add('pc-fe-dragging');
            document.body.classList.add('pc-fe-is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', frame.dataset.pcRecordUid);
            setStatus('Element ziehen und an der Einfügelinie ablegen', 'info', true);
        });

        function moveFrame(frame, direction) {
            if (!frame || !frame.parentElement) return false;
            const sibling = direction === 'up'
                ? previousEditableFrame(frame)
                : nextEditableFrame(frame);
            if (!sibling) return false;

            if (direction === 'up') {
                frame.parentElement.insertBefore(frame, sibling);
            } else {
                frame.parentElement.insertBefore(sibling, frame);
            }
            return true;
        }

        function previousEditableFrame(frame) {
            let sibling = frame.previousElementSibling;
            while (sibling && !sibling.dataset.pcRecordUid) {
                sibling = sibling.previousElementSibling;
            }
            return sibling || null;
        }

        function nextEditableFrame(frame) {
            let sibling = frame.nextElementSibling;
            while (sibling && !sibling.dataset.pcRecordUid) {
                sibling = sibling.nextElementSibling;
            }
            return sibling || null;
        }

        document.addEventListener('dragover', (e) => {
            if (!editMode || !draggedFrame) return;
            const target = e.target.closest && e.target.closest('[data-pc-record-uid]');
            if (!target || target === draggedFrame || target.parentElement !== draggedFrame.parentElement) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = target.getBoundingClientRect();
            const after = e.clientY > rect.top + rect.height / 2;
            target.parentElement.insertBefore(dropIndicator, after ? target.nextSibling : target);
            dragTargetContainer = target.parentElement;
        });

        document.addEventListener('drop', (e) => {
            if (!draggedFrame || !dropIndicator.parentElement) return;
            e.preventDefault();
            dropIndicator.parentElement.insertBefore(draggedFrame, dropIndicator);
        });

        document.addEventListener('dragend', async () => {
            if (!draggedFrame) return;
            const moved = draggedFrame.nextSibling !== dragSourceNextSibling;
            draggedFrame.classList.remove('pc-fe-dragging');
            document.body.classList.remove('pc-fe-is-dragging');
            dropIndicator.remove();
            const targetContainer = dragTargetContainer;
            const originalOrder = dragOriginalOrder;
            draggedFrame = null;
            dragSourceNextSibling = null;
            dragTargetContainer = null;
            dragOriginalOrder = [];
            if (!moved) {
                setStatus('Position unverändert', 'info');
                return;
            }
            setStatus('Reihenfolge wird gespeichert...', 'info', true);
            if (await saveCurrentOrder(targetContainer)) {
                setStatus('Reihenfolge gespeichert', 'success');
            } else {
                restoreFrameOrder(targetContainer, originalOrder);
                setStatus('Speichern fehlgeschlagen, Reihenfolge wiederhergestellt', 'warning');
            }
        });

        // Save on blur
        document.addEventListener('focusout', async (e) => {
            const el = e.target.closest && e.target.closest('[data-pc-field]');
            if (!el || !editMode) return;
            if (!dirtyFields.has(getFieldKey(el))) return;
            const {
                table,
                uid,
                field
            } = el.dataset;
            if (await saveField({
                table,
                uid,
                field,
                value: getFieldValue(el)
            })) {
                dirtyFields.delete(getFieldKey(el));
                el.classList.remove('pc-fe-dirty');
                updateSaveButton();
                setStatus('Gespeichert', 'success');
            } else {
                setStatus('Speichern fehlgeschlagen', 'warning');
            }
        });

        async function saveDirtyFields() {
            window.clearTimeout(saveTimer);
            if (dirtyFields.size === 0) {
                setStatus('Alles gespeichert', 'success');
                return true;
            }

            setStatus('Speichert...', 'info');
            let ok = true;
            for (const [key, element] of Array.from(dirtyFields.entries())) {
                const saved = await saveField({
                    table: element.dataset.table,
                    uid: element.dataset.uid,
                    field: element.dataset.field,
                    value: getFieldValue(element)
                });
                if (saved) {
                    dirtyFields.delete(key);
                    element.classList.remove('pc-fe-dirty');
                    element.dataset.pcOriginalValue = getFieldValue(element);
                } else {
                    ok = false;
                }
            }
            updateSaveButton();
            setStatus(ok ? 'Gespeichert' : 'Speichern fehlgeschlagen', ok ? 'success' : 'warning');
            return ok;
        }
    }

    async function saveField({
        table,
        uid,
        field,
        value
    }) {
        const url = TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls['fe_editor_save'] : null;
        const formToken = TYPO3 && TYPO3.security ? TYPO3.security.feEditorToken : null;
        if (!url || !formToken) {
            console.error('PixelCoda FE Editor: missing ajaxUrl or formToken');
            return false;
        }
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                table,
                uid,
                field,
                value,
                formToken
            })
        });
        const json = await parseJsonResponse(res);
        if (!res.ok || !json.ok) {
            console.error('Save failed', json.error || json.errors || 'Unknown error');
            return false;
        }
        return true;
    }

    async function createContentElement(pid, colPos) {
        const url = TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls['fe_editor_save'] : null;
        const formToken = TYPO3 && TYPO3.security ? TYPO3.security.feEditorToken : null;
        if (!url || !formToken) {
            console.error('PixelCoda FE Editor: missing ajaxUrl or formToken');
            return false;
        }
        const NEWID = 'NEW' + Math.floor(Math.random() * 1e6);
        const data = {
            tt_content: {
                [NEWID]: {
                    pid: parseInt(pid, 10),
                    colPos: parseInt(colPos, 10),
                    CType: 'textpic',
                    header: 'Neues Element',
                    bodytext: '<p>Hier können Sie Ihren Text eingeben...</p>'
                }
            }
        };
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                data: JSON.stringify(data),
                cmd: JSON.stringify({}),
                formToken
            })
        });
        const json = await parseJsonResponse(res);
        if (!res.ok || !json.ok) {
            console.error('Create failed', json.error || json.errors || 'Unknown error');
            return false;
        }
        return true;
    }

    async function requestAiSuggestion({ text, field, action }) {
        const url = TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls['fe_editor_ai'] : null;
        const formToken = TYPO3 && TYPO3.security ? TYPO3.security.feEditorToken : null;
        if (!url || !formToken) {
            console.error('PixelCoda FE Editor: missing aiUrl or formToken');
            return { ok: false, error: 'missing_ai_url_or_token' };
        }
        let res;
        try {
            res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    text,
                    field,
                    action,
                    formToken
                })
            });
        } catch (error) {
            console.error('AI request failed', error);
            return { ok: false, error: 'openai_request_failed' };
        }
        const json = await parseJsonResponse(res);
        if ((!res.ok || !json.ok) && json.error !== 'openai_api_key_missing') {
            console.error('AI failed', json.error || json.message || 'Unknown error');
        }
        return json;
    }

    async function saveCurrentOrder(container = null) {
        const scope = container instanceof Element ? container : document;
        const frames = Array.from(scope.querySelectorAll(':scope > [data-pc-record-uid]'))
            .filter(frame => frame.offsetParent !== null);
        if (frames.length < 2) return true;

        const seenUids = new Set();
        const orderedUids = [];
        frames.forEach(frame => {
            const uid = frame.dataset.pcRecordUid;
            if (!uid || seenUids.has(uid)) return;
            seenUids.add(uid);
            orderedUids.push(uid);
        });

        if (seenUids.size !== frames.length) {
            console.error('PixelCoda FE Editor: duplicate or missing record UID while sorting');
            return false;
        }

        const pageId = Number(TYPO3?.settings?.feEditorPageId || 0);
        if (!Number.isInteger(pageId) || pageId <= 0) {
            console.error('PixelCoda FE Editor: missing page ID while sorting');
            return false;
        }

        const cmd = { tt_content: {} };
        orderedUids.forEach((uid, index) => {
            cmd.tt_content[uid] = {
                move: index === 0 ? pageId : -Number(orderedUids[index - 1])
            };
        });

        return saveDataPayload({}, cmd);
    }

    function getFrameOrder(container) {
        if (!(container instanceof Element)) return [];
        return Array.from(container.querySelectorAll(':scope > [data-pc-record-uid]'))
            .map(frame => frame.dataset.pcRecordUid)
            .filter(Boolean);
    }

    function restoreFrameOrder(container, order) {
        if (!(container instanceof Element) || !Array.isArray(order)) return;
        const frames = new Map(
            Array.from(container.querySelectorAll(':scope > [data-pc-record-uid]'))
                .map(frame => [frame.dataset.pcRecordUid, frame])
        );
        order.forEach(uid => {
            const frame = frames.get(uid);
            if (frame) container.appendChild(frame);
        });
    }

    async function saveDataPayload(data, cmd = {}) {
        const url = TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls['fe_editor_save'] : null;
        const formToken = TYPO3 && TYPO3.security ? TYPO3.security.feEditorToken : null;
        if (!url || !formToken) {
            console.error('PixelCoda FE Editor: missing ajaxUrl or formToken');
            return false;
        }
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                data: JSON.stringify(data),
                cmd: JSON.stringify(cmd),
                formToken
            })
        });
        const json = await parseJsonResponse(res);
        if (!res.ok || !json.ok) {
            console.error('Order save failed', json.error || json.errors || 'Unknown error');
            return false;
        }
        return true;
    }

    async function parseJsonResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        const text = await response.text();
        if (!contentType.includes('application/json')) {
            return {
                ok: false,
                error: 'non_json_response',
                message: 'Expected JSON but received ' + response.status + ' ' + contentType,
                preview: text.slice(0, 160)
            };
        }
        try {
            return JSON.parse(text);
        } catch (error) {
            return {
                ok: false,
                error: 'invalid_json_response',
                message: 'Received invalid JSON',
                preview: text.slice(0, 160)
            };
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
