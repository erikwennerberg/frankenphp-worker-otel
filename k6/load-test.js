import http from 'k6/http';
import { check, sleep } from 'k6';

// Pass the deployed app's address with: k6 cloud run load-test.js -e TARGET_URL=http://<ip>:8080
const BASE_URL = __ENV.TARGET_URL || 'http://localhost:8080';

const ENDPOINTS = ['/api/orders', '/api/sales/by-category', '/api/sales/by-region'];

export const options = {
  scenarios: {
    browsing: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 10 },
        { duration: '3m', target: 25 },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '15s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500'],
  },
};

export function setup() {
  const res = http.get(`${BASE_URL}/health`);
  check(res, { 'app is healthy before load test': (r) => r.status === 200 });
}

export default function () {
  const path = ENDPOINTS[Math.floor(Math.random() * ENDPOINTS.length)];
  const res = http.get(`${BASE_URL}${path}`, { tags: { endpoint: path } });
  check(res, { 'status is 200': (r) => r.status === 200 });
  sleep(Math.random() * 2 + 1);
}
