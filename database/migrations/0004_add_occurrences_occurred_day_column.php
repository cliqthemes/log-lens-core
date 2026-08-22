<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/** A generated `YYYY-MM-DD` column so date filters and daily trends are index-driven instead of substr()-scanning every occurrence. */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('occurrences', function (Blueprint $table): void {
            $table->generatedSubstring('occurred_day', 'occurred_at', 1, 10);
            $table->index(['occurred_day'], 'idx_occurrences_day');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('occurrences', function (Blueprint $table): void {
            $table->dropIndex('idx_occurrences_day'); // must go before dropping the column it indexes
            $table->dropColumn('occurred_day');
        });
    }
};
