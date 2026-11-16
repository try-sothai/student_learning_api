<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when an uploaded import file is unreadable or structurally invalid. */
class ImportFileException extends RuntimeException
{
}
