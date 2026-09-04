<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts to UPDATE or DELETE a generated_documents
 * row, whether through a model instance or the query builder. Per
 * .ai/14-PDF.md, this table is INSERT-only: a correction over a previously
 * generated document (e.g. a payroll receipt regenerated after a
 * reopen+correct+close cycle) is always a new, higher-`version`
 * generated_documents row, never an edit of an existing one.
 */
class GeneratedDocumentImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'generated_documents es inmutable: no se permite UPDATE ni DELETE. Una regeneración posterior siempre crea una fila nueva con versión incrementada.'
        );
    }
}
