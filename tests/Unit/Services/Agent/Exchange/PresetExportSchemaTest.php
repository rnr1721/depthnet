<?php

namespace Tests\Unit\Services\Agent\Exchange;

use App\Services\Agent\Exchange\PresetExporter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The alarm clock against silent drift.
 *
 * Asserts every column of ai_presets is explicitly classified as either
 * exported or ignored by PresetExporter. When someone adds a column later and
 * forgets the exporter, THIS test goes red in CI — forcing the decision
 * "does this field export or not?" instead of letting the field silently
 * vanish from every future bundle.
 *
 * 15 lines that replace a second reviewer on a solo project.
 *
 * When it fails: add the new column to PresetExporter::EXPORTED_FIELDS (and
 * serialize it) or to PresetExporter::IGNORED_FIELDS (with a reason comment).
 */
class PresetExportSchemaTest extends TestCase
{
    public function test_all_ai_presets_columns_are_explicitly_handled(): void
    {
        $columns = Schema::getColumnListing('ai_presets');

        $handled = array_merge(
            PresetExporter::EXPORTED_FIELDS,
            PresetExporter::IGNORED_FIELDS,
        );

        $unhandled = array_diff($columns, $handled);

        $this->assertEmpty(
            $unhandled,
            "ai_presets columns are neither exported nor ignored — classify them in "
            . "PresetExporter::EXPORTED_FIELDS or ::IGNORED_FIELDS: "
            . implode(', ', $unhandled)
        );
    }

    /**
     * Reverse guard: nothing in the whitelists refers to a column that no
     * longer exists (catches a rename that left a stale entry behind).
     */
    public function test_no_stale_column_references(): void
    {
        $columns = Schema::getColumnListing('ai_presets');

        $referenced = array_merge(
            PresetExporter::EXPORTED_FIELDS,
            PresetExporter::IGNORED_FIELDS,
        );

        $stale = array_diff($referenced, $columns);

        $this->assertEmpty(
            $stale,
            "PresetExporter lists columns that don't exist in ai_presets "
            . "(renamed or removed?): " . implode(', ', $stale)
        );
    }
}
