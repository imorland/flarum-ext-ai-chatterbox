<?php

/*
 * This file is part of ianm/ai-chatterbox.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Stores each AI bot's generated personality as JSON on the user row, so the
 * canonical persona travels with the account and is queryable. Nullable: real
 * users (and not-yet-personalised bots) simply have null here.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'ai_persona')) {
            $schema->table('users', function (Blueprint $table) {
                // No ->after(): keep it portable across forums whose column order
                // differs (e.g. no profile extension providing a `bio` column).
                $table->text('ai_persona')->nullable();
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'ai_persona')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn('ai_persona');
            });
        }
    },
];
