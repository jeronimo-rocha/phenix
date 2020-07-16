<?php

namespace Phenix\Core\Enumerators;

use Logcomex\PhpUtils\Helpers\EnumHelper;

/**
 * Class ConditionDeterminantTypeEnum
 * @package Phenix\Core\Enumerators
 */
class ConditionDeterminantTypeEnum
{
    use EnumHelper;
    public const MUST = 'must';
    public const MUST_NOT = 'must_not';
    public const SHOULD = 'should';
}
