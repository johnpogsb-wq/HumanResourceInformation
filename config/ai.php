<?php

/*
|--------------------------------------------------------------------------
| AI features
|--------------------------------------------------------------------------
| Both AI features are optional. With no API key configured the app runs
| exactly as before — the scan button and the assistant hide themselves
| rather than erroring, so a deployment without a key is a supported state,
| not a broken one.
*/

return [

    'key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

    /*
    | Reading a licence photo is a short, bounded extraction, and the
    | assistant answers one question per turn. Neither needs a large ceiling;
    | this one is sized for the tool-calling round trips the assistant makes.
    */
    'max_tokens' => 4096,

    /*
    | Document types the scanner will attempt. Anything else is uploaded the
    | ordinary way — there is no point sending a résumé to a licence reader.
    */
    'scannable_types' => [
        'drivers_license',
        'medical',
        'clearance',
        'government_id',
        'certificate',
    ],

    /*
    | An extraction below this confidence is shown to HR as a suggestion they
    | must confirm rather than a filled-in field. The model is reading a photo
    | that may be creased, glared, or cropped; saying "not sure" is a correct
    | answer and the UI has to be able to represent it.
    */
    'min_confidence' => 0.7,
];
