<?php

namespace Torgash\Exim\Import;

use RuntimeException;

/** Arithmetic met an empty cell: the formula's answer is an empty cell, not 0 (see Formula). */
class EmptyOperand extends RuntimeException {}
