<?php

/*
|--------------------------------------------------------------------------
| Profanity filter word list (PRD 4.6)
|--------------------------------------------------------------------------
| Messages are masked (matched words become ***) rather than rejected —
| a filter, not a gate (decision logged in docs/decisions.md). Override
| per environment with PROFANITY_WORDS (comma separated); tests set
| config('profanity.words') directly.
*/

$words = (string) env(
    'PROFANITY_WORDS',
    'shit,fuck,asshole,bastard,bitch,dick,cunt,whore,slut,faggot,nigger,retard',
);

return [
    'words' => array_values(array_filter(array_map('trim', explode(',', $words)))),
];
