<?php

namespace FpDbTest;

enum Specificators: string
{
    case COMMON_SPEC = '?';
    case INT_SPEC = '?d';
    case FLOAT_SPEC = '?f';
    case ARRAY_SPEC = '?a';
    case IDENTIFIER_SPEC = '?#';
}
