// public/docs/docs.js
(function () {
  const TOKEN_MATCH = window.location.pathname.match(/^\/docs\/([^/]+)/);
  const TOKEN = TOKEN_MATCH ? TOKEN_MATCH[1] : null;
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

  function postEvent(type, fileId, durationS) {
    fetch(`/docs/${TOKEN}/event`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': CSRF,
        'Accept': 'application/json',
      },
      body: JSON.stringify({ type, file_id: fileId, duration_s: durationS }),
    }).catch(() => {});
  }

  let currentPdf = null;
  let heartbeatInterval = null;
  let openedAt = 0;
  let openedFileId = null;

  function openViewer(fileId, fileName) {
    const url = `/docs/${TOKEN}/file/${fileId}/view`;
    openedAt = Date.now();
    openedFileId = fileId;

    const root = document.getElementById('viewer-root');
    root.innerHTML = `
      <div class="viewer-backdrop">
        <div class="viewer">
          <header class="viewer-header">
            <span>${fileName}</span>
            <div>
              <a class="viewer-download" href="/docs/${TOKEN}/file/${fileId}/download">Download</a>
              <button class="viewer-close" type="button">×</button>
            </div>
          </header>
          <div class="viewer-canvas-wrap">
            <iframe class="viewer-iframe" src="${url}" title="${fileName}"></iframe>
          </div>
        </div>
      </div>
    `;

    root.querySelector('.viewer-close').addEventListener('click', closeViewer);
    root.querySelector('.viewer-backdrop').addEventListener('click', (e) => {
      if (e.target.classList.contains('viewer-backdrop')) closeViewer();
    });

    // Send first "viewed" event
    postEvent('doc_viewed', fileId, 0);

    // Heartbeat every 30s — partial duration
    heartbeatInterval = setInterval(() => {
      const duration = Math.round((Date.now() - openedAt) / 1000);
      postEvent('doc_viewed', fileId, duration);
    }, 30000);
  }

  function closeViewer() {
    if (heartbeatInterval) {
      clearInterval(heartbeatInterval);
      heartbeatInterval = null;
    }
    if (openedFileId) {
      const duration = Math.round((Date.now() - openedAt) / 1000);
      postEvent('doc_viewed', openedFileId, duration);
      openedFileId = null;
    }
    const root = document.getElementById('viewer-root');
    if (root) root.innerHTML = '';
  }

  document.querySelectorAll('.btn-view').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      const fileId = parseInt(btn.dataset.fileId, 10);
      const fileName = btn.dataset.fileName;
      openViewer(fileId, fileName);
    });
  });

  document.querySelectorAll('.btn-download').forEach((a) => {
    a.addEventListener('click', () => {
      const match = a.href.match(/file\/(\d+)\//);
      if (match) postEvent('doc_downloaded', parseInt(match[1], 10), null);
    });
  });

  window.addEventListener('beforeunload', closeViewer);
})();
