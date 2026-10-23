<?php

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use App\Support\SlaClock;

test('policyFor returns the active policy of the priority', function () {
    SlaPolicy::factory()->create(['priority' => 'high', 'is_active' => false]);
    $active = SlaPolicy::factory()->create(['priority' => 'high', 'is_active' => true]);
    SlaPolicy::factory()->create(['priority' => 'low', 'is_active' => true]);

    expect(SlaClock::policyFor(TicketPriority::High)?->is($active))->toBeTrue();
});

test('policyFor returns null when the priority has no active policy', function () {
    SlaPolicy::factory()->create(['priority' => 'high', 'is_active' => false]);

    expect(SlaClock::policyFor(TicketPriority::High))->toBeNull()
        ->and(SlaClock::policyFor(TicketPriority::Critical))->toBeNull();
});
