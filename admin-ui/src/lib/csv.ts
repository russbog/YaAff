// Lightweight client-side CSV export (no heavy spreadsheet dependency).
function escapeCell(value: string): string {
  if (/[",\n\r]/.test(value)) {
    return `"${value.replace(/"/g, '""')}"`;
  }
  return value;
}

export function toCsv(headers: string[], rows: string[][]): string {
  const lines = [headers, ...rows].map((cols) => cols.map(escapeCell).join(','));
  return lines.join('\r\n');
}

export function downloadCsv(filename: string, headers: string[], rows: string[][]): void {
  // Prefix BOM so Excel detects UTF-8.
  const blob = new Blob(['\ufeff' + toCsv(headers, rows)], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
