<?php

namespace Torgash\Catalog\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Catalog\Filters\Generator;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\FilterRule;

/**
 * The rules, applied.
 *
 * A background job and not a button that runs in the request: a rule over a
 * catalogue of any size is one product count per characteristic value per
 * category, which is exactly the shape of work that finishes in four seconds on
 * the demo data and in four minutes on a real shop.
 *
 * `startIfIdle()` is what the nightly schedule calls, so a run that is still
 * going does not get a second copy of itself on top of it.
 */
class GenerateFilterPages extends TrackedJob
{
    public function __construct(public ?int $ruleId = null) {}

    public function label(): string
    {
        return 'Generate filter pages';
    }

    public function module(): ?string
    {
        return 'torgash.catalog';
    }

    protected function run(): void
    {
        $rule = $this->ruleId ? FilterRule::find($this->ruleId) : null;

        if ($this->ruleId && ! $rule) {
            $this->result([], 'The rule was deleted before the run began');

            return;
        }

        $this->total($this->steps($rule));

        $done = 0;
        $counts = (new Generator)->run($rule, function () use (&$done) {
            $this->progress(++$done);
        });

        $this->result(
            $counts,
            ':created new, :refreshed refreshed, :withdrawn out of the sitemap',
            $counts,
        );
    }

    /**
     * One step per category examined — the unit the generator reports in.
     *
     * Counted up front rather than estimated: a progress bar whose total grows
     * while it runs is worse than none, and this is three cheap counts.
     */
    protected function steps(?FilterRule $only): int
    {
        $rules = $only ? collect([$only]) : FilterRule::where('enabled', true)->get();
        $steps = 0;

        foreach ($rules as $rule) {
            $steps += Category::listed()->whereIn('id', $rule->categoryIds())->count();
        }

        return $steps;
    }
}
