<?php

use App\Enums\TicketStatus;
use App\Enums\TicketTransitionFlow;

test('the transition matrix is exactly the one in RF-10', function () {
    $matrix = collect(TicketStatus::cases())
        ->flatMap(fn (TicketStatus $from) => collect($from->transitions())
            ->map(fn (TicketTransitionFlow $flow, string $to) => "{$from->value} -> {$to}: {$flow->value}")
            ->values())
        ->all();

    expect($matrix)->toEqualCanonicalizing([
        'open -> in_progress: status_change',
        'in_progress -> awaiting_requester: pending',
        'awaiting_requester -> in_progress: status_change',
        'in_progress -> pending: pending',
        'in_progress -> pending_third_party: pending',
        'in_progress -> pending_departments: pending',
        'pending -> in_progress: status_change',
        'pending_third_party -> in_progress: status_change',
        'pending_departments -> in_progress: status_change',
        'in_progress -> resolved: resolution',
        'resolved -> open: reopening',
        'resolved -> closed: closing',
    ]);
});

test('every destination in the matrix is a known status', function () {
    foreach (TicketStatus::cases() as $from) {
        foreach (array_keys($from->transitions()) as $to) {
            expect(TicketStatus::tryFrom($to))->not->toBeNull();
        }
    }
});

test('the status change flow only starts the work and resumes paused tickets', function () {
    $targets = collect(TicketStatus::cases())
        ->mapWithKeys(fn (TicketStatus $from) => [$from->value => array_map(fn (TicketStatus $s) => $s->value, $from->statusChangeTargets())])
        ->all();

    expect($targets)->toBe([
        'open' => ['in_progress'],
        'in_progress' => [],
        'awaiting_requester' => ['in_progress'],
        'pending' => ['in_progress'],
        'pending_third_party' => ['in_progress'],
        'pending_departments' => ['in_progress'],
        'resolved' => [],
        'closed' => [],
    ]);
});
