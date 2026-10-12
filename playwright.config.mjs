// Browser-level tests (R107). No WordPress or network is needed: the spec serves the selector
// from assets/ and fakes the resolve endpoint with page.route(), so timing is fully controlled.
export default {
  testDir: './tests/e2e',
  testMatch: '**/*.spec.mjs',
  timeout: 30000,
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  use: {
    browserName: 'chromium',
    headless: true,
  },
};
