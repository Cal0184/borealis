// Profile photo (1.7): choose an image, frame it in a circle (drag, arrow keys, zoom) and upload a
// 512 × 512 square. The server re-encodes it to small WebP sizes, so the original never leaves here.
import { api, toast, openDialog, closeDialog, refresh } from './core.js?v=1.13.0';

const OUT = 512;
let st = null; // { img, zoom, x, y } x/y: centre of the crop, in image pixels

function dlg() { return document.getElementById('dlg-photo'); }

function base(img, size) { return size / Math.min(img.naturalWidth, img.naturalHeight); }

function clamp() {
  const { img, zoom } = st;
  const half = Math.min(img.naturalWidth, img.naturalHeight) / zoom / 2;
  st.x = Math.min(Math.max(st.x, half), img.naturalWidth - half);
  st.y = Math.min(Math.max(st.y, half), img.naturalHeight - half);
}

function draw(canvas, size = canvas.width) {
  const ctx = canvas.getContext('2d');
  const { img, zoom, x, y } = st;
  const k = base(img, size) * zoom;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(img, size / 2 - x * k, size / 2 - y * k, img.naturalWidth * k, img.naturalHeight * k);
}

function render() {
  clamp();
  draw(dlg().querySelector('[data-crop-canvas]'));
}

function error(msg) {
  const box = dlg().querySelector('[data-photo-error]');
  box.textContent = msg || '';
  box.classList.toggle('show', !!msg);
}

function start(file) {
  if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) { toast('Usa una imagen JPG, PNG, WebP o GIF.', { type: 'error' }); return; }
  if (file.size > 8 * 1024 * 1024) { toast('La imagen es demasiado grande (máximo 8 MB).', { type: 'error' }); return; }
  const img = new Image();
  const src = URL.createObjectURL(file);
  img.onload = () => {
    URL.revokeObjectURL(src);
    if (img.naturalWidth < 32 || img.naturalHeight < 32) { toast('La imagen es demasiado pequeña (mínimo 32 × 32 píxeles).', { type: 'error' }); return; }
    st = { img, zoom: 1, x: img.naturalWidth / 2, y: img.naturalHeight / 2 };
    const d = dlg();
    d.querySelector('[data-crop-zoom]').value = '1';
    error('');
    openDialog(d);
    render();
    d.querySelector('[data-crop-stage]').focus();
  };
  img.onerror = () => { URL.revokeObjectURL(src); toast('No se pudo leer la imagen.', { type: 'error' }); };
  img.src = src;
}

async function save(btn) {
  const canvas = document.createElement('canvas');
  canvas.width = OUT;
  canvas.height = OUT;
  draw(canvas, OUT);
  const blob = await new Promise((res) => canvas.toBlob((b) => res(b), 'image/webp', 0.9))
    || await new Promise((res) => canvas.toBlob((b) => res(b), 'image/jpeg', 0.9));
  if (!blob) { error('Tu navegador no pudo preparar la imagen.'); return; }
  const fd = new FormData();
  fd.append('photo', blob, blob.type === 'image/webp' ? 'foto.webp' : 'foto.jpg');
  btn.disabled = true;
  try {
    const res = await api('/api/profile/photo', { formData: fd });
    closeDialog(dlg(), true);
    toast(res.message || 'Fotografía guardada');
    await refresh();
  } catch (e) {
    error(e.errors?.photo || e.message);
  } finally {
    btn.disabled = false;
  }
}

document.addEventListener('change', (e) => {
  const input = e.target.closest?.('[data-photo-input]');
  if (!input || !input.files?.[0]) return;
  start(input.files[0]);
  input.value = ''; // choosing the same file again still opens the cropper
});

document.addEventListener('input', (e) => {
  if (!st || !e.target.matches?.('[data-crop-zoom]')) return;
  st.zoom = Number(e.target.value) || 1;
  render();
});

document.addEventListener('click', (e) => {
  const b = e.target.closest?.('[data-crop-save]');
  if (b && st) save(b);
});

// Drag to move (mouse, pen or finger).
let drag = null;
document.addEventListener('pointerdown', (e) => {
  const stage = e.target.closest?.('[data-crop-stage]');
  if (!stage || !st) return;
  e.preventDefault();
  stage.setPointerCapture(e.pointerId);
  drag = { id: e.pointerId, px: e.clientX, py: e.clientY, x: st.x, y: st.y, scale: stage.getBoundingClientRect().width };
});
document.addEventListener('pointermove', (e) => {
  if (!drag || e.pointerId !== drag.id) return;
  const k = base(st.img, drag.scale) * st.zoom;
  st.x = drag.x - (e.clientX - drag.px) / k;
  st.y = drag.y - (e.clientY - drag.py) / k;
  render();
});
const stop = (e) => { if (drag && e.pointerId === drag.id) drag = null; };
document.addEventListener('pointerup', stop);
document.addEventListener('pointercancel', stop);

// Wheel or trackpad zoom, and the keyboard.
document.addEventListener('wheel', (e) => {
  if (!st || !e.target.closest?.('[data-crop-stage]')) return;
  e.preventDefault();
  const z = dlg().querySelector('[data-crop-zoom]');
  st.zoom = Math.min(4, Math.max(1, st.zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08)));
  z.value = String(st.zoom);
  render();
}, { passive: false });
document.addEventListener('keydown', (e) => {
  if (!st || !e.target.closest?.('[data-crop-stage]')) return;
  const step = Math.min(st.img.naturalWidth, st.img.naturalHeight) / st.zoom / 20;
  const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
  const z = dlg().querySelector('[data-crop-zoom]');
  if (moves[e.key]) { st.x += moves[e.key][0]; st.y += moves[e.key][1]; }
  else if (e.key === '+' || e.key === '=') st.zoom = Math.min(4, st.zoom * 1.1);
  else if (e.key === '-') st.zoom = Math.max(1, st.zoom / 1.1);
  else return;
  e.preventDefault();
  z.value = String(st.zoom);
  render();
});
