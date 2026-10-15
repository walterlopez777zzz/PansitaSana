// server/serve_cache.js
// Minimal Express server to serve public/cache with proper ETag / If-None-Match handling
// Usage: node server/serve_cache.js
// env: PORT (default 3000), CACHE_DIR (default ../public/cache relative to server folder)

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const express = require('express');

const PORT = process.env.PORT ? parseInt(process.env.PORT, 10) : 3000;
const CACHE_DIR = process.env.CACHE_DIR || path.join(__dirname, '..', 'public', 'cache');

const app = express();

// optional middlewares if available
try {
  const helmet = require('helmet');
  app.use(helmet());
} catch (e) {}

let metrics = {
  served: 0,
  notModified: 0,
  notFound: 0,
  errors: 0
};

function calcETagForFileSync(filePath) {
  const buf = fs.readFileSync(filePath);
  return crypto.createHash('sha256').update(buf).digest('hex');
}

// Helper to stream file w/ ETag handling
app.get('/cache/*', async (req, res) => {
  try {
    const rel = req.path.replace(/^\/cache\//, '');
    // Prevent path traversal
    if (rel.includes('..')) {
      res.status(400).send('Invalid path');
      return;
    }
    const full = path.join(CACHE_DIR, rel);
    if (!fs.existsSync(full) || !fs.statSync(full).isFile()) {
      metrics.notFound++;
      res.status(404).send('Not found');
      return;
    }

    const stat = fs.statSync(full);
    // compute ETag (SHA256 hex)
    const etag = calcETagForFileSync(full);
    const ifNone = req.headers['if-none-match'] || req.headers['if-none-match'.toLowerCase()];

    res.setHeader('ETag', etag);
    res.setHeader('Last-Modified', stat.mtime.toUTCString());
    // short caching instruct: clients can revalidate with If-None-Match
    res.setHeader('Cache-Control', 'public, max-age=60, must-revalidate');

    if (ifNone && ifNone === etag) {
      metrics.notModified++;
      res.status(304).end();
      return;
    }

    // stream with content-type for json, fallback application/octet-stream
    if (full.endsWith('.json')) res.setHeader('Content-Type', 'application/json; charset=utf-8');
    // send
    const stream = fs.createReadStream(full);
    stream.on('error', (err) => {
      metrics.errors++;
      try { res.status(500).send('Internal server error'); } catch (e) {}
    });
    metrics.served++;
    res.status(200);
    stream.pipe(res);
  } catch (err) {
    metrics.errors++;
    res.status(500).send('Server error');
  }
});

// health
app.get('/health', (req, res) => {
  res.json({ ok: true, time: new Date().toISOString(), cacheDir: CACHE_DIR });
});

// metrics (simple)
app.get('/metrics', (req, res) => {
  const out = Object.entries(metrics).map(([k,v]) => `${k} ${v}`).join('\n') + '\n';
  res.setHeader('Content-Type', 'text/plain; version=0.0.4');
  res.send(out);
});

app.listen(PORT, () => {
  console.log(`Cache server listening on http://localhost:${PORT} serving ${CACHE_DIR}`);
});
