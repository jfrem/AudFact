// ZIP sin compresión para empaquetar las partes de un XLSX sin dependencias.
// Formato: PKWARE APPNOTE 6.3.10, secciones 4.3.7, 4.3.12 y 4.3.16.
const CRC_TABLE = Uint32Array.from({ length: 256 }, (_, value) => {
  for (let bit = 0; bit < 8; bit++) {
    value = (value >>> 1) ^ ((value & 1) ? 0xedb88320 : 0);
  }
  return value >>> 0;
});

function crc32(bytes: Uint8Array): number {
  let crc = 0xffffffff;
  for (const byte of bytes) crc = (crc >>> 8) ^ CRC_TABLE[(crc ^ byte) & 0xff];
  return (crc ^ 0xffffffff) >>> 0;
}

export function createStoredZip(files: Readonly<Record<string, string>>): ArrayBuffer {
  const encoder = new TextEncoder();
  const entries = Object.entries(files).map(([name, content]) => ({
    name: encoder.encode(name),
    data: encoder.encode(content),
  }));
  const localSize = entries.reduce((sum, entry) => sum + 30 + entry.name.length + entry.data.length, 0);
  const directorySize = entries.reduce((sum, entry) => sum + 46 + entry.name.length, 0);
  const size = localSize + directorySize + 22;
  if (entries.length > 0xffff || size > 0xffffffff || entries.some(({ name }) => name.length > 0xffff)) {
    throw new Error("El archivo excede los límites del formato ZIP sin ZIP64.");
  }

  const buffer = new ArrayBuffer(size);
  const bytes = new Uint8Array(buffer);
  const view = new DataView(buffer);
  let localOffset = 0;
  let directoryOffset = localSize;

  for (const { name, data } of entries) {
    const checksum = crc32(data);
    view.setUint32(localOffset, 0x04034b50, true);
    view.setUint16(localOffset + 4, 20, true); // ZIP 2.0
    view.setUint16(localOffset + 6, 0x0800, true); // Nombres UTF-8
    view.setUint16(localOffset + 12, 0x0021, true); // Fecha fija: 1980-01-01
    view.setUint32(localOffset + 14, checksum, true);
    view.setUint32(localOffset + 18, data.length, true);
    view.setUint32(localOffset + 22, data.length, true);
    view.setUint16(localOffset + 26, name.length, true);
    bytes.set(name, localOffset + 30);
    bytes.set(data, localOffset + 30 + name.length);

    view.setUint32(directoryOffset, 0x02014b50, true);
    view.setUint16(directoryOffset + 4, 20, true);
    view.setUint16(directoryOffset + 6, 20, true);
    view.setUint16(directoryOffset + 8, 0x0800, true);
    view.setUint16(directoryOffset + 14, 0x0021, true);
    view.setUint32(directoryOffset + 16, checksum, true);
    view.setUint32(directoryOffset + 20, data.length, true);
    view.setUint32(directoryOffset + 24, data.length, true);
    view.setUint16(directoryOffset + 28, name.length, true);
    view.setUint32(directoryOffset + 42, localOffset, true);
    bytes.set(name, directoryOffset + 46);

    localOffset += 30 + name.length + data.length;
    directoryOffset += 46 + name.length;
  }

  view.setUint32(directoryOffset, 0x06054b50, true);
  view.setUint16(directoryOffset + 8, entries.length, true);
  view.setUint16(directoryOffset + 10, entries.length, true);
  view.setUint32(directoryOffset + 12, directorySize, true);
  view.setUint32(directoryOffset + 16, localSize, true);
  return buffer;
}
