<?php

namespace App\Enums\Invoice;

enum FileExtension: string
{
    case XML = 'xml';
    case PDF = 'pdf';
    case HTML = 'html';
}
