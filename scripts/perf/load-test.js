// k6 performance harness for the BCC hot-path REST endpoints (bcc/v1).
// LOCAL-ONLY by default: refuses any host other than
// blue-collar-crypto-custom.local / localhost / 127.0.0.1 unless
// -e ALLOW_NON_LOCAL=1 is passed (staging re-run — see docs/TODO.md).
//
// Run from repo root (results are written relative to CWD):
//   k6 run scripts/perf/load-test.js                                        # smoke (default): 1 VU, one pass, checks
//   k6 run -e SCENARIO=baseline -e DURATION=60s scripts/perf/load-test.js   # 1 VU constant, per-endpoint trends
//   k6 run -e SCENARIO=ramp scripts/perf/load-test.js                       # staged ramp to 10 VUs, weighted mix
//   k6 run -e SCENARIO=authed -e AUTH_IDENTIFIER=<email-or-handle> -e AUTH_PASSWORD=<pw> scripts/perf/load-test.js
//   k6 run -e SCENARIO=authed -e BEARER=<jwt> scripts/perf/load-test.js   # pre-minted token (staging: no Mailpit)
//
// Legacy single-URL mode (byte-compatible with the 2026-06-19 baseline
// methodology in docs/capacity-model.md — used for the staging re-run and
// the with/without-Redis single-endpoint comparison):
//   k6 run -e URL='http://blue-collar-crypto-custom.local/wp-json/bcc/v1/cards?per_page=20' -e VUS=10 -e DURATION=15s scripts/perf/load-test.js
//   ... -e CACHE_BUST=1   # unique _cb= param per request: forces LiteSpeed
//                         # edge MISSES so origin takes every hit (stampede
//                         # regime); lsc_hit/lsc_miss counters report the split
//
// Optional env: BASE_URL (default http://blue-collar-crypto-custom.local),
//   MAILPIT_URL (default http://127.0.0.1:10006), AUTH_EMAIL (mailbox when
//   AUTH_IDENTIFIER is a handle), BEARER (pre-minted JWT — skips the Mailpit
//   login flow entirely), USER_HANDLE (for /users/:handle; otherwise
//   discovered from /members in setup), VUS, DURATION, ALLOW_NON_LOCAL=1,
//   ALLOW_UNCAPPED=1 (lift the local 25-VU/5-min caps).
//   The authed scenario iterates a weighted logged-in session mix (feed 40%,
//   me/notifications 15%, me/watching 10%, me/badges 5%, rotating profile
//   views 20%, cards/members/groups tail 10%).
// Results: scripts/perf/results/<timestamp>-<scenario>.{json,md} (gitignored).
// Exit codes: 108 = guard/setup abort, 99 = threshold failed, 0 = pass.

import http from 'k6/http';
import exec from 'k6/execution';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.1.0/index.js';

const LEGACY_URL = __ENV.URL || '';
const SCENARIO   = LEGACY_URL ? 'legacy' : (__ENV.SCENARIO || 'smoke').toLowerCase();
const BASE_URL   = (__ENV.BASE_URL || 'http://blue-collar-crypto-custom.local').replace(/\/+$/, '');
const API        = `${BASE_URL}/wp-json/bcc/v1`;
const MAILPIT    = (__ENV.MAILPIT_URL || 'http://127.0.0.1:10006').replace(/\/+$/, '');

// ── LOCAL-ONLY guard — init context, so it aborts (exit 108) before any
//    VU starts and before a single request is issued. ─────────────────────
const LOCAL_HOSTS = ['blue-collar-crypto-custom.local', 'localhost', '127.0.0.1'];

function hostOf(url) {
  const m = /^https?:\/\/([^/:?#]+)/i.exec(url || '');
  return m ? m[1].toLowerCase() : null;
}

const targetHost = hostOf(LEGACY_URL || BASE_URL);
const isLocal = targetHost !== null && LOCAL_HOSTS.includes(targetHost);

if (!isLocal && __ENV.ALLOW_NON_LOCAL !== '1') {
  exec.test.abort(
    `Refusing non-local target "${targetHost}". Allowed hosts: ${LOCAL_HOSTS.join(', ')}. ` +
    'Pass -e ALLOW_NON_LOCAL=1 only for a provisioned staging box (docs/TODO.md).'
  );
}
if (SCENARIO === 'authed' && !__ENV.BEARER && (!__ENV.AUTH_IDENTIFIER || !__ENV.AUTH_PASSWORD)) {
  exec.test.abort('SCENARIO=authed requires -e BEARER=<jwt> (pre-minted) or -e AUTH_IDENTIFIER=... -e AUTH_PASSWORD=... (Mailpit auto-auth, local only).');
}

// Local runs are capped so a fat-fingered VUS/DURATION can't flatten the
// dev box (2 static FPM workers). ALLOW_UNCAPPED=1 lifts both caps.
const CAP_VUS = 25;
const CAP_SECONDS = 300;
const uncapped = !isLocal || __ENV.ALLOW_UNCAPPED === '1';

function toSeconds(d) {
  const m = /^(\d+)(s|m|h)$/.exec(d || '');
  return m ? Number(m[1]) * { s: 1, m: 60, h: 3600 }[m[2]] : null;
}
function clampVus(v) {
  if (!uncapped && v > CAP_VUS) {
    console.warn(`VUS ${v} clamped to local cap ${CAP_VUS} (ALLOW_UNCAPPED=1 to lift)`);
    return CAP_VUS;
  }
  return v;
}
function clampDur(d) {
  const s = toSeconds(d);
  if (!uncapped && (s === null || s > CAP_SECONDS)) {
    console.warn(`DURATION ${d} clamped to local cap ${CAP_SECONDS}s (ALLOW_UNCAPPED=1 to lift)`);
    return `${CAP_SECONDS}s`;
  }
  return d;
}

// ── Endpoint table — the anon hot reads from docs/api-contract-v1.md §4.
//    `name` tags collapse per-URL variants (search terms) into one summary
//    row; the custom bcc_* Trends give threshold-free per-endpoint latency
//    rows regardless of tag handling. ────────────────────────────────────
const SEARCH_TERMS = ['sol', 'eth', 'validator', 'node', 'btc'];
const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];

const ENDPOINTS = {
  feed_hot:     () => `${API}/feed/hot?per_page=20`,
  cards:        () => `${API}/cards?per_page=20`,
  members:      () => `${API}/members?per_page=20`,
  search:       () => `${API}/search?q=${pick(SEARCH_TERMS)}`,
  groups:       () => `${API}/groups`,
  user_profile: (data) => `${API}/users/${__ENV.USER_HANDLE || (data && data.handle) || 'phillipcosmos'}`,
  locals:       () => `${API}/locals`,
  cards_search: () => `${API}/cards/search?q=${pick(SEARCH_TERMS)}`,
};

// Authed logged-in session mix (2026-07-12 staging finding: LiteSpeed serves
// cached ANON variants to Authorization-bearing requests on the
// Anonymous-OR-Bearer routes, so real logged-in ORIGIN load concentrates on
// the Bearer-required surfaces below; the cards/members/groups tail is kept
// at realistic weight — those being edge-served IS production behavior).
const AUTHED_ENDPOINTS = {
  feed_authed:      () => `${API}/feed?per_page=20`,
  me_notifications: () => `${API}/me/notifications`,
  me_watching:      () => `${API}/me/watching`,
  me_badges:        () => `${API}/me/badges`,
  profile_rotate:   (data) => {
    const handles = (data && data.handles && data.handles.length)
      ? data.handles
      : [__ENV.USER_HANDLE || 'phillipcosmos'];
    return `${API}/users/${pick(handles)}`;
  },
  cards:   ENDPOINTS.cards,
  members: ENDPOINTS.members,
  groups:  ENDPOINTS.groups,
};
const AUTHED_MIX = [['feed_authed', 40], ['me_notifications', 15], ['me_watching', 10],
                    ['me_badges', 5], ['profile_rotate', 20], ['cards', 4], ['members', 3], ['groups', 3]];

const TRENDS = {};
for (const key of Object.keys(ENDPOINTS)) {
  TRENDS[key] = new Trend(`bcc_${key}_ms`, true);
}
for (const key of Object.keys(AUTHED_ENDPOINTS)) {
  if (!TRENDS[key]) {
    TRENDS[key] = new Trend(`bcc_${key}_ms`, true);
  }
}

function hitEndpoint(key, data, token, table = ENDPOINTS) {
  const url = table[key](data);
  const params = { headers: { Accept: 'application/json' }, tags: { name: key, endpoint: key } };
  if (token) {
    params.headers.Authorization = `Bearer ${token}`;
  }
  const res = http.get(url, params);
  TRENDS[key].add(res.timings.duration, { endpoint: key });
  check(res, {
    [`${key}: status 200`]: (r) => r.status === 200,
    [`${key}: envelope has data`]: (r) => {
      try { return r.json('data') !== undefined; } catch (e) { return false; }
    },
  }, { endpoint: key });
}

// ── Scenario map — k6 runs every scenario defined in options.scenarios,
//    so exactly ONE (selected by -e SCENARIO=) is exported. ──────────────
const ALL_SCENARIOS = {
  smoke:    { executor: 'per-vu-iterations', vus: 1, iterations: 1, exec: 'smokePass', maxDuration: '2m' },
  baseline: { executor: 'constant-vus', vus: clampVus(Number(__ENV.VUS || 1)),
              duration: clampDur(__ENV.DURATION || '60s'), exec: 'baselinePass' },
  ramp:     { executor: 'ramping-vus', startVUs: 0, exec: 'weightedMixPass',
              stages: [{ duration: '30s', target: 3 }, { duration: '1m', target: 10 },
                       { duration: '1m', target: 10 }, { duration: '30s', target: 0 }] },
  authed:   { executor: 'constant-vus', vus: clampVus(Number(__ENV.VUS || 2)),
              duration: clampDur(__ENV.DURATION || '60s'), exec: 'authedPass' },
  legacy:   { executor: 'constant-vus', vus: clampVus(Number(__ENV.VUS || 10)),
              duration: clampDur(__ENV.DURATION || '20s'), exec: 'legacyPass' },
};
if (!ALL_SCENARIOS[SCENARIO]) {
  exec.test.abort(`Unknown SCENARIO "${SCENARIO}". One of: ${Object.keys(ALL_SCENARIOS).join(', ')}`);
}

export const options = {
  insecureSkipTLSVerify: true, // Local serves a self-signed cert
  // The authed Mailpit poll alone can take 60s (30 × 2s); the k6 default
  // setupTimeout is also 60s, so a slow OTP e-mail times setup() out.
  setupTimeout: '150s',
  scenarios: { [SCENARIO]: ALL_SCENARIOS[SCENARIO] },
  // Unchanged from the 2026-06-19 baseline so runs stay comparable.
  summaryTrendStats: ['avg', 'p(50)', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    http_req_failed: ['rate<0.01'],
    checks:          ['rate>0.99'],
    // Latency deliberately has NO thresholds on Local: the ~2-worker FPM
    // pool + cron/indexer ticks dominate the tails (see capacity-model.md).
    // The bcc_* Trends are informational; compare against the recorded
    // baseline by hand.
  },
};

// ── setup(): runs ONCE. Discovers a profile handle for /users/:handle and,
//    for SCENARIO=authed, performs the full Mailpit-driven 2FA login. ─────
export function setup() {
  const data = { token: null, handle: null };
  if (SCENARIO === 'legacy') {
    return data;
  }

  const r = http.get(`${API}/members?per_page=10`, { headers: { Accept: 'application/json' } });
  try {
    const members = r.json('data.items') || [];
    data.handles = members.map((m) => m.handle).filter(Boolean);
    if (!__ENV.USER_HANDLE && data.handles.length) {
      data.handle = data.handles[0];
    }
  } catch (e) { /* smoke checks will surface a broken /members */ }

  if (SCENARIO !== 'authed') {
    return data;
  }

  // Pre-minted token path (staging: Mailpit doesn't exist there; the JWT is
  // minted server-side via wp-cli instead).
  if (__ENV.BEARER) {
    data.token = __ENV.BEARER;
    return data;
  }

  // Mailpit reachability first — the port drifts when Local re-provisions.
  const ping = http.get(`${MAILPIT}/api/v1/messages?limit=1`);
  if (ping.status !== 200) {
    exec.test.abort(`Mailpit not reachable at ${MAILPIT} (HTTP ${ping.status}). ` +
      'Find the current port via Local UI > Tools > Mailpit and pass -e MAILPIT_URL=...');
  }

  // 1. Login → 2FA challenge. Throttled 5/60s per IP and 20/900s per
  //    identifier — which is why auth happens once here, never per VU.
  const t0 = Date.now();
  const login = http.post(`${API}/auth/login`,
    JSON.stringify({ identifier: __ENV.AUTH_IDENTIFIER, password: __ENV.AUTH_PASSWORD }),
    { headers: { 'Content-Type': 'application/json' } });
  if (login.status === 429) {
    exec.test.abort('Login throttled (bcc_rate_limited). Wait 60s (IP bucket) or 15min (identifier bucket).');
  }
  let challenge = null;
  try {
    if (login.status === 200 && login.json('data.status') === '2fa_required') {
      challenge = login.json('data.challenge_token');
    }
  } catch (e) { /* fall through to abort */ }
  if (!challenge) {
    exec.test.abort(`Login failed (HTTP ${login.status}): ${login.body && login.body.slice(0, 200)}`);
  }

  // 2. Poll Mailpit for the OTP email. The 2FA mail is HTML-only
  //    (BccMailer forces text/html), so the 6-digit code is regexed out of
  //    the HTML part; Text/Snippet is a fallback in case a plain-text part
  //    ever ships. Stale codes from earlier runs are skipped via Created>=t0.
  const mailbox = (__ENV.AUTH_EMAIL ||
    (__ENV.AUTH_IDENTIFIER.includes('@') ? __ENV.AUTH_IDENTIFIER : '')).toLowerCase();
  let otp = null;
  for (let attempt = 0; attempt < 30 && !otp; attempt++) {
    sleep(2);
    const listUrl = mailbox
      ? `${MAILPIT}/api/v1/search?query=${encodeURIComponent('to:"' + mailbox + '"')}&limit=5`
      : `${MAILPIT}/api/v1/messages?limit=5`;
    let msgs = [];
    try { msgs = http.get(listUrl).json('messages') || []; } catch (e) { continue; }
    for (const m of msgs) {
      if (Date.parse(m.Created) < t0 - 2000) continue;
      if (!/login verification code/i.test(m.Subject || '')) continue;
      let detail = null;
      try { detail = http.get(`${MAILPIT}/api/v1/message/${m.ID}`).json(); } catch (e) { continue; }
      const html = (detail && detail.HTML) || '';
      const text = (detail && (detail.Text || detail.Snippet)) || '';
      const match = html.match(/>\s*(\d{6})\s*</) || text.match(/\b(\d{6})\b/);
      if (match) { otp = match[1]; break; }
    }
  }
  if (!otp) {
    exec.test.abort(`No 2FA OTP found in Mailpit within 60s for ${mailbox || '(subject match)'} — check ${MAILPIT}.`);
  }

  // 3. Verify → JWT (7-day TTL; reused by every VU via the data object).
  const verify = http.post(`${API}/auth/2fa/verify`,
    JSON.stringify({ challenge_token: challenge, code: otp }),
    { headers: { 'Content-Type': 'application/json' } });
  let token = null;
  try { token = verify.status === 200 ? verify.json('data.token') : null; } catch (e) { /* abort below */ }
  if (!token) {
    exec.test.abort(`2FA verify failed (HTTP ${verify.status}): ${verify.body && verify.body.slice(0, 200)}`);
  }
  console.log(`setup(): authenticated; JWT valid for ${verify.json('data.expires_in')}s`);
  data.token = token;
  return data;
}

// ── Scenario exec functions ────────────────────────────────────────────
const ANON_KEYS = Object.keys(ENDPOINTS);

export function smokePass(data) {
  for (const key of ANON_KEYS) { hitEndpoint(key, data); sleep(0.3); }
}

// 1 VU sequential round-robin = the warm-cache methodology of the recorded
// baseline (docs/capacity-model.md "Measured baseline").
export function baselinePass(data) {
  for (const key of ANON_KEYS) { hitEndpoint(key, data); sleep(0.5); }
}
export default smokePass;

// Weighted mix approximating real traffic shares for the ramp scenario.
const MIX = [['feed_hot', 35], ['cards', 20], ['members', 10], ['search', 10],
             ['groups', 5], ['user_profile', 5], ['locals', 5], ['cards_search', 10]];
const MIX_TOTAL = MIX.reduce((s, [, w]) => s + w, 0);
function pickWeighted() {
  let r = Math.random() * MIX_TOTAL;
  for (const [key, w] of MIX) {
    r -= w;
    if (r < 0) return key;
  }
  return MIX[0][0];
}
export function weightedMixPass(data) {
  hitEndpoint(pickWeighted(), data);
  sleep(1);
}

// Weighted authed-session mix — every request carries the Bearer.
const AUTHED_MIX_TOTAL = AUTHED_MIX.reduce((s, [, w]) => s + w, 0);
function pickAuthed() {
  let r = Math.random() * AUTHED_MIX_TOTAL;
  for (const [key, w] of AUTHED_MIX) {
    r -= w;
    if (r < 0) return key;
  }
  return AUTHED_MIX[0][0];
}
export function authedPass(data) {
  hitEndpoint(pickAuthed(), data, data.token, AUTHED_ENDPOINTS);
  sleep(1);
}

// Byte-compatible with the pre-harness 13-line script — the recorded
// baseline and the staging/Redis methodology depend on this exact shape.
// Two opt-ins for the edge-cache-miss regime (2026-07-15, capacity-model
// "Still unmeasured" §3): -e CACHE_BUST=1 appends a per-request unique
// param so EVERY request misses the LiteSpeed edge and hits origin PHP
// (forced-miss / stampede shape); the lsc_* counters split responses by
// the x-litespeed-cache header so TTL-roll runs can report hit ratio.
// Default behavior (no env) is byte-identical to the recorded baseline.
const lscCounters = {
  hit:   new Counter('lsc_hit'),
  miss:  new Counter('lsc_miss'),
  other: new Counter('lsc_none'),
};
export function legacyPass() {
  const url = __ENV.CACHE_BUST === '1'
    ? LEGACY_URL + (LEGACY_URL.includes('?') ? '&' : '?') +
      `_cb=${exec.vu.idInTest}-${exec.vu.iterationInScenario}`
    : LEGACY_URL;
  const res = http.get(url, { headers: { Accept: 'application/json' } });
  const lsc = (res.headers['X-Litespeed-Cache'] || '').toLowerCase();
  (lscCounters[lsc] || lscCounters.other).add(1);
}

// ── Summary: console (unchanged) + timestamped JSON/markdown artifacts. ──
export function handleSummary(data) {
  const ts = new Date().toISOString().replace(/[:.]/g, '-'); // Windows-safe filename
  const base = `scripts/perf/results/${ts}-${SCENARIO}`;
  return {
    stdout: textSummary(data, { indent: ' ', enableColors: true }),
    [`${base}.json`]: JSON.stringify(data, null, 2),
    [`${base}.md`]: mdSummary(data),
  };
}

function mdSummary(data) {
  const rows = [
    `# k6 ${SCENARIO} — ${new Date().toISOString()}`,
    '',
    `Target: ${LEGACY_URL || API}`,
    '',
    '| endpoint | avg | p50 | p90 | p95 | p99 | max |',
    '|---|---|---|---|---|---|---|',
  ];
  for (const [name, m] of Object.entries(data.metrics)) {
    if (!name.startsWith('bcc_') || !m.values) continue;
    const v = m.values;
    const f = (x) => (x === undefined ? '-' : `${x.toFixed(0)}ms`);
    rows.push(`| ${name.replace(/^bcc_|_ms$/g, '')} | ${f(v.avg)} | ${f(v['p(50)'])} | ${f(v['p(90)'])} | ${f(v['p(95)'])} | ${f(v['p(99)'])} | ${f(v.max)} |`);
  }
  const fails = data.metrics.http_req_failed;
  rows.push('', `http_req_failed rate: ${fails ? (fails.values.rate * 100).toFixed(2) : '?'}%`);
  return rows.join('\n') + '\n';
}
