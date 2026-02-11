/**
 * Simple unit test: planet preview URL mapping.
 *
 * Verifies that the planetPreviewUrl logic correctly maps resource_id
 * values 1–7 to valid planet background image URLs.
 *
 * Run with: node tests/js/planet-preview-url.test.js
 */

const BASE_URL = '/images/planet-__resource__-bg.png';

function planetPreviewUrl(resourceId, baseUrl) {
    if (resourceId && baseUrl) {
        return baseUrl.replace('__resource__', resourceId);
    }
    return null;
}

let passed = 0;
let failed = 0;

function assert(condition, message) {
    if (condition) {
        passed++;
    } else {
        failed++;
        console.error('FAIL:', message);
    }
}

// Test: resource IDs 1–7 produce valid URLs
for (let i = 1; i <= 7; i++) {
    const url = planetPreviewUrl(i, BASE_URL);
    assert(url === `/images/planet-${i}-bg.png`, `resource_id ${i} → ${url}`);
}

// Test: null/undefined resource_id returns null
assert(planetPreviewUrl(null, BASE_URL) === null, 'null resource_id → null');
assert(planetPreviewUrl(undefined, BASE_URL) === null, 'undefined resource_id → null');
assert(planetPreviewUrl(0, BASE_URL) === null, '0 resource_id → null');

// Test: missing baseUrl returns null
assert(planetPreviewUrl(1, null) === null, 'null baseUrl → null');
assert(planetPreviewUrl(1, undefined) === null, 'undefined baseUrl → null');

// Test: string resource_id (from API) still works
assert(
    planetPreviewUrl('3', BASE_URL) === '/images/planet-3-bg.png',
    'string resource_id "3" → /images/planet-3-bg.png'
);

console.log(`\nResults: ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
