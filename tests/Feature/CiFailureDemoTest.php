<?php

/**
 * Deliberately failing test used to verify that the GitHub Actions CI
 * workflow actually surfaces red checks on a commit/branch/PR, instead of
 * only ever having been seen green. Not real coverage — delete this file
 * once the demo run has been confirmed.
 */
test('this test is intentionally broken to verify CI reports failures', function () {
    expect(true)->toBeFalse();
});
