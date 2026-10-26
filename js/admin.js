(() => {
  'use strict'
  const root = document.getElementById('dcb-admin')
  if (!root) return
  const $ = id => root.querySelector('#' + id)
  const apiRoot = '/apps/deckcalendarbridge/api/'
  const csrf = (typeof OC !== 'undefined' && OC.requestToken) || window._oc_requesttoken || ''
  const state = { rules: [], revision: null, boards: [], calendars: [], labels: new Map(), dirty: false, busy: false }
  const list = $('dcb-rule-list')
  const status = $('dcb-status')
  const unsaved = $('dcb-unsaved')
  const jsonEditor = $('dcb-json')
  const btnSave = $('dcb-save')
  const btnAdd = $('dcb-add-rule')
  const url = (path, args) => OC.generateUrl(path.startsWith('/') ? path : apiRoot + path, args || {})
  const esc = s => String(s == null ? '' : s)
  const MAX_INVITEES = 50
  const valid = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/

  async function request(path, method = 'GET', body) {
    if (method !== 'GET' && !csrf) throw new Error('Missing Nextcloud session token. Reload the page and sign in again.')
    const headers = { Accept: 'application/json' }
    if (csrf) headers.requesttoken = csrf
    if (body !== undefined) headers['Content-Type'] = 'application/json'
    const response = await fetch(url(path), {
      method, credentials: 'same-origin', cache: 'no-store', headers,
      ...(body === undefined ? {} : { body: JSON.stringify(body) })
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(esc(data.error || data.message || ('HTTP ' + response.status)))
    }
    return data
  }

  function feedback(message, error = false) {
    status.textContent = message
    status.classList.toggle('dcb-error', error)
  }
  function changed() {
    state.dirty = true
    unsaved.textContent = 'Unsaved changes'
    unsaved.classList.add('dcb-pending')
    jsonEditor.value = JSON.stringify(state.rules, null, 2)
    controls()
  }
  function controls() {
    btnSave.disabled = state.busy || !state.dirty || state.revision === null
    btnAdd.disabled = state.busy || state.revision === null
    $('dcb-reload').disabled = state.busy
  }
  function options(element, values, selected, fallbackLabel = 'Unavailable') {
    element.replaceChildren()
    for (const item of values) {
      const option = document.createElement('option')
      option.value = esc(item.value)
      option.textContent = esc(item.text)
      element.append(option)
    }
    const value = esc(selected)
    if (value && !values.some(item => esc(item.value) === value)) {
      const option = document.createElement('option')
      option.value = value
      option.textContent = fallbackLabel + ' (' + value + ')'
      element.append(option)
    }
    if (!element.options.length) {
      const option = document.createElement('option')
      option.value = ''
      option.textContent = 'Load options first'
      element.append(option)
    }
    element.value = value || element.options[0].value
  }

  function wrap(parent, title, child, hint) {
    const label = document.createElement('label')
    label.className = 'dcb-field'
    const name = document.createElement('span')
    name.textContent = title
    label.append(name, child)
    if (hint) {
      const help = document.createElement('small')
      help.textContent = hint
      label.append(help)
    }
    parent.append(label)
    return child
  }
  function input(parent, title, current, onChange, type = 'text', opts = {}) {
    const control = document.createElement(type === 'textarea' ? 'textarea' : 'input')
    if (type !== 'textarea') control.type = type
    if (type === 'checkbox') control.checked = Boolean(current)
    else control.value = current == null ? '' : esc(current)
    if (opts.min !== undefined) control.min = String(opts.min)
    if (opts.max !== undefined) control.max = String(opts.max)
    if (opts.rows) control.rows = opts.rows
    control.addEventListener(type === 'checkbox' ? 'change' : 'input', () => {
      onChange(type === 'checkbox' ? control.checked : type === 'number' ? Number(control.value) : control.value)
      changed()
    })
    wrap(parent, title, control, opts.hint)
    return control
  }
  function select(parent, title, values, current, onChange, hint) {
    const control = document.createElement('select')
    options(control, values, current)
    control.addEventListener('change', () => { onChange(control.value); changed() })
    wrap(parent, title, control, hint)
    return control
  }
  const values = obj => Object.entries(obj).map(([value, text]) => ({ value, text }))
  function child(parent, cls = '') {
    const element = document.createElement('div')
    if (cls) element.className = cls
    parent.append(element)
    return element
  }
  function paragraph(parent, text, cls = '') {
    const element = document.createElement('p')
    element.textContent = text
    element.className = cls
    parent.append(element)
  }
  function heading(parent, text) {
    const title = document.createElement('h4')
    title.textContent = text
    parent.append(title)
  }
  function calendarKey(c) { return esc(c.principal) + '|' + esc(c.uri) }
  function getCalendarKey(r) {
    return esc(r.target?.principal) + '|' + esc(r.target?.calendarUri)
  }
  async function boardLabels(id) {
    if (!Number.isSafeInteger(id) || id < 1) return []
    if (state.labels.has(id)) return state.labels.get(id)
    const board = await request('boards/' + id + '/labels')
    const items = Array.isArray(board.labels) ? board.labels : []
    const labels = items.filter(x => Number.isSafeInteger(x.id) && x.id > 0)
      .map(x => ({ value: String(x.id), text: esc(x.title) }))
    state.labels.set(id, labels)
    return labels
  }

  function renderRule(r, index) {
    const card = document.createElement('article')
    card.className = 'dcb-rule'
    const top = child(card, 'dcb-rule-head')
    const caption = child(top, 'dcb-rule-caption')
    const chip = document.createElement('span')
    chip.className = 'dcb-rule-index'
    chip.textContent = String(index + 1).padStart(2, '0')
    caption.append(chip)
    const titles = child(caption)
    const title = document.createElement('strong')
    const board = state.boards.find(b => b.id === r.source?.boardIds?.[0])
    title.textContent = board?.title || ('Board ' + esc(r.source?.boardIds?.[0] || 'select'))
    const sub = document.createElement('small')
    sub.textContent = 'Rule ' + esc(r.id) + ' · Tag ' + esc(r.source?.labelIds?.[0] || 'select') + ' · ' + (r.enabled ? 'active' : 'inactive')
    titles.append(title, sub)
    const minus = document.createElement('button')
    minus.type = 'button'
    minus.className = 'dcb-danger'
    minus.textContent = '− Remove rule'
    minus.setAttribute('aria-label', 'Configuration rule ' + (index + 1) + ' remove')
    minus.addEventListener('click', () => {
      if (!window.confirm('Remove this rule? Saving will delete its verified linked calendar events and may send cancellations. Deck tags are retained. This cannot be undone.')) return
      state.rules.splice(state.rules.indexOf(r), 1)
      changed()
      render()
    })
    top.append(minus)
    const grid = child(card, 'dcb-grid')
    const validBoards = state.boards.filter(x => Number.isSafeInteger(x.id) && !x.archived)
      .map(x => ({ value: String(x.id), text: esc(x.title) }))
    const selectedBoard = esc(r.source?.boardIds?.[0] || '')
    const boardInput = select(grid, 'Deck board', validBoards, selectedBoard, async value => {
      const boardId = Number(value)
      if (!Number.isSafeInteger(boardId) || boardId < 1) return
      r.source.boardIds = [boardId]
      r.source.labelIds = []
      try {
        const labels = await boardLabels(boardId)
        if (labels.length) r.source.labelIds = [Number(labels[0].value)]
      } catch (e) { feedback('Could not load tags: ' + e.message, true) }
      changed()
      render()
    })
    const labelInput = document.createElement('select')
    wrap(grid, 'Deck trigger tag', labelInput)
    const setLabels = () => {
      const cached = state.labels.get(Number(boardInput.value)) || []
      options(labelInput, cached, esc(r.source?.labelIds?.[0] || ''))
    }
    setLabels()
    boardLabels(Number(boardInput.value)).then(() => {
      if (!labelInput.isConnected) return
      setLabels()
    }).catch(e => {
      if (labelInput.isConnected) labelInput.title = 'Tag list unavailable: ' + e.message
    })
    labelInput.addEventListener('change', () => {
      r.source.labelIds = [Number(labelInput.value)]
      sub.textContent = 'Rule ' + esc(r.id) + ' · Tag ' + labelInput.selectedOptions[0].textContent + ' · ' + (r.enabled ? 'active' : 'inactive')
      changed()
    })
    const organizerPicker = document.createElement('button')
    organizerPicker.type = 'button'
    organizerPicker.textContent = "Load another user's calendars"
    organizerPicker.addEventListener('click', async () => {
      const uid = window.prompt('Nextcloud user ID of the calendar organizer:')
      if (uid === null) return
      if (!/^[A-Za-z0-9_.@-]{1,128}$/.test(uid) || uid.includes('..')) {
        feedback('Invalid user ID', true)
        return
      }
      try {
        const calResult = await request('calendar-options?userId=' + encodeURIComponent(uid))
        const loaded = Array.isArray(calResult.calendars) ? calResult.calendars : []
        const known = new Map(state.calendars.map(c => [calendarKey(c), c]))
        for (const cal of loaded) known.set(calendarKey(cal), cal)
        state.calendars = [...known.values()]
        render()
        feedback(loaded.length + ' calendar option(s) loaded for ' + uid + '. Permissions are checked when writing.')
      } catch (e) { feedback('Could not load calendars: ' + e.message, true) }
    })
    grid.append(organizerPicker)
    const calOptions = state.calendars.map(c => ({ value: calendarKey(c), text: esc(c.organizerUser || c.principal) + ' · ' + esc(c.name) }))
    select(grid, 'Target calendar', calOptions, getCalendarKey(r), value => {
      const cal = state.calendars.find(c => calendarKey(c) === value)
      if (cal) r.target = { mode: 'fixed', principal: cal.principal, calendarUri: cal.uri }
    })
    input(grid, 'Enable rule', r.enabled, value => {
      r.enabled = value
      sub.textContent = 'Rule ' + esc(r.id) + ' · Tag ' + esc(r.source.labelIds[0] || 'select') + ' · ' + (r.enabled ? 'active' : 'inactive')
    }, 'checkbox')
    const presentation = r.presentation ??= {}
    const prefixMode = presentation.titlePrefixMode ?? (presentation.titlePrefix ? 'custom' : 'none')
    const separator = presentation.titleSeparator ?? (presentation.titlePrefixMode === undefined ? '' : ' - ')
    select(grid, 'Event title prefix', values({
      none: 'No prefix', custom: 'Custom text', list: 'Deck list / column name'
    }), prefixMode, value => {
      presentation.titlePrefixMode = value
      presentation.titleSeparator = separatorInput.value
      prefixInput.disabled = value !== 'custom'
      separatorInput.disabled = value === 'none'
    }, 'List names follow the card’s current Deck column. Renames are picked up by the recovery scan.')
    const prefixInput = input(grid, 'Custom prefix text', presentation.titlePrefix || '', value => { presentation.titlePrefix = value }, 'text',
      { hint: 'Used only with Custom text. Existing prefixes retain their exact punctuation and spaces.' })
    const separatorInput = input(grid, 'Prefix separator', separator, value => { presentation.titleSeparator = value }, 'text',
      { hint: 'Literal text, including spaces; for example " - " or ": ". Empty joins directly. Ignored without a prefix.' })
    prefixInput.disabled = prefixMode !== 'custom'
    separatorInput.disabled = prefixMode === 'none'
    input(grid, 'Calendar categories (one per line)', (r.presentation?.categories || []).join('\n'), value => {
      r.presentation.categories = [...new Set(value.split(/\r?\n/).map(x => x.trim()).filter(Boolean))]
    }, 'textarea', { rows: 2 })

    input(grid, 'Export all Deck card tags as calendar categories', r.presentation?.syncDeckTags || false,
      value => { r.presentation.syncDeckTags = value }, 'checkbox',
      { hint: 'Keep card tags current as event categories. Unrelated calendar categories are retained. Category-only email updates are optional below.' })
    const sync = child(card, 'dcb-subsection')
    heading(sync, 'Synchronization')
    paragraph(sync, 'Card start and due dates define the event interval. A single date uses the duration and position below.')
    const syncGrid = child(sync, 'dcb-grid')
    paragraph(sync, 'Time changes update the card and its other linked events. Deck controls the title. The description is kept as the card link and a field ownership notice; edit card notes and checklists in Deck.')
    input(syncGrid, 'Single-date event duration (minutes)', r.timing?.durationMinutes ?? 10, value => { r.timing.durationMinutes = value }, 'number', { min: 1, max: 1440 })
    select(syncGrid, 'Due date is at the…', values({
      before: 'end of the event',
      center: 'middle of the event',
      after: 'start of the event'
    }), r.timing?.dueAnchor || 'before', value => { r.timing.dueAnchor = value })
    input(syncGrid, 'Maximum event duration (minutes)', r.timing?.maxBlockMinutes ?? 240, value => { r.timing.maxBlockMinutes = value }, 'number', { min: 1, max: 10080 },
      )
    select(syncGrid, 'Date mode for this rule', values({
       skip: 'Use Deck times (default)',
       all_day: 'All day: ignore clock times',
       day_edges: 'Day edges: ignore clock times'
     }), r.timing?.dateOnly || 'skip', value => { r.timing.dateOnly = value },
       'Deck 1.19 does not distinguish date-only values from explicit times. This mode applies to every card under the rule. Day edges with a 20-minute duration: due-only ends at midnight; start-only begins at midnight. All-day and day-edge rules do not sync times back.')
    input(syncGrid, 'Time zone for day boundaries (IANA)', r.timing?.dateTimeZone || 'UTC',
       value => { r.timing.dateTimeZone = value }, 'text',
       { hint: 'Use an IANA time zone, such as UTC or Europe/London. Day boundaries follow its daylight saving rules.' })
    select(syncGrid, 'When the card is done', values({
      keep: 'Keep the event',
      delete: 'Delete this event'
    }), r.sync?.onDone || 'keep', value => { r.sync.onDone = value })

    const invitation = child(card, 'dcb-subsection')
    heading(invitation, 'Invitations and reminders')
    const invitationGrid = child(invitation, 'dcb-grid')
    input(invitationGrid, 'Invite attendees on creation', r.invitations?.enabled || false, value => { r.invitations.enabled = value }, 'checkbox',
      { hint: 'Nextcloud sends invitations. Later attendee edits in the event are retained.' })
    input(invitationGrid, 'Recipients (commas or new lines)', (r.invitations?.attendees || []).join(', '), value => {
      r.invitations.attendees = [...new Map(value.split(/[,;\n]+/).map(s => s.trim()).filter(Boolean)
        .map(s => [s.toLowerCase(), s])).values()]
    }, 'textarea', { rows: 2, hint: 'Rule recipients apply to new events. Existing events retain the attendees currently set in the calendar.' })
    input(invitationGrid, 'Notify current attendees when categories change', r.invitations?.notifyOnCategoryChanges || false,
      value => { r.invitations.notifyOnCategoryChanges = value }, 'checkbox',
      { hint: 'Optional. Requests a native invitation update only when categories actually change. Uses the event’s current attendees, even if initial invitations are disabled. Does not resend unchanged events or bypass attendee delivery opt-outs.' })
    input(invitationGrid, 'Reminder before event (minutes; 0 = off)', r.reminderMinutes ?? 5,
      value => { r.reminderMinutes = value }, 'number',
      { min: 0, max: 10080, hint: '0 creates no bridge alarm. Calendar client reminders are independent.' })
    list.append(card)
  }

  function render() {
    list.replaceChildren()
    if (!state.rules.length) paragraph(list, 'No rules yet. Start with “+ Add rule”.', 'dcb-empty')
    state.rules.forEach((r, i) => renderRule(r, i))
    jsonEditor.value = JSON.stringify(state.rules, null, 2)
    controls()
  }

  function newRule(boardId, labelId, cal) {
    const id = 'tag-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)
    return {
      id, enabled: false, priority: 10,
      source: { type: 'deck', boardIds: [boardId], labelIds: [labelId], match: 'any' },
      target: { mode: 'fixed', principal: cal.principal, calendarUri: cal.uri },
      timing: { durationMinutes: 10, maxBlockMinutes: 240, dueAnchor: 'before', dateOnly: 'skip', dateTimeZone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' },
      reminderMinutes: 5,
      invitations: { enabled: false, attendees: [], includeAssignees: false, descriptionPolicy: 'link_only', allowFullDescription: false, notifyOnCategoryChanges: false },
      presentation: { titlePrefixMode: 'none', titlePrefix: '', titleSeparator: ' - ', categories: [], syncDeckTags: false },
      sync: { direction: 'bidirectional_times', onCalendarDelete: 'remove_tag', onDone: 'keep' }
    }
  }

  async function loadRuntime() {
    const runtime = await request('status')
    $('dcb-enabled').checked = runtime.enabled
    $('dcb-runtime').textContent = runtime.enabled
      ? 'Enabled. Times sync both ways; Deck manages titles, categories and the link description. Deleting an original event removes its trigger tag. Removing a rule keeps Deck tags.'
      : 'Disabled. Rules and previews are available; cards and events are not changed.'
    const container = $('dcb-relations')
    container.replaceChildren()
    const rows = runtime.relations || []
    if (!rows.length) paragraph(container, 'No relations yet. Enabling synchronization scans existing matching cards.')
    for (const row of rows) {
      const line = child(container, 'dcb-relation')
      const title = document.createElement('strong')
      title.textContent = 'Card ' + row.card_id + ' · Tag ' + row.tag_id + ' · ' + row.state
      line.append(title)
      paragraph(line, row.principal + ' / ' + row.calendar_uri + ' · Rule ' + row.rule_id + ' · Generation ' + row.generation)
      if (row.last_error) paragraph(line, 'Pending: ' + row.last_error, 'dcb-error')
    }
  }
  $('dcb-enabled').addEventListener('change', async () => {
    try { await request('mode', 'PUT', { enabled: $('dcb-enabled').checked }); await loadRuntime() }
    catch (e) { feedback(e.message, true); await loadRuntime() }
  })
  $('dcb-run').addEventListener('click', async () => {
    $('dcb-run').disabled = true
    try { await request('sync', 'POST', {}); await loadRuntime(); feedback('Reconciliation completed. Pending work appears in the relation list.') }
    catch (e) { feedback(e.message, true) }
    finally { $('dcb-run').disabled = false }
  })
  $('dcb-refresh-relations').addEventListener('click', () => loadRuntime().catch(e => feedback(e.message, true)))

  async function loadChoices() {
    try {
      const [boardResult, calendarResult] = await Promise.all([
        request('boards'),
        request('calendar-options')
      ])
      state.boards = Array.isArray(boardResult) ? boardResult : (boardResult.boards || [])
      state.calendars = Array.isArray(calendarResult.calendars) ? calendarResult.calendars : []
      state.labels.clear()
      render()
      if (!state.calendars.length) feedback('No writable calendar found. Existing rules remain visible.', true)
    } catch (e) {
      feedback('Could not load Deck boards or calendars: ' + e.message, true)
    }
  }

  async function load() {
    if (state.busy) return
    state.busy = true
    controls()
    try {
      const result = await request('rules')
      if (!Array.isArray(result.rules) || typeof result.revision !== 'string') throw new Error('Invalid rules response')
      state.rules = result.rules
      state.revision = result.revision
      state.dirty = false
      unsaved.classList.remove('dcb-pending')
      unsaved.textContent = 'All changes saved'
      render()
      feedback('Rules loaded. Save changes before synchronizing.')
    } catch (e) {
      feedback('Could not load rules: ' + e.message, true)
    } finally { state.busy = false; controls() }
  }

  $('dcb-add-rule').addEventListener('click', async () => {
    try {
      const board = state.boards.find(b => Number.isSafeInteger(b.id) && !b.archived)
      const cal = state.calendars[0]
      if (!board || !cal) throw new Error('Create an accessible Deck board and a writable calendar first.')
      const labels = await boardLabels(board.id)
      if (!labels.length) throw new Error('The selected board has no tags.')
      if (state.rules.length >= 100) throw new Error('At most 100 rules are supported')
      const rule = newRule(board.id, Number(labels[0].value), cal)
      state.rules.push(rule)
      changed()
      render()
      feedback('Added a disabled rule. Configure it and save.')
    } catch (e) { feedback(e.message, true) }
  })
  $('dcb-save').addEventListener('click', async () => {
    if (!state.dirty || state.busy || state.revision === null) return
    const combinations = new Set()
    try {
      for (const rule of state.rules) {
        const board = rule.source?.boardIds?.[0], tag = rule.source?.labelIds?.[0]
        if (!(board > 0 && tag > 0 && rule.target?.principal && rule.target?.calendarUri)) throw new Error('Each rule needs a board, tag and calendar.')
        const key = board + ':' + tag
        if (combinations.has(key)) throw new Error('A board/tag combination must be unique.')
        combinations.add(key)
        if (rule.timing.durationMinutes > rule.timing.maxBlockMinutes) throw new Error('Maximum duration must be at least the default duration.')
        if (!['deck_to_calendar','bidirectional_times'].includes(rule.sync.direction)
          || !['unlink','remove_tag'].includes(rule.sync.onCalendarDelete)) throw new Error('Invalid synchronization direction.')
        rule.sync.onCalendarDelete = 'remove_tag'
        const recipients = rule.invitations?.attendees || []
        if (recipients.length > MAX_INVITEES || recipients.some(email => !valid.test(email) || email.length > 254)) throw new Error('Use at most 50 valid email addresses per rule.')
        if (rule.invitations?.enabled && recipients.length === 0) throw new Error('Invitations are enabled but no recipients are set.')
      }
    } catch (e) { feedback('Validation failed: ' + e.message, true); return }
    state.busy = true; controls()
    try {
      const requested = state.rules.map(r => ({
        id: r.id, enabled: r.invitations?.enabled ?? false,
        attendees: [...(r.invitations?.attendees || [])],
        notifyOnCategoryChanges: r.invitations?.notifyOnCategoryChanges ?? false
      }))
      const response = await request('rules', 'PUT', { revision: state.revision, rules: state.rules })
      if (!Array.isArray(response.rules) || typeof response.revision !== 'string') throw new Error('Invalid server response')
      // Do not claim a recipient change was saved just because PUT returned 200.
      // Re-fetch with no-store, verify exact revision AND per-rule attendees.
      const confirmed = await request('rules')
      if (!Array.isArray(confirmed.rules) || confirmed.revision !== response.revision) {
        throw new Error('Saving may have succeeded, but the saved version could not be verified. Reload before saving again')
      }
      for (const expected of requested) {
        const actual = confirmed.rules.find(r => r.id === expected.id)
        if (!actual || actual.invitations?.enabled !== expected.enabled
            || actual.invitations?.notifyOnCategoryChanges !== expected.notifyOnCategoryChanges
            || JSON.stringify(actual.invitations?.attendees || []) !== JSON.stringify(expected.attendees)) {
          throw new Error('Recipient list for rule ' + expected.id + ' was not saved as entered. Reload and check the saved rules')
        }
      }
      state.rules = confirmed.rules
      state.revision = confirmed.revision
      state.dirty = false
      unsaved.classList.remove('dcb-pending')
      unsaved.textContent = 'All changes saved'
      render()
      const scan = response.backfill || {}
      feedback('Rules saved. Backfill queued (' + (scan.cards || 0) + ' cards, ' + (scan.relations || 0) + ' existing relations). The worker or cron processes remaining pages. Removed rules also clean up older events.')
      await loadRuntime()
    } catch (e) { feedback('Save failed: ' + e.message + '. Reload settings after a version conflict.', true) }
    finally { state.busy = false; controls() }
  })
  $('dcb-reload').addEventListener('click', () => {
    if (state.dirty && !window.confirm('Discard unsaved changes?')) return
    Promise.all([load(), loadChoices(), loadRuntime()])
  })
  $('dcb-json-import').addEventListener('click', () => {
    try {
      const rules = JSON.parse(jsonEditor.value)
      if (!Array.isArray(rules)) throw new Error('Expected a JSON array')
      state.rules = rules
      changed()
      render()
      feedback('JSON imported into the local editor. Review all rules before saving.')
    } catch (e) { feedback('JSON import failed: ' + e.message, true) }
  })

  $('dcb-preview-button').addEventListener('click', async () => {
    const id = Number($('dcb-preview-card-id').value)
    const output = $('dcb-preview-results')
    if (!Number.isSafeInteger(id) || id < 1) { output.textContent = 'Enter a positive card ID.'; return }
    const button = $('dcb-preview-button')
    button.disabled = true
    try {
      const result = await request('multi-preview/' + id)
      output.textContent = JSON.stringify(result, null, 2)
    } catch (e) { output.textContent = 'Preview failed: ' + e.message }
    finally { button.disabled = false }
  })

  render()
  Promise.all([load(), loadChoices(), loadRuntime()]).catch(e => feedback(e.message, true))
})()

