/* ---------------------------------------------------------------------------
   Printing a receipt on a Bluetooth roll printer (Officom OC-PT210 and the other
   58 mm / 80 mm pocket printers that speak ESC/POS).

   The browser talks to the printer directly with Web Bluetooth, so nothing has to be
   installed on the phone or the till. That does put two conditions on the browser:

     * it must be Chrome, Edge, Opera or Samsung Internet - on Android or on a computer.
       Firefox, Safari, the iPhone, and the browsers built into Facebook and Messenger
       cannot do it, and there is no way round that from inside the page.
     * the page must be opened on a secure address: https://... , or http://localhost/...
       on the computer running it. A plain http:// address on the network (192.168...)
       is refused by the browser.

   Whatever the reason, the page says so in plain words instead of failing quietly.
   --------------------------------------------------------------------------- */
window.BtPrint = (function () {
  'use strict';

  // The services these printers put their "write here to print" channel behind. Only services named
  // here can be reached at all, so the usual ones are all listed and the first that works is used.
  const SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',   // the one on most 58 mm pocket printers
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000ff80-0000-1000-8000-00805f9b34fb',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',   // Microchip / ISSC serial port
    '6e400001-b5a3-f393-e0a9-e50e24dcca9e',   // Nordic serial port
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
  ];
  const REMEMBER = 'bt_printer_device';

  let device = null;        // the printer the user picked
  let chr = null;           // the channel on it that takes what is printed
  let opts = { width: 32, name: '', feed: 4, cut: false, autoprint: false };
  const listeners = [];

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const on = (fn) => { listeners.push(fn); fn(state()); };
  const say = () => listeners.forEach((fn) => fn(state()));

  function state() {
    return {
      supported: !!(navigator.bluetooth && navigator.bluetooth.requestDevice),
      secure: window.isSecureContext !== false,
      connected: !!(chr && device && device.gatt && device.gatt.connected),
      name: device ? (device.name || 'the printer') : '',
    };
  }

  /** Why this browser cannot print, in words the person at the counter can act on. */
  function whyNot() {
    if (window.isSecureContext === false) {
      return 'This page was opened on a plain http:// address, and browsers only allow Bluetooth on a secure one. '
        + 'Open the system as https://... , or on the computer it runs on as http://localhost/... , then try again.';
    }
    if (!(navigator.bluetooth && navigator.bluetooth.requestDevice)) {
      return 'This browser cannot talk to a Bluetooth printer. Use Google Chrome, Microsoft Edge, Opera or Samsung Internet '
        + 'on Android, or Chrome or Edge on a computer. The browsers built into Facebook and Messenger, Firefox, and anything '
        + 'on an iPhone or iPad cannot do it.';
    }
    return '';
  }

  // ------------------------------------------------------------------ the printer

  /** Asks the user to pick the printer and opens the channel to it. Must be called from a tap or a click. */
  async function connect() {
    const no = whyNot();
    if (no) { throw new Error(no); }
    const filter = String(opts.name || '').trim();
    device = await navigator.bluetooth.requestDevice(filter
      ? { filters: [{ namePrefix: filter }], optionalServices: SERVICES }
      : { acceptAllDevices: true, optionalServices: SERVICES });
    device.addEventListener('gattserverdisconnected', () => { chr = null; say(); });
    await open();
    try { localStorage.setItem(REMEMBER, device.id || ''); } catch (e) { /* private window */ }
    say();
    return state();
  }

  /** Opens the channel on a printer that has already been picked. */
  async function open() {
    const server = await device.gatt.connect();
    const services = await server.getPrimaryServices();
    for (const s of services) {
      let chars = [];
      try { chars = await s.getCharacteristics(); } catch (e) { continue; }
      const w = chars.find((c) => c.properties.writeWithoutResponse) || chars.find((c) => c.properties.write);
      if (w) { chr = w; return; }
    }
    throw new Error('That device answered, but it is not a printer this system can send to. '
      + 'Pick the receipt printer itself (the Officom / thermal printer), not a phone, watch or speaker.');
  }

  /**
   * Goes back to the printer that was used last, without asking again. Only works once the user has
   * allowed that printer for this site, and only in browsers that keep the permission; it is quiet
   * about failing, because there is always the button to pick the printer by hand.
   */
  async function reconnect() {
    if (chr) { return true; }
    try {
      if (!navigator.bluetooth || !navigator.bluetooth.getDevices) { return false; }
      const known = await navigator.bluetooth.getDevices();
      if (!known || !known.length) { return false; }
      let want = null;
      try { want = localStorage.getItem(REMEMBER); } catch (e) { want = null; }
      device = known.find((d) => d.id === want) || known[0];
      device.addEventListener('gattserverdisconnected', () => { chr = null; say(); });
      await open();
      say();
      return true;
    } catch (e) {
      chr = null;
      return false;
    }
  }

  function disconnect() {
    try { if (device && device.gatt && device.gatt.connected) { device.gatt.disconnect(); } } catch (e) { /* already gone */ }
    chr = null;
    say();
  }

  // ------------------------------------------------------------------ the printer's own alphabet
  // Everything above plain English letters (ñ, é, £, ½ ...) has its own number on a receipt printer.
  // This is the standard set (code page 437) the printer is put into before anything is sent.
  const CP437 = 'ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜ¢£¥₧ƒáíóúñÑªº¿⌐¬½¼¡«»'
    + '░▒▓│┤╡╢╖╕╣║╗╝╜╛┐└┴┬├─┼╞╟╚╔╩╦╠═╬╧╨╤╥╙╘╒╓╫╪┘┌█▄▌▐▀'
    + 'αßΓπΣσµτΦΘΩδ∞φε∩≡±≥≤⌠⌡÷≈°∙·√ⁿ²■ ';
  const SWAP = { '₱': 'P', '–': '-', '—': '-', '‘': "'", '’': "'", '“': '"', '”': '"', '…': '...', ' ': ' ' };

  function encode(text) {
    const out = [];
    for (const ch of String(text)) {
      const sub = SWAP[ch];
      if (sub !== undefined) { for (const c of sub) { out.push(c.charCodeAt(0) & 0x7f); } continue; }
      const code = ch.charCodeAt(0);
      if (code < 128) { out.push(code); continue; }
      const at = CP437.indexOf(ch);
      out.push(at >= 0 ? at + 128 : 63);   // anything else becomes a question mark
    }
    return out;
  }

  // ------------------------------------------------------------------ turning the receipt into printer codes
  const ESC = 27, GS = 29, LF = 10;

  function pad(left, right, width) {
    left = String(left === undefined ? '' : left);
    right = String(right === undefined ? '' : right);
    const room = width - right.length - 1;
    if (room < 1) { return (left + ' ' + right).slice(0, width); }
    if (left.length > room) { left = left.slice(0, room); }
    return left + ' '.repeat(width - left.length - right.length) + right;
  }

  /** The list of instructions the page carries, as the bytes the printer understands */
  function build(ops, width) {
    const b = [ESC, 0x40, ESC, 0x74, 0x00];   // start afresh, and use code page 437
    const ALIGN = { left: 0, center: 1, right: 2 };
    let align = 0, bold = 0, wide = 0;
    const setAlign = (a) => { if (a !== align) { align = a; b.push(ESC, 0x61, a); } };
    const setBold = (v) => { if (v !== bold) { bold = v; b.push(ESC, 0x45, v); } };
    const setWide = (v) => { if (v !== wide) { wide = v; b.push(GS, 0x21, v ? 0x11 : 0x00); } };
    const text = (t) => { encode(t).forEach((c) => b.push(c)); b.push(LF); };

    (ops || []).forEach((o) => {
      switch (o.op) {
        case 'rule':
          setAlign(0); setBold(0); setWide(0);
          text('-'.repeat(width));
          break;
        case 'cols':
          setAlign(0); setWide(0); setBold(o.bold ? 1 : 0);
          text(pad(o.left, o.right, width));
          break;
        case 'feed':
          setAlign(0); setBold(0); setWide(0);
          for (let i = 0; i < (o.n || 1); i++) { b.push(LF); }
          break;
        case 'cut':
          b.push(GS, 0x56, 0x42, 0x00);
          break;
        default:
          setAlign(ALIGN[o.align] || 0); setBold(o.bold ? 1 : 0); setWide(o.wide ? 1 : 0);
          text(o.text === undefined ? '' : o.text);
      }
    });
    b.push(ESC, 0x61, 0, ESC, 0x45, 0, GS, 0x21, 0x00);
    return new Uint8Array(b);
  }

  /** Sends in small helpings: a Bluetooth printer drops anything sent in one large piece. */
  async function send(bytes) {
    let size = 120, i = 0;
    while (i < bytes.length) {
      const part = bytes.slice(i, i + size);
      try {
        if (chr.writeValueWithoutResponse) { await chr.writeValueWithoutResponse(part); }
        else { await chr.writeValue(part); }
      } catch (e) {
        if (size === 20) { throw e; }
        size = 20;      // some printers take only one Bluetooth packet at a time: the same bytes, smaller helpings
        continue;
      }
      i += part.length;
      await sleep(18);
    }
  }

  /** Connects if it has to, then prints. Call it from a tap or a click. */
  async function print(ops) {
    if (!chr) {
      const back = await reconnect();
      if (!back) { await connect(); }
    }
    await send(build(ops, opts.width || 32));
  }

  // ------------------------------------------------------------------ the buttons on the page
  // A page prints by carrying:  <button data-bt-print data-bt-ops="#someJsonScript">
  // and a place to say how it went:  <div data-bt-status></div>
  function note(kind, message) {
    document.querySelectorAll('[data-bt-status]').forEach((el) => {
      el.hidden = false;
      el.className = 'alert alert-' + kind + ' py-2 small no-print mb-2';
      el.textContent = message;
    });
  }

  function opsFrom(btn) {
    const el = document.querySelector(btn.dataset.btOps || '#btReceipt');
    if (!el) { return []; }
    try { return JSON.parse(el.textContent || '[]'); } catch (e) { return []; }
  }

  function bind() {
    const cfg = document.getElementById('btPrinterOptions');
    if (cfg) { try { opts = Object.assign(opts, JSON.parse(cfg.textContent || '{}')); } catch (e) { /* keep the defaults */ } }

    const buttons = document.querySelectorAll('[data-bt-print]');
    if (!buttons.length) { return; }

    const no = whyNot();
    buttons.forEach((btn) => {
      const label = btn.innerHTML;
      btn.addEventListener('click', async () => {
        if (no) { note('warning', no); return; }
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Printing';
        try {
          await print(opsFrom(btn));
          note('success', 'Sent to ' + (state().name || 'the printer') + '.');
        } catch (e) {
          const m = String(e && e.message ? e.message : e);
          note('warning', /cancell?ed|User cancelled/i.test(m)
            ? 'No printer was picked, so nothing was printed.'
            : 'The printer could not be reached: ' + m);
        }
        btn.disabled = false;
        btn.innerHTML = label;
      });
    });

    // the printer that was used last, and printing the receipt the moment it opens
    (async () => {
      if (no) { return; }
      const back = await reconnect();
      if (back && opts.autoprint) {
        const auto = document.querySelector('[data-bt-print][data-bt-auto]');
        if (auto) {
          try {
            await print(opsFrom(auto));
            note('success', 'Printed on ' + (state().name || 'the printer') + '.');
          } catch (e) { /* the button is there to try again */ }
        }
      }
    })();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bind); }
  else { bind(); }

  return { connect, disconnect, print, reconnect, state, whyNot, on, build, encode };
})();
