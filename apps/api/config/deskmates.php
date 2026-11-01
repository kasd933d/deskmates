<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Avatar options
    |--------------------------------------------------------------------------
    |
    | Fixed sets the user may pick from. No uploads in v1 (see PRD 4.1).
    |
    */

    'avatar_emojis' => array_filter(explode(',', env('AVATAR_EMOJIS', '🧑‍💻,📚,🧠,☕,🌙,🔥,🌿,⚡,🦉,🐱,🐶,🌱,💻,📝,🎧,😴'))),

    'avatar_colors' => array_filter(explode(',', env('AVATAR_COLORS', '#ef4444,#f97316,#eab308,#22c55e,#14b8a6,#06b6d4,#3b82f6,#8b5cf6,#ec4899,#64748b'))),

];
