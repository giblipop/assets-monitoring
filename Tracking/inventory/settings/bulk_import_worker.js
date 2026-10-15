importScripts('./format/vendor/xlsx.full.min.js');

function zipDirectory(buffer) {
  const view = new DataView(buffer);
  const minimum = Math.max(0, view.byteLength - 65557);
  let endOffset = -1;
  for (let offset = view.byteLength - 22; offset >= minimum; offset--) {
    if (view.getUint32(offset, true) === 0x06054b50) {
      endOffset = offset;
      break;
    }
  }
  if (endOffset < 0) throw new Error('The workbook archive is incomplete.');
  const entryCount = view.getUint16(endOffset + 10, true);
  let offset = view.getUint32(endOffset + 16, true);
  const decoder = new TextDecoder();
  const entries = new Map();
  for (let index = 0; index < entryCount; index++) {
    if (view.getUint32(offset, true) !== 0x02014b50) {
      throw new Error('The workbook contains an unreadable archive directory.');
    }
    const method = view.getUint16(offset + 10, true);
    const compressedSize = view.getUint32(offset + 20, true);
    const nameLength = view.getUint16(offset + 28, true);
    const extraLength = view.getUint16(offset + 30, true);
    const commentLength = view.getUint16(offset + 32, true);
    const localOffset = view.getUint32(offset + 42, true);
    const name = decoder.decode(new Uint8Array(buffer, offset + 46, nameLength));
    entries.set(name, { method: method, compressedSize: compressedSize, localOffset: localOffset });
    offset += 46 + nameLength + extraLength + commentLength;
  }
  return { view: view, entries: entries };
}

async function zipEntryText(archive, buffer, path) {
  const entry = archive.entries.get(path);
  if (!entry) return '';
  const view = archive.view;
  const localOffset = entry.localOffset;
  if (view.getUint32(localOffset, true) !== 0x04034b50) {
    throw new Error('The workbook contains an unreadable file entry.');
  }
  const nameLength = view.getUint16(localOffset + 26, true);
  const extraLength = view.getUint16(localOffset + 28, true);
  const start = localOffset + 30 + nameLength + extraLength;
  const compressed = buffer.slice(start, start + entry.compressedSize);
  let bytes;
  if (entry.method === 0) {
    bytes = compressed;
  } else if (entry.method === 8 && typeof DecompressionStream === 'function') {
    const stream = new Blob([compressed]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
    bytes = await new Response(stream).arrayBuffer();
  } else {
    throw new Error('This browser cannot extract embedded workbook images. Update the browser and try again.');
  }
  return new TextDecoder().decode(bytes);
}

async function zipEntryBytes(archive, buffer, path) {
  const entry = archive.entries.get(path);
  if (!entry) return null;
  const view = archive.view;
  const localOffset = entry.localOffset;
  if (view.getUint32(localOffset, true) !== 0x04034b50) {
    throw new Error('The workbook contains an unreadable image file.');
  }
  const nameLength = view.getUint16(localOffset + 26, true);
  const extraLength = view.getUint16(localOffset + 28, true);
  const start = localOffset + 30 + nameLength + extraLength;
  const compressed = buffer.slice(start, start + entry.compressedSize);
  if (entry.method === 0) return compressed;
  if (entry.method !== 8 || typeof DecompressionStream !== 'function') {
    throw new Error('This browser cannot extract embedded workbook images. Update the browser and try again.');
  }
  const stream = new Blob([compressed]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
  return new Response(stream).arrayBuffer();
}

function xmlAttribute(attributes, name) {
  const match = attributes.match(new RegExp('(?:^|\\s)' + name.replace(':', '\\:') + '="([^"]*)"', 'i'));
  return match ? match[1].replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>').replace(/&apos;/g, "'") : '';
}

function resolveZipPath(basePath, target) {
  const pieces = (target.charAt(0) === '/' ? target.slice(1) : basePath + '/' + target).split('/');
  const resolved = [];
  pieces.forEach(function (piece) {
    if (!piece || piece === '.') return;
    if (piece === '..') resolved.pop();
    else resolved.push(piece);
  });
  return resolved.join('/');
}

function parseRelationships(xml) {
  const relationships = Object.create(null);
  const tags = xml.match(/<Relationship\b[^>]*\/?>/g) || [];
  tags.forEach(function (tag) {
    const attributes = tag.slice('<Relationship'.length).replace(/\/?>$/, '');
    const id = xmlAttribute(attributes, 'Id');
    if (!id) return;
    relationships[id] = {
      type: xmlAttribute(attributes, 'Type'),
      target: xmlAttribute(attributes, 'Target'),
      external: xmlAttribute(attributes, 'TargetMode') === 'External'
    };
  });
  return relationships;
}

async function extractEmbeddedImages(buffer, sheetNames) {
  const archive = zipDirectory(buffer);
  const workbookXml = await zipEntryText(archive, buffer, 'xl/workbook.xml');
  const workbookRels = parseRelationships(
    await zipEntryText(archive, buffer, 'xl/_rels/workbook.xml.rels')
  );
  const worksheetPaths = Object.create(null);
  (workbookXml.match(/<sheet\b[^>]*\/?>/g) || []).forEach(function (tag) {
    const attributes = tag.slice('<sheet'.length).replace(/\/?>$/, '');
    const name = xmlAttribute(attributes, 'name');
    const relationId = xmlAttribute(attributes, 'r:id');
    const relation = workbookRels[relationId];
    if (name && relation && !relation.external) {
      worksheetPaths[name] = resolveZipPath('xl', relation.target);
    }
  });

  const imagesBySheet = Object.create(null);
  let totalBytes = 0;
  Object.keys(worksheetPaths).forEach(function (name) { imagesBySheet[name] = []; });
  for (const sheetName of sheetNames) {
    const worksheetPath = worksheetPaths[sheetName];
    if (!worksheetPath) continue;
    const worksheetDirectory = worksheetPath.slice(0, worksheetPath.lastIndexOf('/'));
    const worksheetFile = worksheetPath.slice(worksheetPath.lastIndexOf('/') + 1);
    const worksheetRelsPath = worksheetDirectory + '/_rels/' + worksheetFile + '.rels';
    const worksheetRels = parseRelationships(await zipEntryText(archive, buffer, worksheetRelsPath));
    const drawingRelation = Object.keys(worksheetRels).map(function (id) {
      return worksheetRels[id];
    }).find(function (relation) { return !relation.external && /\/drawing$/.test(relation.type); });
    if (!drawingRelation) continue;
    const drawingPath = resolveZipPath(worksheetDirectory, drawingRelation.target);
    const drawingDirectory = drawingPath.slice(0, drawingPath.lastIndexOf('/'));
    const drawingFile = drawingPath.slice(drawingPath.lastIndexOf('/') + 1);
    const drawingXml = await zipEntryText(archive, buffer, drawingPath);
    const drawingRels = parseRelationships(
      await zipEntryText(archive, buffer, drawingDirectory + '/_rels/' + drawingFile + '.rels')
    );
    const anchors = drawingXml.match(/<(?:[A-Za-z0-9_]+:)?(?:twoCellAnchor|oneCellAnchor)\b[^>]*>[\s\S]*?<\/(?:[A-Za-z0-9_]+:)?(?:twoCellAnchor|oneCellAnchor)>/g) || [];
    for (const anchor of anchors) {
      const from = anchor.match(/<(?:[A-Za-z0-9_]+:)?from\b[^>]*>([\s\S]*?)<\/(?:[A-Za-z0-9_]+:)?from>/);
      const picture = anchor.match(/<(?:[A-Za-z0-9_]+:)?pic\b[^>]*>([\s\S]*?)<\/(?:[A-Za-z0-9_]+:)?pic>/);
      if (!from || !picture) continue;
      const rowMatch = from[1].match(/<(?:[A-Za-z0-9_]+:)?row\b[^>]*>(\d+)<\/(?:[A-Za-z0-9_]+:)?row>/);
      const columnMatch = from[1].match(/<(?:[A-Za-z0-9_]+:)?col\b[^>]*>(\d+)<\/(?:[A-Za-z0-9_]+:)?col>/);
      const embedMatch = picture[1].match(/<(?:[A-Za-z0-9_]+:)?blip\b[^>]*(?:r:embed|embed)="([^"]+)"/);
      if (!rowMatch || !columnMatch || !embedMatch) continue;
      const mediaRelation = drawingRels[embedMatch[1]];
      if (!mediaRelation || mediaRelation.external) continue;
      const mediaPath = resolveZipPath(drawingDirectory, mediaRelation.target);
      const extensionMatch = mediaPath.match(/\.([a-z0-9]+)$/i);
      const extension = extensionMatch ? extensionMatch[1].toLowerCase() : '';
      const mimeTypes = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' };
      if (!mimeTypes[extension]) continue;
      const data = await zipEntryBytes(archive, buffer, mediaPath);
      if (!data) continue;
      totalBytes += data.byteLength;
      if (data.byteLength > 20 * 1024 * 1024 || totalBytes > 100 * 1024 * 1024) {
        throw new Error('Embedded workbook images exceed the 100 MB total or 20 MB per-image import limit.');
      }
      imagesBySheet[sheetName].push({
        row: Number(rowMatch[1]),
        column: Number(columnMatch[1]),
        name: mediaPath.slice(mediaPath.lastIndexOf('/') + 1),
        mime_type: mimeTypes[extension],
        data: data
      });
    }
  }
  return imagesBySheet;
}

self.onmessage = function (event) {
  try {
    const buffer = event.data && event.data.buffer instanceof ArrayBuffer ? event.data.buffer : event.data;
    const workbook = XLSX.read(buffer, { type: 'array', cellDates: false });
    const shouldExtractImages = event.data && event.data.extractImages === true;
    const names = workbook.SheetNames;
    const sheets = names.map(function (name) {
      const worksheet = workbook.Sheets[name];
      if (!worksheet || !worksheet['!ref']) {
        return {
          name: name,
          bounds: { s: { r: 0, c: 0 }, e: { r: 0, c: 0 } },
          matrix: [],
          merges: [],
          empty: true
        };
      }
      let minRow = Infinity;
      let minColumn = Infinity;
      let maxRow = -1;
      let maxColumn = -1;
      Object.keys(worksheet).forEach(function (address) {
        if (address.charAt(0) === '!') return;
        const cell = worksheet[address];
        if (!cell || (cell.v === undefined && !cell.f)) return;
        const point = XLSX.utils.decode_cell(address);
        minRow = Math.min(minRow, point.r);
        minColumn = Math.min(minColumn, point.c);
        maxRow = Math.max(maxRow, point.r);
        maxColumn = Math.max(maxColumn, point.c);
      });
      const merges = worksheet['!merges'] || [];
      if (maxRow < 0) {
        return {
          name: name,
          bounds: { s: { r: 0, c: 0 }, e: { r: 0, c: 0 } },
          matrix: [],
          merges: merges,
          empty: true
        };
      }
      merges.forEach(function (merge) {
        if (merge.e.r < minRow || merge.s.r > maxRow || merge.e.c < minColumn || merge.s.c > maxColumn) return;
        minRow = Math.min(minRow, merge.s.r);
        minColumn = Math.min(minColumn, merge.s.c);
        maxRow = Math.max(maxRow, merge.e.r);
        maxColumn = Math.max(maxColumn, merge.e.c);
      });
      const bounds = {
        s: { r: minRow, c: minColumn },
        e: { r: maxRow, c: maxColumn }
      };
      const range = XLSX.utils.encode_range(bounds);
      return {
        name: name,
        bounds: bounds,
        matrix: XLSX.utils.sheet_to_json(worksheet, {
          header: 1,
          defval: null,
          raw: false,
          blankrows: true,
          range: range
        }),
        links: Object.keys(worksheet).reduce(function (links, address) {
          if (address.charAt(0) === '!') return links;
          const cell = worksheet[address];
          const target = cell && cell.l && cell.l.Target;
          if (typeof target === 'string') {
            const point = XLSX.utils.decode_cell(address);
            links.push({ row: point.r, column: point.c, target: target });
          }
          return links;
        }, []),
        merges: merges,
        empty: false
      };
    });
    const imagePromise = shouldExtractImages
      ? extractEmbeddedImages(buffer, names)
      : Promise.resolve(Object.create(null));
    imagePromise.then(function (images) {
      const transfers = [];
      sheets.forEach(function (sheet) {
        sheet.images = images[sheet.name] || [];
        sheet.images.forEach(function (image) { transfers.push(image.data); });
      });
      self.postMessage({ sheets: sheets }, transfers);
    }).catch(function (error) {
      self.postMessage({ error: error && error.message ? error.message : 'Embedded workbook images could not be extracted.' });
    });
  } catch (error) {
    self.postMessage({ error: error && error.message ? error.message : 'The workbook could not be processed.' });
  }
};
