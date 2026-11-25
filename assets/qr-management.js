(() => {
  const page = document.querySelector('.qr-management');
  if (!page) return;

  const status = document.getElementById('qr-management-status');
  const printArea = document.getElementById('qr-print-area');
  const cards = Array.from(printArea.querySelectorAll('.qr-print-card'));
  const say = message => { status.textContent = message; };
  let activeQrId = '';
  const dialog = document.getElementById('tenant-qr-dialog');
  const dialogImage = dialog.querySelector('[data-qr-dialog-image]');

  const download = async (qrId, tenantId) => {
    say('Preparing QR image download…');
    try {
      const response = await fetch('index.php?tenant_qr_download=' + encodeURIComponent(qrId), {cache:'no-store'});
      if (!response.ok) throw new Error(response.status === 404 ? 'This QR code is no longer active.' : 'The QR image could not be downloaded.');
      const image = await response.blob();
      const url = URL.createObjectURL(image);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'tenant-' + tenantId + '-qr.svg';
      document.body.append(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      say('QR image downloaded.');
    } catch (error) {
      say(error.message || 'The QR image could not be downloaded.');
    }
  };

  const printSelected = predicate => {
    cards.forEach(card => {
      if (predicate(card)) card.dataset.printSelected = '1';
      else delete card.dataset.printSelected;
    });
    if (!cards.some(card => card.dataset.printSelected === '1')) {
      say('There are no active QR cards to print for this selection.');
      return;
    }
    const originalParent = printArea.parentNode;
    const originalNext = printArea.nextSibling;
    const restorePrintArea = () => {
      document.body.classList.remove('qr-printing');
      if (originalNext && originalNext.parentNode === originalParent) originalParent.insertBefore(printArea, originalNext);
      else originalParent.append(printArea);
      cards.forEach(card => delete card.dataset.printSelected);
    };
    document.body.append(printArea);
    document.body.classList.add('qr-printing');
    say('Opening the print dialog…');
    window.addEventListener('afterprint', () => {
      restorePrintArea();
      say('Print dialog closed.');
    }, {once:true});
    try { window.print(); }
    finally { restorePrintArea(); }
  };

  document.querySelectorAll('[data-qr-view]').forEach(button => button.addEventListener('click', () => {
    activeQrId = button.dataset.qrId;
    dialog.querySelector('[data-qr-dialog-name]').textContent = button.dataset.tenantName;
    dialog.querySelector('[data-qr-dialog-tenant]').textContent = button.dataset.tenantId;
    dialog.querySelector('[data-qr-dialog-room]').textContent = button.dataset.room;
    dialogImage.src = 'index.php?tenant_qr_image=' + encodeURIComponent(activeQrId);
    dialogImage.onerror = () => say('The QR image could not be loaded. Refresh and try again.');
    dialog.showModal();
  }));
  document.querySelectorAll('[data-qr-download]').forEach(button => button.addEventListener('click', () => download(button.dataset.qrDownload, button.dataset.tenantId)));
  document.querySelectorAll('[data-qr-print]').forEach(button => button.addEventListener('click', () => printSelected(card => card.dataset.qrId === button.dataset.qrPrint)));
  document.querySelector('[data-qr-print-all]')?.addEventListener('click', () => printSelected(() => true));
  document.querySelector('[data-qr-print-room]')?.addEventListener('click', () => {
    const roomId = document.querySelector('[data-qr-filter]').elements.namedItem('room_id').value;
    if (!roomId) {
      say('Select a room before printing room QR cards.');
      return;
    }
    printSelected(card => card.dataset.roomId === roomId);
  });
  dialog.querySelector('[data-qr-close]').addEventListener('click', () => dialog.close());
  dialog.querySelector('[data-qr-dialog-download]').addEventListener('click', () => download(activeQrId, dialog.querySelector('[data-qr-dialog-tenant]').textContent));
  dialog.querySelector('[data-qr-dialog-print]').addEventListener('click', () => printSelected(card => card.dataset.qrId === activeQrId));
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
})();
