<?php

/*
|--------------------------------------------------------------------------
| HR assistant
|--------------------------------------------------------------------------
| Optional. With no API key configured the app runs exactly as before — the
| assistant hides itself rather than erroring, so a deployment without a key
| is a supported state, not a broken one.
*/

return [

    'key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

    /*
    | The assistant answers one question per turn, so it needs no large
    | ceiling — this is sized for the tool-calling round trips it makes.
    */
    'max_tokens' => 4096,
];
