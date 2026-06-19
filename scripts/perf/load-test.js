// k6 load test for the BCC hot-path read endpoints.
// Usage: k6 run -e URL='https://<site>/wp-json/bcc/v1/cards?per_page=20' scripts/perf/load-test.js
import http from 'k6/http';
export const options = {
  insecureSkipTLSVerify: true,        // Local serves a self-signed cert
  vus: Number(__ENV.VUS || 10),
  duration: __ENV.DURATION || '20s',
  summaryTrendStats: ['avg', 'p(50)', 'p(90)', 'p(95)', 'p(99)', 'max'],
};
export default function () {
  http.get(__ENV.URL, { headers: { Accept: 'application/json' } });
}
