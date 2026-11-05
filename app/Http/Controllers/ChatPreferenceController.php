<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Preferência de som do chat (RF-31). Sempre do próprio usuário. */
class ChatPreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $enabled = (bool) $request->validate(
            ['sound_enabled' => ['required', 'boolean']],
            ['sound_enabled.required' => 'Informe se o som deve ficar ligado.', 'sound_enabled.boolean' => 'Informe se o som deve ficar ligado.'],
        )['sound_enabled'];

        $request->user()->forceFill(['chat_sound_enabled' => $enabled])->save();

        return response()->json(['sound_enabled' => $enabled]);
    }
}
