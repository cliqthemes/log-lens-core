<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/**
 * Surfaces the nginx access-log fields parsed into `context_preview` as
 * generated columns, so access analytics can be indexed instead of
 * JSON-scanning every occurrence (M-9). The partial indexes below cover only
 * access rows (`access_status IS NOT NULL`), keeping them small — MySQL has
 * no partial index, so it indexes the whole table there instead (see
 * MysqlGrammar); functionally identical, just larger.
 *
 * `context_preview` is free text for most occurrences (stack traces, context
 * lines) and only JSON for nginx access rows — every engine's generated
 * columns here guard against that (SQLite's `json_valid()`, Postgres's
 * `safe_jsonb()`, MySQL's `JSON_VALID()`), yielding NULL rather than an
 * error on write.
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('occurrences', function (Blueprint $table): void {
            $table->generatedJson('access_status', 'context_preview', 'status', 'integer');
            $table->generatedJson('access_path', 'context_preview', 'path', 'text', mysqlVarcharLength: 600);
            $table->generatedJson('access_method', 'context_preview', 'method', 'text', mysqlVarcharLength: 20);
            $table->generatedJson('access_agent', 'context_preview', 'user_agent', 'text');
            $table->index(['occurred_at'], 'idx_occ_access_time')->where('access_status IS NOT NULL')->mysqlPrefix('occurred_at', 19);
            $table->index(['access_path', 'access_status'], 'idx_occ_access_path')->where('access_status IS NOT NULL');
            $table->index(['access_method'], 'idx_occ_access_method')->where('access_status IS NOT NULL');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('occurrences', function (Blueprint $table): void {
            // Indexes first — must go before dropping the columns they reference.
            $table->dropIndex('idx_occ_access_time');
            $table->dropIndex('idx_occ_access_path');
            $table->dropIndex('idx_occ_access_method');
            $table->dropColumn('access_status');
            $table->dropColumn('access_path');
            $table->dropColumn('access_method');
            $table->dropColumn('access_agent');
        });
    }
};
