const http = require('node:http');
const os = require('node:os');
const fs = require('node:fs/promises');
const path = require('node:path');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');

const execFileAsync = promisify(execFile);
const PORT = Number(process.env.RESTOCLOUD_PRINT_PORT || 3210);
const TOKEN = process.env.RESTOCLOUD_PRINT_TOKEN || '';
const HOST = '127.0.0.1';

function json(res, status, payload) {
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': 'http://localhost',
    'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, X-RestoCloud-Print-Token'
  });
  res.end(JSON.stringify(payload));
}

function authorized(req) {
  return !TOKEN || req.headers['x-restocloud-print-token'] === TOKEN;
}

async function listPrinters() {
  const command = 'Get-Printer | Where-Object { $_.PrinterStatus -ne $null } | Select-Object Name,DriverName,PortName,PrinterStatus,Default | ConvertTo-Json -Compress';
  const { stdout } = await execFileAsync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command], { windowsHide: true, timeout: 10000 });
  const parsed = stdout.trim() ? JSON.parse(stdout) : [];
  const rows = Array.isArray(parsed) ? parsed : [parsed];
  return rows.filter(row => row && row.Name).map(row => ({
    name: String(row.Name),
    driver: String(row.DriverName || ''),
    port: String(row.PortName || ''),
    status: String(row.PrinterStatus || ''),
    isDefault: Boolean(row.Default)
  }));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > 256 * 1024) req.destroy(new Error('Payload demasiado grande'));
    });
    req.on('end', () => resolve(body));
    req.on('error', reject);
  });
}

async function printText(printer, text) {
  if (!printer || typeof printer !== 'string' || printer.length > 150) throw new Error('Impresora inválida');
  const file = path.join(os.tmpdir(), `restocloud-print-${Date.now()}-${Math.random().toString(16).slice(2)}.txt`);
  await fs.writeFile(file, String(text || ''), 'utf8');
  try {
    const command = `$content = Get-Content -LiteralPath ${JSON.stringify(file)} -Raw; $content | Out-Printer -Name ${JSON.stringify(printer)}`;
    await execFileAsync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command], { windowsHide: true, timeout: 30000 });
  } finally {
    await fs.rm(file, { force: true });
  }
}

async function printRaw(printer, data) {
  if (!printer || typeof printer !== 'string' || printer.length > 150) throw new Error('Impresora inválida');
  if (!Buffer.isBuffer(data) || data.length > 256 * 1024) throw new Error('Trabajo de impresión inválido');
  const file = path.join(os.tmpdir(), `restocloud-print-raw-${Date.now()}-${Math.random().toString(16).slice(2)}.bin`);
  await fs.writeFile(file, data);
  try {
    const script = `$file = ${JSON.stringify(file)}; $printer = ${JSON.stringify(printer)}; Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class RawPrinter {
  [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)] public class DOCINFO { public string pDocName; public string pOutputFile; public string pDataType; }
  [DllImport("winspool.drv", SetLastError=true, CharSet=CharSet.Unicode)] public static extern bool OpenPrinter(string pPrinterName, out IntPtr phPrinter, IntPtr pDefault);
  [DllImport("winspool.drv", SetLastError=true)] public static extern bool ClosePrinter(IntPtr hPrinter);
  [DllImport("winspool.drv", SetLastError=true, CharSet=CharSet.Unicode)] public static extern int StartDocPrinter(IntPtr hPrinter, int level, [In] DOCINFO di);
  [DllImport("winspool.drv", SetLastError=true)] public static extern bool EndDocPrinter(IntPtr hPrinter);
  [DllImport("winspool.drv", SetLastError=true)] public static extern int StartPagePrinter(IntPtr hPrinter);
  [DllImport("winspool.drv", SetLastError=true)] public static extern bool EndPagePrinter(IntPtr hPrinter);
  [DllImport("winspool.drv", SetLastError=true)] public static extern bool WritePrinter(IntPtr hPrinter, byte[] pBytes, int dwCount, out int dwWritten);
  public static void Send(string printer, byte[] data) {
    IntPtr handle; if (!OpenPrinter(printer, out handle, IntPtr.Zero)) throw new Exception("No se pudo abrir la impresora");
    try { var info = new DOCINFO { pDocName = "RestoCloud", pDataType = "RAW" }; if (StartDocPrinter(handle, 1, info) == 0) throw new Exception("No se pudo iniciar el trabajo"); try { StartPagePrinter(handle); int written; if (!WritePrinter(handle, data, data.Length, out written) || written != data.Length) throw new Exception("No se pudo enviar el trabajo"); EndPagePrinter(handle); } finally { EndDocPrinter(handle); } } finally { ClosePrinter(handle); }
  }
}
'@; [RawPrinter]::Send($printer, [IO.File]::ReadAllBytes($file));`;
    await execFileAsync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script], { windowsHide: true, timeout: 30000 });
  } finally {
    await fs.rm(file, { force: true });
  }
}

async function handle(req, res) {
  if (req.method === 'OPTIONS') return json(res, 204, {});
  if (!authorized(req)) return json(res, 401, { status: 'error', message: 'Token de impresión inválido' });

  try {
    if (req.method === 'GET' && req.url === '/health') return json(res, 200, { status: 'success', agent: 'restocloud-print-agent', version: '1.0.0' });
    if (req.method === 'GET' && req.url === '/printers') return json(res, 200, { status: 'success', printers: await listPrinters() });
    if (req.method === 'POST' && req.url === '/print') {
      const input = JSON.parse(await readBody(req));
      await printText(input.printer, input.text);
      return json(res, 200, { status: 'success' });
    }
    if (req.method === 'POST' && req.url === '/print-raw') {
      const input = JSON.parse(await readBody(req));
      await printRaw(input.printer, Buffer.from(String(input.data || ''), 'base64'));
      return json(res, 200, { status: 'success' });
    }
    return json(res, 404, { status: 'error', message: 'Ruta no encontrada' });
  } catch (error) {
    console.error('[print-agent]', error.message);
    return json(res, 500, { status: 'error', message: error.message || 'Error de impresión' });
  }
}

http.createServer(handle).listen(PORT, HOST, () => {
  console.log(`RestoCloud Print Agent escuchando en http://${HOST}:${PORT}`);
});
