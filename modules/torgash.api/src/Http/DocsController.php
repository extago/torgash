<?php

namespace Torgash\Api\Http;

use App\Http\Controllers\Controller;
use Torgash\Api\OpenApi;

/**
 * The API's own description, in the two forms it is wanted in.
 *
 * The document is PUBLIC and the page is not, which is the right way round: a
 * client generating a stub needs the description before it has a token, and
 * there is nothing in it but the shape of routes that check their own
 * permissions. The page is a working tool of the shop's — it carries a token box
 * and fires real requests — so it lives in the admin, beside the screen that
 * issues the tokens.
 */
class DocsController extends Controller
{
    /** The machine-readable half: OpenAPI 3.1, for Postman, codegen and 1C. */
    public function document(OpenApi $openApi)
    {
        return response()->json(
            $openApi->document(),
            200,
            [],
            // Readable when somebody opens it in a browser, which is where an
            // integration problem is usually first looked at.
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** The human half. */
    public function page(OpenApi $openApi)
    {
        $document = $openApi->document();

        return view('torgash.api::admin.docs', [
            'document' => $document,
            // Grouped for the sidebar: "Admin · Products" is a section and a
            // name, and the page reads better split than sorted.
            'groups' => $this->groups($document),
        ]);
    }

    /**
     * Operations gathered under their tag, and tags under their section.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    protected function groups(array $document): array
    {
        $groups = [];

        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $tag = $operation['tags'][0] ?? '—';
                [$section, $name] = array_pad(array_map('trim', explode('·', $tag, 2)), 2, $tag);

                $groups[$section][$name][] = ['path' => $path, 'method' => $method] + $operation;
            }
        }

        return $groups;
    }
}
