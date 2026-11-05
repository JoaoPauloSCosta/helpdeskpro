<?php

use App\Enums\ChatSessionStatus as S;

test('only the approved session transitions are allowed', function (S $from, array $allowed) {
    foreach (S::cases() as $to) {
        expect($from->canTransitionTo($to))->toBe(in_array($to, $allowed, true));
    }
})->with([
    'waiting' => [S::Waiting, [S::Active, S::Cancelled]],
    'active' => [S::Active, [S::Ended]],
    'cancelled' => [S::Cancelled, []],
    'ended' => [S::Ended, []],
]);

test('only waiting and active sessions are open', function () {
    expect(array_filter(S::cases(), fn (S $s) => $s->isOpen()))->toBe([S::Waiting, S::Active]);
});
