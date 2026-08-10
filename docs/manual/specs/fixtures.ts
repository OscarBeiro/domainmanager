/**
 * docs/manual/specs/fixtures.ts
 *
 * The demo data every spec imports. One place to rename, and — more importantly — every
 * value is a fixed literal. Anything derived from the clock or a random source changes the
 * screenshots on each run, which buries real UI changes in noise.
 *
 * These values must match what the golden DB fixture contains (see fixtures/CHECKLIST.md),
 * and must be safe to publish: no client names, no real hostnames, no live credentials.
 */
export const DEMO = {
  supplier: {
    name: 'Manual Demo Registrar',
    driver: 'Cloudflare',
    // Obviously fake — the manual documents the Test/Import UI as it behaves with an
    // invalid credential, since no real Cloudflare account backs this fixture.
    accountId: 'demo-account-id-not-real',
    token: 'demo-token-not-a-real-credential',
  },
  domain: {
    name: 'manual-example.com',
    // A fixed future date, never "today + 30 days".
    expiry: '2027-06-30',
  },
  dnsRecord: {
    a: { name: '@', type: 'A', value: '203.0.113.10', ttl: '3600' }, // TEST-NET-3, non-routable
    cname: { name: 'www', type: 'CNAME', value: 'manual-example.com', ttl: '3600' },
  },
} as const;
