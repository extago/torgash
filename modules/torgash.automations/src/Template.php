<?php

namespace Torgash\Automations;

use App\Core\Content\SafeTemplate;

/**
 * The placeholder resolver for a webhook body and a chat message — core's
 * SafeTemplate, kept under this name because the actions and their tests
 * speak of it.
 */
class Template extends SafeTemplate {}
