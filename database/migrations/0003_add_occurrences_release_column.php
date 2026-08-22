<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/** Attributes an occurrence to the release it arrived in (Releases plugin). */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('occurrences', function (Blueprint $table): void {
            // `release` is a reserved word in MySQL (RELEASE SAVEPOINT) —
            // every column name is quoted by the Grammar, so the bare name
            // here is safe on every engine.
            $table->text('release')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('occurrences', function (Blueprint $table): void {
            $table->dropColumn('release');
        });
    }
};
