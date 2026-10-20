// k6 load test for the platform's hot paths. Run against staging, never production:
//
//   k6 run ops/load/platform.js \
//     -e ROOT=staging.milesoftwares.com \
//     -e CLIENT_ID=... -e CLIENT_SECRET=... \
//     -e ORG_ID=<organization uuid> -e MEMBER_SUB=<user uuid> \
//     -e MODULE=finance
//
// The service client needs scopes access.read and events.issue, and its
// application (and MODULE) must be entitled for ORG_ID. Create one with
// `php artisan platform:sample-app --entitle=<slug>` on staging.
//
// Scenarios run together. Targets come from docs/production-checklist.md.
import http from 'k6/http';
import { check, fail } from 'k6';

const ROOT = __ENV.ROOT || fail('set ROOT');
const AUTH = `https://auth.${ROOT}`;
const SITE = `https://${ROOT}`;

export const options = {
    scenarios: {
        // Visitors on the public, server-rendered site.
        marketing: {
            executor: 'constant-arrival-rate',
            exec: 'marketing',
            rate: Number(__ENV.SITE_RPS || 30),
            timeUnit: '1s',
            duration: __ENV.DURATION || '5m',
            preAllocatedVUs: 50,
        },
        // Products checking a member's access on every request they serve.
        effectiveAccess: {
            executor: 'constant-arrival-rate',
            exec: 'effectiveAccess',
            rate: Number(__ENV.API_RPS || 100),
            timeUnit: '1s',
            duration: __ENV.DURATION || '5m',
            preAllocatedVUs: 100,
        },
        // Products asking for event tokens before calling shared modules.
        eventTokens: {
            executor: 'constant-arrival-rate',
            exec: 'eventTokens',
            rate: Number(__ENV.EVENT_RPS || 10),
            timeUnit: '1s',
            duration: __ENV.DURATION || '5m',
            preAllocatedVUs: 20,
        },
        // People opening the sign-in page.
        signInPage: {
            executor: 'constant-arrival-rate',
            exec: 'signInPage',
            rate: Number(__ENV.LOGIN_RPS || 5),
            timeUnit: '1s',
            duration: __ENV.DURATION || '5m',
            preAllocatedVUs: 10,
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{scenario:marketing}': ['p(95)<500'],
        'http_req_duration{scenario:effectiveAccess}': [
            'p(95)<150',
            'p(99)<400',
        ],
        'http_req_duration{scenario:eventTokens}': ['p(95)<300'],
        'http_req_duration{scenario:signInPage}': ['p(95)<500'],
        checks: ['rate>0.99'],
    },
};

// One client-credentials token for the whole run (tokens last 10 minutes,
// so keep DURATION under that or the API calls start failing with 401).
export function setup() {
    const res = http.post(`${AUTH}/oauth/token`, {
        grant_type: 'client_credentials',
        client_id: __ENV.CLIENT_ID,
        client_secret: __ENV.CLIENT_SECRET,
        scope: 'access.read events.issue',
    });

    if (res.status !== 200) {
        fail(`token request failed: ${res.status} ${res.body}`);
    }

    return { token: res.json('access_token') };
}

const pages = ['/', '/pricing', '/products'];

export function marketing() {
    const res = http.get(
        SITE + pages[Math.floor(Math.random() * pages.length)],
        {
            tags: { name: 'marketing' },
        },
    );
    check(res, { 'page 200': (r) => r.status === 200 });
}

export function effectiveAccess(data) {
    const res = http.get(
        `${AUTH}/api/v1/organizations/${__ENV.ORG_ID}/members/${__ENV.MEMBER_SUB}/effective-access`,
        {
            headers: {
                Authorization: `Bearer ${data.token}`,
                Accept: 'application/json',
            },
            tags: { name: 'effective-access' },
        },
    );
    check(res, { 'access 200': (r) => r.status === 200 });
}

export function eventTokens(data) {
    const res = http.post(
        `${AUTH}/api/v1/event-tokens`,
        JSON.stringify({
            organization_id: __ENV.ORG_ID,
            module: __ENV.MODULE || 'finance',
        }),
        {
            headers: {
                Authorization: `Bearer ${data.token}`,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            tags: { name: 'event-token' },
        },
    );
    check(res, { 'event token 201': (r) => r.status === 201 });
}

export function signInPage() {
    const res = http.get(`${AUTH}/login`, { tags: { name: 'sign-in' } });
    check(res, { 'sign-in 200': (r) => r.status === 200 });
}
