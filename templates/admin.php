<?php
script('deckcalendarbridge', 'admin');
style('deckcalendarbridge', 'admin');
?>
<div id="dcb-admin" class="section">
  <header class="dcb-heading">
    <div>
      <p class="dcb-eyebrow">NEXTCLOUD · DECK + CALENDAR</p>
      <h2>Deck Calendar Bridge</h2>
      <p>Each trigger tag controls its linked event. Times synchronize in both directions; Deck wins conflicts. Invitations are optional.</p>
    </div>
    <span class="dcb-version">NC35 · 1.1.3</span>
  </header>

  <div id="dcb-status" class="dcb-status" role="status" aria-live="polite">Loading settings …</div>
  <p id="dcb-runtime" class="dcb-footnote" role="status">Loading runtime status …</p>
  <div class="dcb-actions">
    <label><input id="dcb-enabled" type="checkbox"> Enable synchronization</label>
    <button type="button" id="dcb-run">Synchronize now</button>
  </div>

  <section class="dcb-panel" aria-labelledby="dcb-rules-title">
    <div class="dcb-panel-heading">
      <div>
        <h3 id="dcb-rules-title">Tag rules</h3>
        <p>One rule per board/tag. Saving a removed rule deletes its verified linked calendar events and may send cancellations. Deck tags are retained. Disabling a rule keeps existing events.</p>
      </div>
      <button type="button" class="primary" id="dcb-add-rule">+ Add rule</button>
    </div>
    <div id="dcb-rule-list" aria-live="polite"></div>
    <div class="dcb-panel-footer">
      <span id="dcb-unsaved">No unsaved changes</span>
      <div class="dcb-actions">
        <button type="button" id="dcb-reload">Reload</button>
        <button type="button" id="dcb-save" class="primary" disabled>Save rules</button>
      </div>
    </div>
  </section>

  <section class="dcb-panel" aria-labelledby="dcb-preview-title">
    <div class="dcb-panel-heading"><div>
      <h3 id="dcb-preview-title">Card preview</h3>
      <p>Shows the effects of <strong>saved</strong> rules without changing events.</p>
    </div></div>
    <div class="dcb-actions">
      <label>Deck card ID <input type="number" id="dcb-preview-card-id" min="1" step="1"></label>
      <button type="button" id="dcb-preview-button">Show preview</button>
    </div>
    <pre id="dcb-preview-results" class="dcb-output" role="status" aria-live="polite"></pre>
  </section>

  <section class="dcb-panel">
    <div class="dcb-panel-heading"><h3>Relations and errors</h3><button type="button" id="dcb-refresh-relations">Refresh</button></div>
    <div id="dcb-relations" class="dcb-relation-list" aria-live="polite"></div>
  </section>
  <details class="dcb-panel dcb-details">
    <summary>Advanced: rules as JSON</summary>
    <p>For migration and troubleshooting. Import replaces the local editor contents; changes reach the server only after saving.</p>
    <textarea id="dcb-json" rows="12" spellcheck="false" aria-label="Rules as JSON"></textarea>
    <button type="button" id="dcb-json-import">Import JSON into editor</button>
  </details>
  <p class="dcb-footnote">A saved administrator rule delegates only its linked calendar actions, time synchronization and exact trigger-tag removal. Users do not need general access to the other application. Initial attendees are set once. Titles, categories and link descriptions are managed from Deck; card notes and checklists stay in Deck.</p>
</div>
