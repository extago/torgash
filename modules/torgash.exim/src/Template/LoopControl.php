<?php

namespace Torgash\Exim\Template;

use RuntimeException;

/** `{% break %}` / `{% continue %}` on their way up to the loop they are in. */
final class LoopControl extends RuntimeException {}
