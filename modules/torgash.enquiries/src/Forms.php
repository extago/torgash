<?php

namespace Torgash\Enquiries;

use Illuminate\Database\Eloquent\Collection;
use Throwable;
use Torgash\Enquiries\Models\Form;

/**
 * The shop's own forms (PLAN §48), read once per request.
 *
 * A registered singleton, reset with the request (`PerRequest::forget`), never
 * a static: under a persistent worker a static would keep serving a form the
 * shop has since edited. Kinds asks this lazily, so a request that never
 * touches an enquiry never queries the table.
 */
class Forms
{
    /** @var Collection<int, Form>|null */
    protected ?Collection $forms = null;

    /** @return Collection<int, Form> keyed by id */
    public function all(): Collection
    {
        if ($this->forms === null) {
            try {
                $this->forms = Form::query()->orderBy('id')->get()->keyBy('id');
            } catch (Throwable) {
                // Asked while the module is being installed, before its table
                // exists: no forms is the honest answer.
                $this->forms = new Collection;
            }
        }

        return $this->forms;
    }

    /** Forget what was read — a form was saved or deleted in this request. */
    public function flush(): void
    {
        $this->forms = null;
    }

    public function byCode(string $code): ?Form
    {
        return $this->all()->first(fn (Form $form) => $form->code === $code);
    }

    /** @return array<string, array<string, mixed>> kind key => definition */
    public function kinds(): array
    {
        return $this->all()
            ->mapWithKeys(fn (Form $form) => [$form->kind() => $form->asKind()])
            ->all();
    }
}
