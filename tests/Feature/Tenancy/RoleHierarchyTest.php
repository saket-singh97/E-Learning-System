<?php

use App\Domains\Tenancy\Services\RoleHierarchy;

it('places parent strictly below student, not tied with it', function () {
    expect(RoleHierarchy::LEVELS['parent'])->toBeGreaterThan(RoleHierarchy::LEVELS['student']);
});

it('does not let student or parent assign any role', function (string $role) {
    expect(RoleHierarchy::canAssign($role, 'student'))->toBeFalse();
})->with(['student', 'parent']);

it('lets an institute admin assign parent, the lowest tier in the hierarchy', function () {
    expect(RoleHierarchy::canAssign('institute_admin', 'parent'))->toBeTrue();
});
