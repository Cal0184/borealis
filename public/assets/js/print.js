// Printable reports: the button opens the browser's print dialog (where "Save as PDF" lives).
document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
if (new URLSearchParams(location.search).has('auto')) window.addEventListener('load', () => setTimeout(() => window.print(), 300));
